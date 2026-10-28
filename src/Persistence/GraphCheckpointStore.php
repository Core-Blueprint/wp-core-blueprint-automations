<?php
declare(strict_types=1);

namespace CB\Automations\Persistence;

use CB\Automations\Discovery\CapabilitySource;
use CB\Automations\Runtime\GraphCheckpointJournal;
use CB\Automations\Runtime\GraphCheckpointPlanner;
use CB\Automations\Runtime\NodeType;
use CB\Automations\Runtime\RunExecutionLease;
use CB\Automations\Runtime\RunStatus;
use CB\Automations\Runtime\RunStepAttempt;
use CB\Automations\Workflow\GraphDefinition;
use CB\Automations\Workflow\GraphDefinitionCodec;

defined( 'ABSPATH' ) || exit;

/**
 * ADV-02C C2: isolated v2-only lease-fenced checkpoint writer.
 *
 * Neither this class nor its parent C1 journal starts a run or invokes a
 * provider. The existing Standard v1 snapshot writer, cursor and worker are
 * deliberately unchanged. A later authorized executor must prove invocation
 * provenance, output schema and crash/replay policy before calling completion.
 */
final class GraphCheckpointStore {
	private const CURSOR = '/^graph:(0|[1-9][0-9]{0,2})$/D';

	/** Persist exactly one deterministic IF/ELSE decision. */
	public static function commit_branch(
		int $run_id,
		RunExecutionLease $lease,
		CapabilitySource $catalog,
		string $expected_cursor
	): string {
		return self::commit( $run_id, $lease, $catalog, $expected_cursor, 'branch', null, '', '', [] );
	}

