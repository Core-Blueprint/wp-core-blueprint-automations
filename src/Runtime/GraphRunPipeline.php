<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Discovery\CapabilityCatalog;
use CB\Automations\Persistence\GraphCheckpointStore;
use CB\Automations\Persistence\JobRepository;
use CB\Automations\Persistence\PersistenceFailure;
use CB\Automations\Persistence\RunContextRepository;
use CB\Automations\Persistence\RunRepository;
use CB\Automations\Persistence\RunStepRepository;
use CB\Automations\Persistence\Schema;
use CB\Automations\Workflow\GraphDefinition;
use CB\Automations\Workflow\GraphDefinitionCodec;

defined( 'ABSPATH' ) || exit;

/**
 * ADV-02C C5: bounded sandbox-only v2 orchestrator.
 * All route decisions are derived from the immutable run and encrypted
 * acknowledged journal; the worker supplies only its durable run job.
 */
final class GraphRunPipeline {
	private const MAX_TRANSITIONS = 515;
	private const ERROR_UNKNOWN_ACTION = 'runtime.action_outcome_unknown';

	/** Identify v2 without invoking the Standard v1 RunRepository::find hydrator. */
	public static function is_advanced_run( int $run_id ): bool {
		global $wpdb;
		if ( $run_id < 1 ) {
			throw new \InvalidArgumentException( 'Invalid Advanced run id.' );
		}
		$table = Schema::runs_table();
		$version = $wpdb->get_var( $wpdb->prepare(
			"SELECT definition_version FROM {$table} WHERE id = %d LIMIT 1", $run_id
		) );
		if ( null === $version ) {
			if ( '' !== (string) $wpdb->last_error ) {
				throw PersistenceFailure::database( 'identify automation run definition' );
			}
			throw PersistenceFailure::definition();
		}
		if ( ! in_array( (string) $version, [ '1', '2' ], true ) ) {
			throw PersistenceFailure::definition();
		}
		return '2' === (string) $version;
	}

	public static function execute( int $run_id, JobRecord $job ): RunStatus {
		$lease = RunExecutionLease::from_job( $job, $run_id );
		$lease->renew();
		if ( ! GraphRuntimeGate::enabled() ) {
			throw new \UnexpectedValueException( 'Advanced sandbox execution is disabled.' );
		}
		$catalog = new CapabilityCatalog();
		for ( $i = 0; $i < self::MAX_TRANSITIONS; $i++ ) {
			$lease->renew();
			$state = self::projection( $run_id, $lease, $catalog );
			if ( $state['status']->halts_automatic_execution() ) {
				return $state['status'];
			}
			if ( RunStatus::Queued === $state['status'] ) {
				$started = $lease->transaction( static fn (): bool =>
					RunRepository::transition_status( $run_id, RunStatus::Queued, RunStatus::Running )
				);
				if ( ! $started ) {
					throw new \RuntimeException( 'Advanced run did not enter Running.' );
				}
				continue;
			}
			if ( RunStatus::Running !== $state['status'] ) {
					throw new \UnexpectedValueException( 'Advanced run status cannot be dispatched.' );
			}
			if ( 'complete' === $state['projection']['status'] ) {
					$done = $lease->transaction( static fn (): bool =>
						RunRepository::transition_status( $run_id, RunStatus::Running, RunStatus::Succeeded )
					);
					if ( ! $done ) {
						throw new \RuntimeException( 'Advanced completion was not exclusively confirmed.' );
					}
					return RunStatus::Succeeded;
			}
			if ( null !== $state['projection']['pending_branch'] ) {
				GraphCheckpointStore::commit_branch( $run_id, $lease, $catalog, $state['cursor'] );
				continue;
			}
			if ( 'pending' !== $state['projection']['status'] || null === $state['projection']['intent'] ) {
				throw new \UnexpectedValueException( 'Advanced checkpoint has no executable intent.' );
			}
			$outcome = GraphProviderExecutor::execute( $run_id, $state['cursor'], $lease, $catalog );
			if ( 'checkpointed' !== $outcome['status'] ) {
				$halted = RunStatus::tryFrom( $outcome['status'] );
				if ( null === $halted || ! $halted->halts_automatic_execution() ) {
					throw new \UnexpectedValueException( 'Unverified Advanced provider outcome.' );
				}
				return $halted;
			}
			if ( ! is_string( $outcome['cursor'] ) || $outcome['cursor'] === $state['cursor'] ) {
				throw new \UnexpectedValueException( 'Advanced cursor did not advance.' );
			}
		}
		throw new \UnexpectedValueException( 'Advanced runtime exhausted its bounded graph transitions.' );
	}

