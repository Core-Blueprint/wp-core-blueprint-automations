<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Discovery\CapabilitySource;
use CB\Automations\Persistence\PersistenceFailure;
use CB\Automations\Persistence\RunContextRepository;
use CB\Automations\Persistence\RunRepository;
use CB\Automations\Persistence\RunStepRepository;
use CB\Automations\Persistence\Schema;
use CB\Automations\Workflow\GraphDefinition;
use CB\Automations\Workflow\GraphDefinitionCodec;

defined( 'ABSPATH' ) || exit;

/**
 * ADV-02C C3: pre-invocation Action fence, not an Action dispatcher.
 *
 * Inside one existing run-job lease transaction this validates the immutable
 * v2 snapshot, checks prior Action attempts BEFORE opening Vault, and begins
 * the one matching Action attempt only if the encrypted journal proves the
 * pending node. A prior attempt is NEVER replayed automatically, even if it
 * might have died before the actual provider call.
 */
final class GraphActionStartFence {
	/**
	 * @return RunStepAttempt|null A null result is QUARANTINED, never invoke.
	 */
	public static function begin(
		int $run_id,
		string $node_id,
		string $expected_cursor,
		RunExecutionLease $lease,
		CapabilitySource $catalog
	): ?RunStepAttempt {
		if ( $run_id < 1
			|| 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $node_id )
			|| 1 !== preg_match( '/^graph:(0|[1-9][0-9]{0,2})$/D', $expected_cursor )
			|| (int) substr( $expected_cursor, 6 ) > 512 ) {
			throw new \InvalidArgumentException( 'Invalid Advanced Action start identity.' );
		}

		return $lease->transaction( static function () use (
			$run_id, $node_id, $expected_cursor, $catalog
		): ?RunStepAttempt {
			$definition = self::lock_definition( $run_id, $expected_cursor );
			$node = null;
			foreach ( $definition->graph()['nodes'] as $candidate ) {
				if ( $candidate['id'] === $node_id ) {
					$node = $candidate;
					break;
				}
			}
			if ( null === $node || 'action' !== $node['type'] ) {
				throw new \UnexpectedValueException( 'Advanced Action start does not match its graph.' );
			}

			// This must precede any Vault read or output resolution. A process
			// may have crashed after entering an external provider but before
			// committing the acknowledgement.
			$previous = self::last_attempt( $run_id, $node_id );
			if ( null !== $previous ) {
				if ( StepStatus::Running->value === $previous['status'] ) {
					RunStepRepository::indeterminate(
						new RunStepAttempt( $previous['id'], $previous['attempt'] ),
						'runtime.action_outcome_unknown'
					);
				}
				if ( ! RunRepository::transition_status(
					$run_id, RunStatus::Running, RunStatus::Indeterminate,
					'runtime.action_outcome_unknown'
				) ) {
					throw new \RuntimeException( 'Advanced Action uncertainty could not be quarantined.' );
				}
				return null;
			}

			$context = RunContextRepository::get( $run_id );
			if ( ! is_array( $context ) || ! isset( $context['graph_checkpoint'] )
				|| ! is_array( $context['graph_checkpoint'] )
				|| array_keys( $context['graph_checkpoint'] ) !== [ 'journal', 'trigger_outputs' ]
				|| ! is_array( $context['graph_checkpoint']['journal'] )
				|| ! is_array( $context['graph_checkpoint']['trigger_outputs'] ) ) {
				throw new \UnexpectedValueException( 'Advanced Action start needs authenticated context.' );
			}
			$journal = GraphCheckpointJournal::from_array( $context['graph_checkpoint']['journal'] );
			if ( $expected_cursor !== 'graph:' . count( $journal->to_array()['entries'] ) ) {
				throw new \UnexpectedValueException( 'Advanced Action cursor and journal are inconsistent.' );
			}
			$projection = $journal->replay(
				$definition, new GraphCheckpointPlanner( $catalog ),
				$context['graph_checkpoint']['trigger_outputs']
			);
			if ( null !== $projection['pending_branch']
				|| 'pending' !== $projection['status']
				|| null === $projection['intent']
				|| 'action' !== $projection['intent']['kind']
				|| $node_id !== $projection['intent']['node_id'] ) {
				throw new \UnexpectedValueException( 'Advanced Action is not the next acknowledged route intent.' );
			}
			$ref = $projection['intent']['capability'];
			return RunStepRepository::begin(
				$run_id, NodeType::Action, $node_id,
				$ref->provider(), $ref->id(), $ref->schema_version()
			);
		} );
	}

	private static function lock_definition( int $run_id, string $cursor ): GraphDefinition {
		global $wpdb;
		$table = Schema::runs_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, workflow_id, workflow_revision, execution_principal_user_id,
				        definition_version, definition_json, definition_hash, status, run_cursor
				 FROM {$table} WHERE id = %d FOR UPDATE",
				$run_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || '' !== (string) $wpdb->last_error ) {
			throw PersistenceFailure::definition();
		}
		$json = $row['definition_json'] ?? null;
		$hash = $row['definition_hash'] ?? null;
		if ( (int) ( $row['id'] ?? 0 ) !== $run_id
			|| GraphDefinition::VERSION !== (int) ( $row['definition_version'] ?? 0 )
			|| RunStatus::Running->value !== ( $row['status'] ?? null )
			|| $cursor !== ( $row['run_cursor'] ?? null )
			|| (int) ( $row['workflow_id'] ?? 0 ) < 1
			|| (int) ( $row['workflow_revision'] ?? 0 ) < 1
			|| (int) ( $row['execution_principal_user_id'] ?? 0 ) < 1
			|| ! is_string( $json ) || ! is_string( $hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash )
			|| ! hash_equals( $hash, hash( 'sha256', $json ) ) ) {
			throw PersistenceFailure::definition();
		}
		try {
			$data = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
			if ( ! is_array( $data ) ) {
				throw new \UnexpectedValueException( 'Invalid graph definition.' );
			}
			return GraphDefinitionCodec::decode( $data );
		} catch ( \Throwable ) {
			throw PersistenceFailure::definition();
		}
	}

	/** @return array{id:int,attempt:int,status:string}|null */
	private static function last_attempt( int $run_id, string $node_id ): ?array {
		global $wpdb;
		$table = Schema::run_steps_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, run_id, attempt, status FROM {$table}
				 WHERE run_id = %d AND node_type = %s AND node_id = %s
				 ORDER BY attempt DESC LIMIT 1 FOR UPDATE",
				$run_id, NodeType::Action->value, $node_id
			),
			ARRAY_A
		);
		if ( null === $row ) {
			if ( '' !== (string) $wpdb->last_error ) {
				throw PersistenceFailure::database( 'inspect Advanced Action attempt history' );
			}
			return null;
		}
		$status = $row['status'] ?? null;
		$id = (int) ( $row['id'] ?? 0 );
		$attempt = (int) ( $row['attempt'] ?? 0 );
		if ( ! is_array( $row ) || $id < 1 || $attempt < 1
			|| (int) ( $row['run_id'] ?? 0 ) !== $run_id
			|| ! is_string( $status ) || null === StepStatus::tryFrom( $status ) ) {
			throw PersistenceFailure::definition();
		}
		return [ 'id' => $id, 'attempt' => $attempt, 'status' => $status ];
	}
}
