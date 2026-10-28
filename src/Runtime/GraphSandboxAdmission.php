<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Capability\CapabilityReference;
use CB\Automations\Discovery\CapabilityCatalog;
use CB\Automations\Discovery\CapabilityDefinition;
use CB\Automations\Persistence\EventReceiptRepository;
use CB\Automations\Persistence\JobRepository;
use CB\Automations\Persistence\PersistenceFailure;
use CB\Automations\Persistence\RunContextRepository;
use CB\Automations\Persistence\Schema;
use CB\Automations\Support\Requirements;
use CB\Automations\Validation\GraphValidator;
use CB\Automations\Workflow\GraphDefinition;
use CB\Automations\Workflow\GraphDefinitionCodec;

defined( 'ABSPATH' ) || exit;

/**
 * C5 CLI-only test admission, not a public workflow activation mechanism.
 * Creates a synthetic terminal receipt, immutable v2 run, authenticated
 * trigger context and durable run job in a single DB transaction.
 */
final class GraphSandboxAdmission {
	/**
	 * @param array<string,mixed> $trigger_payload A deliberate operator fixture.
	 * @return int Durable run id. The same admission UUID is idempotent.
	 */
	public static function enqueue( int $workflow_id, string $admission_uuid, array $trigger_payload, int $principal_id ): int {
		GraphRuntimeGate::require_cli();
		if ( ! Requirements::execution_ready() || $workflow_id < 1 || $principal_id < 1 || ! self::uuid( $admission_uuid ) ) {
			throw new \InvalidArgumentException( 'Invalid Advanced sandbox admission or unavailable runtime.' );
		}
		try {
			$json = json_encode( $trigger_payload, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			throw new \UnexpectedValueException( 'Invalid Advanced trigger fixture.' );
		}
		if ( strlen( $json ) > 262144 || array_is_list( $trigger_payload ) && [] !== $trigger_payload ) {
			throw new \UnexpectedValueException( 'Advanced trigger fixture exceeds the object and size contract.' );
		}
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw PersistenceFailure::database( 'begin Advanced sandbox admission' );
		}
		try {
			$table = Schema::workflows_table();
			$workflow = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, revision, definition_version, definition_json, activation_state, execution_principal_user_id,
				 trigger_key FROM {$table} WHERE id = %d FOR UPDATE", $workflow_id
			), ARRAY_A );
			if ( ! is_array( $workflow ) || '' !== (string) $wpdb->last_error
				|| (int) ( $workflow['id'] ?? 0 ) !== $workflow_id
				|| (int) ( $workflow['revision'] ?? 0 ) < 1
				|| (int) ( $workflow['definition_version'] ?? 0 ) !== GraphDefinition::VERSION
				|| ( $workflow['activation_state'] ?? null ) !== 'disabled'
				|| ( $workflow['trigger_key'] ?? null ) !== '' ) {
				throw PersistenceFailure::definition();
			}
			$stored_principal = (int) ( $workflow['execution_principal_user_id'] ?? 0 );
			$principal = $principal_id;
			// Disabled Advanced drafts may not yet have a configured principal.
			// The sandbox actor must name the execution principal explicitly;
			// if the draft already has one, it may not be overridden.
			if ( ( $stored_principal > 0 && $stored_principal !== $principal )
				|| ! ( get_userdata( $principal ) instanceof \WP_User ) ) {
				throw new \UnexpectedValueException( 'Advanced sandbox principal is missing or conflicts with the draft.' );
			}
			$raw = (string) $workflow['definition_json'];
			try {
				$data = json_decode( $raw, true, 64, JSON_THROW_ON_ERROR );
				$definition = GraphDefinitionCodec::decode( $data );
			} catch ( \Throwable ) {
				throw PersistenceFailure::definition();
			}
			$catalog = new CapabilityCatalog();
			if ( ! ( new GraphValidator( $catalog ) )->validate( $definition )->is_valid() ) {
				throw new \UnexpectedValueException( 'Advanced sandbox graph has failed validation.' );
			}
			$trigger_id = null;
			$trigger_ref = null;
			foreach ( $definition->graph()['nodes'] as $node ) {
				if ( 'trigger' === $node['type'] ) {
					$trigger_id = $node['id'];
					$spec = $node['config']['capability'];
					$trigger_ref = CapabilityReference::from_values( $spec['kind'], $spec['provider'], $spec['id'], $spec['schema_version'] );
				}
				if ( in_array( $node['type'], [ 'state', 'action' ], true ) ) {
					$spec = $node['config']['capability'];
					$ref = CapabilityReference::from_values( $spec['kind'], $spec['provider'], $spec['id'], $spec['schema_version'] );
					$cap = null === $ref ? null : $catalog->current( $ref );
					$required = $cap instanceof CapabilityDefinition ? $cap->required_capability() : null;
					if ( null === $ref || ! $cap instanceof CapabilityDefinition
						|| $cap->reference()->key() !== $ref->key()
						|| ! is_string( $required ) || '' === $required || ! user_can( $principal, $required ) ) {
						throw new \UnexpectedValueException( 'Advanced sandbox principal lacks provider authorization.' );
					}
				}
			}
			$trigger = null === $trigger_ref ? null : $catalog->current( $trigger_ref );
			if ( ! is_string( $trigger_id ) || ! $trigger instanceof CapabilityDefinition
				|| $trigger->reference()->key() !== $trigger_ref->key() ) {
				throw PersistenceFailure::definition();
			}
			self::validate_payload( $trigger_payload, $trigger->output_schema() );
			// A stable operator UUID, scoped to this workflow, prevents a second
			// run if the CLI acknowledgement was lost after SQL COMMIT.
			$event_id = 'adv-sandbox:' . $workflow_id . ':' . strtolower( $admission_uuid );
			$fingerprint = EventReceiptRepository::fingerprint( $trigger_ref->provider(), $trigger_ref->id(), $event_id );
			$receipts = Schema::event_receipts_table();
			$now = current_time( 'mysql', true );
			$inserted = $wpdb->query( $wpdb->prepare(
				"INSERT INTO {$receipts}
				 (event_fingerprint, event_id, provider, trigger_id, schema_version, occurred_at,
				 payload_envelope, status, created_at, materialized_at, purged_at)
				 VALUES (%s, %s, %s, %s, %s, %s, '', 'materialized', %s, %s, %s)
				 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
				$fingerprint, $event_id, $trigger_ref->provider(), $trigger_ref->id(),
				$trigger_ref->schema_version(), $now, $now, $now, $now
			) );
			if ( false === $inserted || (int) $wpdb->insert_id < 1 ) {
				throw PersistenceFailure::database( 'persist sandbox admission receipt' );
			}
			$receipt_id = (int) $wpdb->insert_id;
			$runs = Schema::runs_table();
			$existing = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, definition_hash, workflow_revision, execution_principal_user_id FROM {$runs}
				 WHERE event_receipt_id = %d AND workflow_id = %d FOR UPDATE", $receipt_id, $workflow_id
			), ARRAY_A );
			$hash = hash( 'sha256', $raw );
			if ( is_array( $existing ) ) {
				if ( (int) ( $existing['id'] ?? 0 ) < 1
					|| (int) ( $existing['workflow_revision'] ?? 0 ) !== (int) $workflow['revision']
					|| (int) ( $existing['execution_principal_user_id'] ?? 0 ) !== $principal
					|| ! hash_equals( $hash, (string) ( $existing['definition_hash'] ?? '' ) ) ) {
					throw new \UnexpectedValueException( 'Sandbox admission key belongs to a different workflow revision.' );
				}
				$run_id = (int) $existing['id'];
			} else {
				if ( '' !== (string) $wpdb->last_error ) {
					throw PersistenceFailure::database( 'inspect sandbox run idempotency' );
				}
				$run_uuid = wp_generate_uuid4();
				$correlation = wp_generate_uuid4();
				if ( ! self::uuid( $run_uuid ) || ! self::uuid( $correlation ) ) {
					throw PersistenceFailure::definition();
				}
				$inserted = $wpdb->query( $wpdb->prepare(
					"INSERT INTO {$runs}
					 (run_uuid, correlation_id, event_receipt_id, workflow_id, workflow_revision,
					 execution_principal_user_id, definition_version, definition_json, definition_hash,
					 status, run_cursor, failure_code, created_at, started_at, finished_at, updated_at)
					 VALUES (%s, %s, %d, %d, %d, %d, %d, %s, %s, %s, %s, '', %s, NULL, NULL, %s)",
					$run_uuid, $correlation, $receipt_id, $workflow_id, (int) $workflow['revision'],
					$principal, GraphDefinition::VERSION, $raw, $hash, RunStatus::Queued->value,
					'graph:0', $now, $now
				) );
				if ( false === $inserted || (int) $wpdb->insert_id < 1 ) {
					throw PersistenceFailure::database( 'persist Advanced immutable run' );
				}
				$run_id = (int) $wpdb->insert_id;
				RunContextRepository::put( $run_id, [
					'advanced_admission' => 'cli_sandbox_v1',
					'causality' => null,
					'graph_checkpoint' => [
						'journal' => GraphCheckpointJournal::empty()->to_array(),
						'trigger_outputs' => [ $trigger_id => $trigger_payload ],
					],
				] );
				JobRepository::enqueue( JobSubjectType::Run, $run_id );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw PersistenceFailure::database( 'commit Advanced sandbox admission' );
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' );
			throw $e;
		}
		// Scheduling is best effort. The durable job is already committed.
		try { WorkerWakeup::request_if_pending(); } catch ( \Throwable ) {}
		return $run_id;
	}

	/** @param array<string,mixed> $payload @param array<string,array<string,mixed>> $schema */
	private static function validate_payload( array $payload, array $schema ): void {
		foreach ( $schema as $name => $field ) {
			if ( ! is_string( $name ) || ! is_array( $field )
				|| ( ! empty( $field['required'] ) && ! array_key_exists( $name, $payload ) ) ) {
				throw new \UnexpectedValueException( 'Sandbox trigger payload is missing a required field.' );
			}
		}
		foreach ( $payload as $name => $value ) {
			$field = $schema[ $name ] ?? null;
			if ( ! is_string( $name ) || ! is_array( $field ) || ! self::matches( $value, $field ) ) {
				throw new \UnexpectedValueException( 'Sandbox trigger payload does not match registered schema.' );
			}
		}
	}

	/** @param array<string,mixed> $field */
	private static function matches( mixed $value, array $field ): bool {
		return match ( $field['type'] ?? null ) {
			'string' => is_string( $value ),
			'integer' => is_int( $value ),
			'number' => is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ),
			'boolean' => is_bool( $value ),
			'array' => is_array( $value ) && array_is_list( $value )
				&& is_string( $field['items'] ?? null )
				&& array_reduce( $value, static fn ( bool $ok, mixed $item ): bool =>
					$ok && self::matches( $item, [ 'type' => $field['items'] ] ), true ),
			default => false,
		};
	}

	private static function uuid( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match(
			'/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di', $value
		);
	}
}