	/**
	 * Repair an error without Vault: a running Action is always UNKNOWN, never
	 * retried or silently blocked. Called only by the durable worker catch path.
	 */
	public static function handle_failure( JobRecord $job, bool $vault_failure ): void {
		$run_id = $job->subject_id();
		$lease = RunExecutionLease::from_job( $job, $run_id );
		$lease->renew();
		$retry = $lease->transaction( static function () use ( $run_id, $job, $vault_failure ): bool {
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
				if ( ! JobRepository::complete( $job->id(), $job->lease_token() ) ) {
					throw PersistenceFailure::definition();
				}
				return false;
			}
			// Never open Vault before checking for a potentially invoked Action.
			$steps = Schema::run_steps_table();
			$action = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, attempt FROM {$steps} WHERE run_id = %d AND node_type = %s AND status = %s
				 ORDER BY id DESC LIMIT 1 FOR UPDATE",
				$run_id, NodeType::Action->value, StepStatus::Running->value
			), ARRAY_A );
			if ( null === $action && '' !== (string) $wpdb->last_error ) {
				throw PersistenceFailure::database( 'check Advanced Action uncertainty' );
			}
			if ( is_array( $action ) ) {
				if ( (int) ( $action['id'] ?? 0 ) < 1 || (int) ( $action['attempt'] ?? 0 ) < 1 ) {
					throw PersistenceFailure::definition();
				}
				RunStepRepository::indeterminate( new RunStepAttempt( (int) $action['id'], (int) $action['attempt'] ), self::ERROR_UNKNOWN_ACTION );
				if ( ! RunRepository::transition_status( $run_id, $status, RunStatus::Indeterminate, self::ERROR_UNKNOWN_ACTION )
					|| ! JobRepository::complete( $job->id(), $job->lease_token() ) ) {
					throw PersistenceFailure::definition();
				}
				return false;
			}
			if ( ! GraphRuntimeGate::enabled() || $vault_failure || $job->worker_attempts() >= 5 ) {
				$code = ! GraphRuntimeGate::enabled() ? 'runtime.advanced_sandbox_disabled'
					: ( $vault_failure ? 'runtime.context_unavailable' : 'runtime.run_worker_failed' );
				if ( ! RunRepository::transition_status( $run_id, $status, RunStatus::Blocked, $code )
					|| ! JobRepository::fail( $job->id(), $job->lease_token(), $code ) ) {
					throw PersistenceFailure::definition();
				}
				return false;
			}
			return true;
		} );
		if ( $retry ) {
			$exponent = max( 0, min( 6, $job->worker_attempts() - 1 ) );
			$delay = min( 300, 5 * ( 2 ** $exponent ) );
			if ( ! JobRepository::release_for_retry(
				$job->id(), $job->lease_token(), gmdate( 'Y-m-d H:i:s', time() + $delay ), 'runtime.run_worker_failed'
			) ) {
				throw new \RuntimeException( 'Advanced run could not be requeued after a safe failure.' );
			}
		}
	}

	/** @return array{status:RunStatus,cursor:string,projection:array<string,mixed>} */
	private static function projection( int $run_id, RunExecutionLease $lease, CapabilityCatalog $catalog ): array {
		return $lease->transaction( static function () use ( $run_id, $catalog ): array {
			global $wpdb;
			$table = Schema::runs_table();
			$row = $wpdb->get_row( $wpdb->prepare(
					"SELECT id, definition_version, definition_json, definition_hash, status, run_cursor,
				 workflow_id, workflow_revision, execution_principal_user_id
				 FROM {$table} WHERE id = %d FOR UPDATE", $run_id
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
				return [ 'status' => $status, 'cursor' => (string) $row['run_cursor'], 'projection' => [] ];
			}
			$raw = $row['definition_json'] ?? null;
			$hash = $row['definition_hash'] ?? null;
			$cursor = $row['run_cursor'] ?? null;
			if ( (int) ( $row['workflow_id'] ?? 0 ) < 1 || (int) ( $row['workflow_revision'] ?? 0 ) < 1
				|| (int) ( $row['execution_principal_user_id'] ?? 0 ) < 1
				|| ! is_string( $raw ) || ! is_string( $hash )
				|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $hash )
				|| ! hash_equals( $hash, hash( 'sha256', $raw ) )
				|| ! is_string( $cursor ) || 1 !== preg_match( '/^graph:(0|[1-9][0-9]{0,2})$/D', $cursor )
				|| (int) substr( $cursor, 6 ) > 512 ) {
				throw PersistenceFailure::definition();
			}
			try {
				$document = json_decode( $raw, true, 64, JSON_THROW_ON_ERROR );
				$definition = GraphDefinitionCodec::decode( $document );
			} catch ( \Throwable ) {
				throw PersistenceFailure::definition();
			}
			$context = RunContextRepository::get( $run_id );
			if ( ! is_array( $context ) || ( $context['advanced_admission'] ?? null ) !== 'cli_sandbox_v1'
				|| ! is_array( $context['graph_checkpoint'] ?? null )
				|| array_keys( $context['graph_checkpoint'] ) !== [ 'journal', 'trigger_outputs' ] ) {
				throw PersistenceFailure::definition();
			}
			$journal = GraphCheckpointJournal::from_array( $context['graph_checkpoint']['journal'] );
			if ( $cursor !== 'graph:' . count( $journal->to_array()['entries'] ) ) {
				throw PersistenceFailure::definition();
			}
			$projection = $journal->replay( $definition, new GraphCheckpointPlanner( $catalog ), $context['graph_checkpoint']['trigger_outputs'] );
			return [ 'status' => $status, 'cursor' => $cursor, 'projection' => $projection ];
		} );
	}
}