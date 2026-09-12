<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Persistence\EventReceiptRepository;
use CB\Automations\Persistence\JobRepository;
use CB\Automations\Persistence\PersistenceFailure;
use CB\Automations\Persistence\RunContextRepository;
use CB\Automations\Persistence\RunRepository;
use CB\Automations\Persistence\WorkflowRecord;
use CB\Automations\Persistence\WorkflowRepository;

defined( 'ABSPATH' ) || exit;

final class EventMaterializer {
	private const PAGE_SIZE = 200;

	public static function materialize( int $receipt_id ): int {
		global $wpdb;
		if ( $receipt_id < 1 ) {
			throw new \InvalidArgumentException( 'Event receipt id must be positive.' );
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw PersistenceFailure::database( 'start automation event materialization transaction' );
		}

		try {
			$receipt = EventReceiptRepository::lock( $receipt_id );
			if ( null === $receipt ) {
				throw PersistenceFailure::definition();
			}
			$status = (string) ( $receipt['status'] ?? '' );
			if ( EventReceiptRepository::is_terminal_status( $status ) ) {
				self::commit();
				return 0;
			}

			$payload = EventReceiptRepository::open_payload( $receipt );
			$provider = (string) $receipt['provider'];
			$trigger_id = (string) $receipt['trigger_id'];
			$schema_version = (string) $receipt['schema_version'];
			$trigger_key = TriggerKey::from_parts( $provider, $trigger_id, $schema_version );
			if ( '' === $trigger_key ) {
				throw PersistenceFailure::definition();
			}

			$correlation_id = wp_generate_uuid4();
			if ( ! is_string( $correlation_id ) || '' === $correlation_id ) {
				throw PersistenceFailure::definition();
			}

			$matched = 0;
			$offset = 0;
			do {
				$workflows = WorkflowRepository::list_enabled_for_trigger( $trigger_key, self::PAGE_SIZE, $offset );
				foreach ( $workflows as $workflow ) {
					if ( ! self::matches_receipt( $workflow, $provider, $trigger_id, $schema_version ) ) {
						continue;
					}

					$trigger = $workflow->definition()->trigger();
					if ( null === $trigger ) {
						throw PersistenceFailure::definition();
					}
					$run_id = RunRepository::create_snapshot( $receipt_id, $workflow, $correlation_id );
					RunContextRepository::put(
						$run_id,
						[
							'event' => [
								'event_id' => (string) $receipt['event_id'],
								'provider' => $provider,
								'trigger_id' => $trigger_id,
								'schema_version' => $schema_version,
								'occurred_at' => (string) $receipt['occurred_at'],
							],
							'outputs' => [
								$trigger->step_id() => $payload,
							],
						]
					);
					JobRepository::enqueue( JobSubjectType::Run, $run_id );
					++$matched;
				}
				$batch_count = count( $workflows );
				$offset += $batch_count;
			} while ( self::PAGE_SIZE === $batch_count );

			EventReceiptRepository::finalize( $receipt_id, $matched > 0 );
			self::commit();
			return $matched;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- transaction boundary owned by Automations worker.
			throw $error;
		}
	}

	private static function matches_receipt(
		WorkflowRecord $workflow,
		string $provider,
		string $trigger_id,
		string $schema_version
	): bool {
		$trigger = $workflow->definition()->trigger();
		if ( null === $trigger ) {
			return false;
		}
		$reference = $trigger->capability();
		return $reference->provider() === $provider
			&& $reference->id() === $trigger_id
			&& $reference->schema_version() === $schema_version;
	}

	private static function commit(): void {
		global $wpdb;
		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- transaction boundary owned by Automations worker.
			throw PersistenceFailure::database( 'commit automation event materialization transaction' );
		}
	}
}
