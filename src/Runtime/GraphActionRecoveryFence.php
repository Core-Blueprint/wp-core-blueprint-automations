<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Persistence\PersistenceFailure;
use CB\Automations\Persistence\RunRepository;
use CB\Automations\Persistence\RunStepRepository;
use CB\Automations\Persistence\Schema;
use CB\Automations\Workflow\GraphDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * C6: fail closed on orphaned Advanced Action attempts before opening Vault.
 *
 * A durable Action start does not prove the external side effect was absent.
 * Once its SQL transaction commits, a reclaimed worker must quarantine the
 * run even if the provider was never reached or its acknowledgement was lost.
 * The caller holds the real run-job lease. No recovery route invokes Base.
 */
final class GraphActionRecoveryFence {
	/** A halted status means do not read Vault and do not call a provider. */
	public static function before_vault( int $run_id, RunExecutionLease $lease ): ?RunStatus {
		if ( $run_id < 1 ) {
			throw new \InvalidArgumentException( 'Invalid Advanced recovery identity.' );
		}
		return $lease->transaction( static function () use ( $run_id ): ?RunStatus {
			global $wpdb;
			$runs = Schema::runs_table();
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, definition_version, status FROM {$runs} WHERE id = %d FOR UPDATE", $run_id
			), ARRAY_A );
			if ( ! is_array( $row ) || '' !== (string) $wpdb->last_error
				|| (int) ( $row['id'] ?? 0 ) !== $run_id
				|| GraphDefinition::VERSION !== (int) ( $row['definition_version'] ?? 0 ) ) {
				throw PersistenceFailure::definition();
			}
			$status = RunStatus::tryFrom( (string) ( $row['status'] ?? '' ) );
			if ( null === $status ) {
				throw PersistenceFailure::definition();
			}
			if ( $status->halts_automatic_execution() ) {
				return $status;
			}

			// Lock the most recent unacknowledged Action; no Vault read is allowed
			// before this check. A completed Action attempt has status succeeded.
			$steps = Schema::run_steps_table();
			$attempt = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, run_id, attempt, status FROM {$steps}
				 WHERE run_id = %d AND node_type = %s AND status IN (%s, %s)
				 ORDER BY id DESC LIMIT 1 FOR UPDATE",
				$run_id, NodeType::Action->value,
				StepStatus::Running->value, StepStatus::Indeterminate->value
			), ARRAY_A );
			if ( null === $attempt ) {
				if ( '' !== (string) $wpdb->last_error ) {
					throw PersistenceFailure::database( 'inspect Advanced Action recovery evidence' );
				}
				return null;
			}
			if ( ! is_array( $attempt ) || (int) ( $attempt['id'] ?? 0 ) < 1
				|| (int) ( $attempt['run_id'] ?? 0 ) !== $run_id
				|| (int) ( $attempt['attempt'] ?? 0 ) < 1
				|| ! in_array( $attempt['status'] ?? null, [ StepStatus::Running->value, StepStatus::Indeterminate->value ], true )
				|| '' !== (string) $wpdb->last_error ) {
				throw PersistenceFailure::definition();
			}
			if ( StepStatus::Running->value === $attempt['status'] ) {
				RunStepRepository::indeterminate(
					new RunStepAttempt( (int) $attempt['id'], (int) $attempt['attempt'] ),
					'runtime.action_outcome_unknown'
				);
			}
			// Queued with a started Action is inconsistent: fail closed using an
			// allowed queued->blocked transition. Running can be indeterminate.
			$target = RunStatus::Running === $status ? RunStatus::Indeterminate : RunStatus::Blocked;
			if ( ! RunRepository::transition_status( $run_id, $status, $target, 'runtime.action_outcome_unknown' ) ) {
				throw PersistenceFailure::definition();
			}
			return $target;
		} );
	}
}