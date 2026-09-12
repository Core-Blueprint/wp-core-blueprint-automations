<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Persistence\EventReceiptRepository;
use CB\Automations\Persistence\JobRepository;
use CB\Automations\Persistence\RunRepository;
use CB\Automations\Persistence\RunStepRepository;
use CB\Automations\Support\Requirements;

defined( 'ABSPATH' ) || exit;

final class RuntimeWorker {
	private const MAX_JOBS_PER_WAKEUP = 25;
	private const MAX_EVENT_ATTEMPTS = 5;
	private const MAX_RUN_ATTEMPTS = 5;
	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( WorkerWakeup::HOOK, [ self::class, 'run' ] );
	}

	public static function run(): void {
		if ( ! Requirements::execution_ready() ) {
			return;
		}

		try {
			TriggerIntake::repair_missing_event_jobs();
			for ( $processed = 0; $processed < self::MAX_JOBS_PER_WAKEUP; $processed++ ) {
				$job = JobRepository::claim( 120 );
				if ( null === $job ) {
					break;
				}
				try {
					if ( JobSubjectType::Event === $job->subject_type() ) {
						EventMaterializer::materialize( $job->subject_id() );
						JobRepository::complete( $job->id(), $job->lease_token() );
						continue;
					}
					if ( JobSubjectType::Run === $job->subject_type() ) {
						RunPipeline::execute( $job->subject_id(), $job );
						JobRepository::complete( $job->id(), $job->lease_token() );
						continue;
					}
					JobRepository::fail( $job->id(), $job->lease_token(), 'runtime.job_subject_unsupported' );
				} catch ( VaultFailure ) {
					if ( JobSubjectType::Run === $job->subject_type() ) {
						self::handle_run_vault_failure( $job );
					} else {
						self::handle_event_vault_failure( $job );
					}
				} catch ( \Throwable ) {
					if ( JobSubjectType::Run === $job->subject_type() ) {
						self::handle_run_failure( $job );
					} else {
						self::handle_event_failure( $job );
					}
				}
			}
		} catch ( \Throwable ) {
			/* Durable jobs remain recoverable on a later worker wake-up. */
		} finally {
			try {
				WorkerWakeup::request_if_pending();
			} catch ( \Throwable ) {
				/* A later request will re-evaluate pending jobs. */
			}
		}
	}

	private static function handle_event_vault_failure( JobRecord $job ): void {
		try {
			EventReceiptRepository::mark_failed( $job->subject_id() );
			JobRepository::fail( $job->id(), $job->lease_token(), 'runtime.context_unavailable' );
		} catch ( \Throwable ) {
			/* Stale event leases are left for their current owner/reclaimer. */
		}
	}

	private static function handle_run_vault_failure( JobRecord $job ): void {
		try {
			$lease = RunExecutionLease::from_job( $job, $job->subject_id() );
			$lease->renew();
			$lease->transaction( static function () use ( $job ): void {
				$run = RunRepository::find( $job->subject_id() );
				if ( null === $run ) {
					JobRepository::fail( $job->id(), $job->lease_token(), 'runtime.context_unavailable' );
					return;
				}
				if ( $run->status()->halts_automatic_execution() ) {
					JobRepository::complete( $job->id(), $job->lease_token() );
					return;
				}

				$running_action = self::running_action_attempt( $run );
				if ( null !== $running_action ) {
					RunStepRepository::indeterminate( $running_action, 'runtime.action_outcome_unknown' );
					if ( ! RunRepository::transition_status( $run->id(), $run->status(), RunStatus::Indeterminate, 'runtime.action_outcome_unknown' ) ) {
						throw new \RuntimeException( 'Automation run could not enter indeterminate state after Vault loss.' );
					}
					if ( ! JobRepository::complete( $job->id(), $job->lease_token() ) ) {
						throw new \RuntimeException( 'Indeterminate automation job could not be closed.' );
					}
					return;
				}

				if ( ! RunRepository::transition_status( $run->id(), $run->status(), RunStatus::Blocked, 'runtime.context_unavailable' ) ) {
					throw new \RuntimeException( 'Automation run could not block after Vault loss.' );
				}
				if ( ! JobRepository::fail( $job->id(), $job->lease_token(), 'runtime.context_unavailable' ) ) {
					throw new \RuntimeException( 'Automation run job could not fail after Vault loss.' );
				}
			} );
		} catch ( \Throwable ) {
			/* Lost leases are never allowed to overwrite the current owner. */
		}
	}

	private static function running_action_attempt( RunRecord $run ): ?RunStepAttempt {
		try {
			$index = RunCursor::action_index( $run->cursor(), count( $run->definition()->actions() ) );
		} catch ( \UnexpectedValueException ) {
			return null;
		}
		$actions = $run->definition()->actions();
		if ( null === $index || $index >= count( $actions ) ) {
			return null;
		}
		return RunStepRepository::find_running_attempt( $run->id(), NodeType::Action, $actions[ $index ]->step_id() );
	}

	private static function handle_event_failure( JobRecord $job ): void {
		$error_code = 'runtime.event_materialization_failed';
		if ( $job->worker_attempts() >= self::MAX_EVENT_ATTEMPTS ) {
			try {
				EventReceiptRepository::mark_failed( $job->subject_id() );
				JobRepository::fail( $job->id(), $job->lease_token(), $error_code );
				return;
			} catch ( \Throwable ) {
				/* Preserve the durable job for another recovery attempt. */
			}
		}
		self::release_with_backoff( $job, $error_code );
	}

	private static function handle_run_failure( JobRecord $job ): void {
		$error_code = 'runtime.run_worker_failed';
		try {
			$lease = RunExecutionLease::from_job( $job, $job->subject_id() );
			$lease->renew();
			$run = RunRepository::find( $job->subject_id() );
			if ( null !== $run && $run->status()->halts_automatic_execution() ) {
				JobRepository::complete( $job->id(), $job->lease_token() );
				return;
			}
			if ( $job->worker_attempts() >= self::MAX_RUN_ATTEMPTS ) {
				$lease->transaction( static function () use ( $job, $run, $error_code ): void {
					if ( null !== $run && ! $run->status()->halts_automatic_execution() ) {
						if ( ! RunRepository::transition_status( $run->id(), $run->status(), RunStatus::Blocked, $error_code ) ) {
							throw new \RuntimeException( 'Automation run could not be blocked after repeated worker failures.' );
						}
					}
					if ( ! JobRepository::fail( $job->id(), $job->lease_token(), $error_code ) ) {
						throw new \RuntimeException( 'Automation run job could not be failed by its active lease owner.' );
					}
				} );
				return;
			}
			self::release_run_with_backoff( $job, $lease, $error_code );
		} catch ( \Throwable ) {
			/* A lost/expired lease is owned by another worker or becomes reclaimable. */
		}
	}

	private static function release_run_with_backoff( JobRecord $job, RunExecutionLease $lease, string $error_code ): void {
		$lease->renew();
		$exponent = max( 0, min( 6, $job->worker_attempts() - 1 ) );
		$delay = min( 300, 5 * ( 2 ** $exponent ) );
		JobRepository::release_for_retry( $job->id(), $job->lease_token(), gmdate( 'Y-m-d H:i:s', time() + $delay ), $error_code );
	}

	private static function release_with_backoff( JobRecord $job, string $error_code ): void {
		$exponent = max( 0, min( 6, $job->worker_attempts() - 1 ) );
		$delay = min( 300, 5 * ( 2 ** $exponent ) );
		JobRepository::release_for_retry( $job->id(), $job->lease_token(), gmdate( 'Y-m-d H:i:s', time() + $delay ), $error_code );
	}
}