	/**
	 * Persist one *already invoked* State/Action result and mark its matching
	 * running attempt successful. The caller must be a future governed executor,
	 * never a browser, REST endpoint or arbitrary plugin hook.
	 *
	 * @param array<string,mixed> $output Output must be verified by C3 before call.
	 */
	public static function commit_completion(
		int $run_id,
		RunExecutionLease $lease,
		CapabilitySource $catalog,
		string $expected_cursor,
		RunStepAttempt $attempt,
		string $node_id,
		string $kind,
		array $output
	): string {
		if ( ! in_array( $kind, [ 'state', 'action' ], true )
			|| 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $node_id ) ) {
			throw new \InvalidArgumentException( 'Invalid Advanced completion target.' );
		}
		return self::commit( $run_id, $lease, $catalog, $expected_cursor, 'completion', $attempt, $node_id, $kind, $output );
	}

	/**
	 * No transaction callback or new journal object is accepted from callers.
	 * The only authority is the currently locked v2 run snapshot + encrypted
	 * previously acknowledged journal. The lease locks the job row first.
	 *
	 * @param array<string,mixed> $output
	 */
	private static function commit(
		int $run_id,
		RunExecutionLease $lease,
		CapabilitySource $catalog,
		string $expected_cursor,
		string $operation,
		?RunStepAttempt $attempt,
		string $node_id,
		string $kind,
		array $output
	): string {
		if ( $run_id < 1 || ! self::cursor_valid( $expected_cursor ) ) {
			throw new \InvalidArgumentException( 'Invalid Advanced checkpoint identity or cursor.' );
		}
		return $lease->transaction( static function () use (
			$run_id, $catalog, $expected_cursor, $operation, $attempt, $node_id, $kind, $output
		): string {
			$locked = self::lock_snapshot( $run_id );
			if ( $locked['cursor'] !== $expected_cursor ) {
				throw new \UnexpectedValueException( 'Advanced checkpoint cursor is stale.' );
			}

			$context = RunContextRepository::get( $run_id );
			if ( ! is_array( $context ) || ! isset( $context['graph_checkpoint'] )
				|| ! is_array( $context['graph_checkpoint'] )
				|| array_keys( $context['graph_checkpoint'] ) !== [ 'journal', 'trigger_outputs' ]
				|| ! is_array( $context['graph_checkpoint']['journal'] )
				|| ! is_array( $context['graph_checkpoint']['trigger_outputs'] ) ) {
				throw new \UnexpectedValueException( 'Missing or malformed encrypted Advanced checkpoint.' );
			}

			$journal = GraphCheckpointJournal::from_array( $context['graph_checkpoint']['journal'] );
			$prior = $journal->to_array()['entries'];
			if ( $expected_cursor !== self::cursor( count( $prior ) ) ) {
				throw new \UnexpectedValueException( 'Advanced cursor and encrypted journal disagree.' );
			}

			$definition = $locked['definition'];
			$planner = new GraphCheckpointPlanner( $catalog );
			$trigger_outputs = $context['graph_checkpoint']['trigger_outputs'];
			// Every read must replay the complete prior evidence against the
			// immutable definition and current capability catalog.
			$projection = $journal->replay( $definition, $planner, $trigger_outputs );

			if ( 'branch' === $operation ) {
				if ( null === $projection['pending_branch'] ) {
					throw new \UnexpectedValueException( 'No Advanced branch awaits acknowledgement.' );
				}
				$branch_id = $projection['pending_branch']['node_id'];
				$next = $journal->record_next_branch( $definition, $planner, $trigger_outputs );
				// A Condition is a local deterministic step, never a provider
				// invocation. Its attempt and journal are one transaction.
				$branch_attempt = RunStepRepository::begin( $run_id, NodeType::Condition, $branch_id );
				RunStepRepository::succeed( $branch_attempt );
			} elseif ( 'completion' === $operation && null !== $attempt ) {
				if ( null !== $projection['pending_branch']
					|| 'pending' !== $projection['status']
					|| null === $projection['intent']
					|| $projection['intent']['node_id'] !== $node_id
					|| $projection['intent']['kind'] !== $kind ) {
					throw new \UnexpectedValueException( 'Advanced completion does not match the pending node.' );
				}
				self::lock_matching_attempt( $run_id, $attempt, $projection['intent'], $node_id, $kind );
				$next = $journal->record_completion( $definition, $planner, $node_id, $kind, $output, $trigger_outputs );
				RunStepRepository::succeed( $attempt );
			} else {
				throw new \InvalidArgumentException( 'Unsupported Advanced checkpoint operation.' );
			}

			$next_cursor = self::cursor( count( $next->to_array()['entries'] ) );
			$context['graph_checkpoint']['journal'] = $next->to_array();
			// The Vault supplies authenticated encryption and per-run AAD.
			// No plaintext outputs, binding values or tokens enter run rows.
			RunContextRepository::put( $run_id, $context );
			self::advance( $run_id, $expected_cursor, $next_cursor, $locked['hash'] );
			return $next_cursor;
		} );
	}

	/**
	 * @return array{definition:GraphDefinition,hash:string,cursor:string}
	 */
	private static function lock_snapshot( int $run_id ): array {
		global $wpdb;
		$table = Schema::runs_table();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, definition_version, definition_json, definition_hash, status, run_cursor,
			        workflow_id, workflow_revision, execution_principal_user_id
			 FROM {$table} WHERE id = %d FOR UPDATE",
			$run_id
		), ARRAY_A );
		if ( ! is_array( $row ) || '' !== (string) $wpdb->last_error ) {
			throw PersistenceFailure::definition();
		}
		$json = $row['definition_json'] ?? null;
		$hash = $row['definition_hash'] ?? null;
		$cursor = $row['run_cursor'] ?? null;
		if ( (int) ( $row['id'] ?? 0 ) !== $run_id
			|| GraphDefinition::VERSION !== (int) ( $row['definition_version'] ?? 0 )
			|| RunStatus::Running->value !== ( $row['status'] ?? null )
			|| (int) ( $row['workflow_id'] ?? 0 ) < 1
			|| (int) ( $row['workflow_revision'] ?? 0 ) < 1
			|| (int) ( $row['execution_principal_user_id'] ?? 0 ) < 1
			|| ! is_string( $json ) || ! is_string( $hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash )
			|| ! hash_equals( $hash, hash( 'sha256', $json ) )
			|| ! is_string( $cursor ) || ! self::cursor_valid( $cursor ) ) {
			throw PersistenceFailure::definition();
		}
		try {
			$decoded = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
			if ( ! is_array( $decoded ) ) {
				throw new \UnexpectedValueException( 'Invalid Advanced run definition.' );
			}
			$definition = GraphDefinitionCodec::decode( $decoded );
		} catch ( \Throwable ) {
			throw PersistenceFailure::definition();
		}
		return [ 'definition' => $definition, 'hash' => $hash, 'cursor' => $cursor ];
	}

	/** @param array<string,mixed> $intent */
	private static function lock_matching_attempt(
		int $run_id, RunStepAttempt $attempt, array $intent, string $node_id, string $kind
	): void {
		global $wpdb;
		$table = Schema::run_steps_table();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, run_id, node_id, node_type, attempt, status,
			        provider, capability_id, schema_version
			 FROM {$table} WHERE id = %d FOR UPDATE",
			$attempt->id()
		), ARRAY_A );
		$reference = $intent['capability'];
		if ( ! is_array( $row ) || '' !== (string) $wpdb->last_error
			|| (int) ( $row['id'] ?? 0 ) !== $attempt->id()
			|| (int) ( $row['run_id'] ?? 0 ) !== $run_id
			|| (int) ( $row['attempt'] ?? 0 ) !== $attempt->attempt()
			|| ( $row['node_id'] ?? null ) !== $node_id
			|| ( $row['node_type'] ?? null ) !== $kind
			|| ( $row['status'] ?? null ) !== 'running'
			|| ( $row['provider'] ?? null ) !== $reference->provider()
			|| ( $row['capability_id'] ?? null ) !== $reference->id()
			|| ( $row['schema_version'] ?? null ) !== $reference->schema_version() ) {
			throw new \UnexpectedValueException( 'Advanced step attempt is not the currently running capability.' );
		}
	}

	private static function advance(
		int $run_id, string $expected_cursor, string $next_cursor, string $definition_hash
	): void {
		global $wpdb;
		$table = Schema::runs_table();
		$changed = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET run_cursor = %s, updated_at = %s
			 WHERE id = %d AND run_cursor = %s AND definition_version = %d
			   AND definition_hash = %s AND status = %s",
			$next_cursor, current_time( 'mysql', true ), $run_id, $expected_cursor,
			GraphDefinition::VERSION, $definition_hash, RunStatus::Running->value
		) );
		if ( 1 !== $changed ) {
			// Any SQL error, stale cursor or uncertain write is a rollback.
			// Do NOT retry an Action completion automatically.
			throw new \RuntimeException( 'Advanced checkpoint cursor update was not exclusively confirmed.' );
		}
	}

	private static function cursor_valid( string $value ): bool {
		return 1 === preg_match( self::CURSOR, $value ) && (int) substr( $value, 6 ) <= 512;
	}

	private static function cursor( int $count ): string {
		if ( $count < 0 || $count > 512 ) {
			throw new \UnexpectedValueException( 'Advanced checkpoint exceeds cursor bound.' );
		}
		return 'graph:' . $count;
	}
}
