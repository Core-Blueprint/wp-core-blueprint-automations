<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Discovery\CapabilityDefinition;
use CB\Automations\Discovery\CapabilitySource;
use CB\Automations\Persistence\PersistenceFailure;
use CB\Automations\Persistence\RunContextRepository;
use CB\Automations\Persistence\RunStepRepository;
use CB\Automations\Persistence\Schema;
use CB\Automations\Workflow\GraphDefinition;
use CB\Automations\Workflow\GraphDefinitionCodec;

defined( 'ABSPATH' ) || exit;

/**
 * C4: read one authoritative Advanced intent from a locked immutable v2 run.
 *
 * The snapshot opens RunExecutionLease::transaction(). A State attempt is
 * reserved in that same transaction; Action reservation belongs exclusively
 * to GraphActionStartFence. No HTTP or caller-supplied graph is trusted.
 */
final class GraphInvocationSnapshot {
	/**
	 * @return array{
	 *   workflow_id:int,workflow_revision:int,principal:int,run_uuid:string,
	 *   correlation_id:string,cursor:string,causality:mixed,
	 *   intent:array{node_id:string,kind:string,capability:\CB\Automations\Capability\CapabilityReference,input:array<string,mixed>},
	 *   attempt:?RunStepAttempt
	 * }
	 */
	public static function prepare(
		int $run_id,
		string $expected_cursor,
		RunExecutionLease $lease,
		CapabilitySource $catalog
	): array {
		return $lease->transaction(
			static fn (): array => self::read_locked( $run_id, $expected_cursor, $catalog )
		);
	}

	/** @return array<string,mixed> */
	private static function read_locked( int $run_id, string $expected_cursor, CapabilitySource $catalog ): array {
		global $wpdb;
		if ( $run_id < 1 || 1 !== preg_match( '/^graph:(0|[1-9][0-9]{0,2})$/D', $expected_cursor )
			|| (int) substr( $expected_cursor, 6 ) > 512 ) {
			throw new \InvalidArgumentException( 'Invalid Advanced invocation identity.' );
		}

		$table = Schema::runs_table();
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT id, workflow_id, workflow_revision, execution_principal_user_id,
			        run_uuid, correlation_id, definition_version, definition_json,
			        definition_hash, status, run_cursor
			 FROM {$table} WHERE id = %d FOR UPDATE",
			$run_id
		), ARRAY_A );
		if ( ! is_array( $row ) || '' !== (string) $wpdb->last_error ) {
			throw PersistenceFailure::definition();
		}
		$raw = $row['definition_json'] ?? null;
		$hash = $row['definition_hash'] ?? null;
		$workflow_id = (int) ( $row['workflow_id'] ?? 0 );
		$revision = (int) ( $row['workflow_revision'] ?? 0 );
		$principal = (int) ( $row['execution_principal_user_id'] ?? 0 );
		$run_uuid = $row['run_uuid'] ?? null;
		$correlation_id = $row['correlation_id'] ?? null;
		if ( (int) ( $row['id'] ?? 0 ) !== $run_id
			|| GraphDefinition::VERSION !== (int) ( $row['definition_version'] ?? 0 )
			|| RunStatus::Running->value !== ( $row['status'] ?? null )
			|| $expected_cursor !== ( $row['run_cursor'] ?? null )
			|| $workflow_id < 1 || $revision < 1 || $principal < 1
			|| ! is_string( $raw ) || ! is_string( $hash )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash )
			|| ! hash_equals( $hash, hash( 'sha256', $raw ) )
			|| ! self::uuid( $run_uuid ) || ! self::uuid( $correlation_id ) ) {
			throw PersistenceFailure::definition();
		}
		try {
			$decoded = json_decode( $raw, true, 64, JSON_THROW_ON_ERROR );
			if ( ! is_array( $decoded ) ) {
				throw new \UnexpectedValueException( 'Invalid Advanced graph.' );
			}
			$definition = GraphDefinitionCodec::decode( $decoded );
		} catch ( \Throwable ) {
			throw PersistenceFailure::definition();
		}

		$context = RunContextRepository::get( $run_id );
		if ( ! is_array( $context ) || ! isset( $context['graph_checkpoint'] )
			|| ! is_array( $context['graph_checkpoint'] )
			|| array_keys( $context['graph_checkpoint'] ) !== [ 'journal', 'trigger_outputs' ]
			|| ! is_array( $context['graph_checkpoint']['journal'] )
			|| ! is_array( $context['graph_checkpoint']['trigger_outputs'] ) ) {
			throw new \UnexpectedValueException( 'Advanced invocation requires authenticated checkpoint.' );
		}
		$checkpoint = $context['graph_checkpoint'];
		$journal = GraphCheckpointJournal::from_array( $checkpoint['journal'] );
		if ( $expected_cursor !== 'graph:' . count( $journal->to_array()['entries'] ) ) {
			throw new \UnexpectedValueException( 'Advanced invocation journal and cursor disagree.' );
		}
		$projected = $journal->replay(
			$definition,
			new GraphCheckpointPlanner( $catalog ),
			$checkpoint['trigger_outputs']
		);
		$intent = $projected['intent'];
		if ( null !== $projected['pending_branch'] || 'pending' !== $projected['status']
			|| ! is_array( $intent ) || ! in_array( $intent['kind'] ?? null, [ 'state', 'action' ], true ) ) {
			throw new \UnexpectedValueException( 'No provider node is pending on the acknowledged route.' );
		}
		$reference = $intent['capability'];
		$capability = $catalog->current( $reference );
		$required = $capability instanceof CapabilityDefinition ? $capability->required_capability() : null;
		if ( null === $capability || $capability->reference()->key() !== $reference->key()
			|| ! is_string( $required ) || '' === $required
			|| ! ( get_userdata( $principal ) instanceof \WP_User ) || ! user_can( $principal, $required ) ) {
			throw new \UnexpectedValueException( 'Advanced execution principal or capability is unavailable.' );
		}

		$attempt = null;
		if ( 'state' === $intent['kind'] ) {
			// State is read-only: an interrupted attempt may be retried under
			// the new lease. This must never be applied to an Action.
			RunStepRepository::interrupt_running_attempts( $run_id, NodeType::State, $intent['node_id'] );
			$attempt = RunStepRepository::begin(
				$run_id, NodeType::State, $intent['node_id'],
				$reference->provider(), $reference->id(), $reference->schema_version()
			);
		}

		return [
			'workflow_id' => $workflow_id,
			'workflow_revision' => $revision,
			'principal' => $principal,
			'run_uuid' => $run_uuid,
			'correlation_id' => $correlation_id,
			'cursor' => $expected_cursor,
			'causality' => $context['causality'] ?? null,
			'intent' => $intent,
			'attempt' => $attempt,
		];
	}

	private static function uuid( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match(
			'/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di',
			$value
		);
	}
}