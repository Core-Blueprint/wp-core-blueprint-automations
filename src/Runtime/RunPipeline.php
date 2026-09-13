<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Persistence\RunRepository;

defined( 'ABSPATH' ) || exit;

final class RunPipeline {
	public static function execute( int $run_id, JobRecord $job ): RunStatus {
		$lease = RunExecutionLease::from_job( $job, $run_id );
		$lease->renew();
		$run = RunRepository::find( $run_id );
		if ( null === $run ) {
			throw new \RuntimeException( 'Automation run is missing.' );
		}

		if ( RunStatus::Queued === $run->status() ) {
			$started = $lease->transaction( static fn (): bool => RunRepository::transition_status( $run_id, RunStatus::Queued, RunStatus::Running ) );
			if ( ! $started ) {
				throw new \RuntimeException( 'Automation run could not enter running state.' );
			}
			$run = RunRepository::find( $run_id );
			if ( null === $run ) {
				throw new \RuntimeException( 'Automation run disappeared after start.' );
			}
		}

		if ( RunStatus::Running !== $run->status() ) {
			return $run->status();
		}

		$state = StatePhaseRunner::run( $run_id, $lease );
		if ( ! $state->is_complete() ) {
			return self::settle_phase_failure( $run_id, $state->status(), $state->error_code(), $lease );
		}

		$condition = ConditionPhaseRunner::run( $run_id, $lease );
		if ( ConditionPhaseResult::SKIPPED === $condition->status() ) {
			return self::transition( $run_id, RunStatus::Skipped, '', $lease );
		}
		if ( ! $condition->is_matched() ) {
			return self::settle_phase_failure( $run_id, $condition->status(), $condition->error_code(), $lease );
		}

		$action = ActionPhaseRunner::run( $run_id, $lease );
		$final = RunRepository::find( $run_id );
		if ( null === $final ) {
			throw new \RuntimeException( 'Automation run disappeared after Action execution.' );
		}
		if ( $final->status()->halts_automatic_execution() ) {
			return $final->status();
		}

		throw new \RuntimeException( 'Automation Action phase returned without a durable terminal state: ' . $action->status() );
	}

	private static function settle_phase_failure( int $run_id, string $phase_status, string $error_code, RunExecutionLease $lease ): RunStatus {
		$target = match ( $phase_status ) {
			StatePhaseResult::FAILED,
			ConditionPhaseResult::FAILED => RunStatus::Failed,
			StatePhaseResult::BLOCKED,
			ConditionPhaseResult::BLOCKED => RunStatus::Blocked,
			default => throw new \UnexpectedValueException( 'Unsupported automation phase result.' ),
		};
		return self::transition( $run_id, $target, $error_code, $lease );
	}

	private static function transition( int $run_id, RunStatus $target, string $error_code, RunExecutionLease $lease ): RunStatus {
		$changed = $lease->transaction( static fn (): bool => RunRepository::transition_status( $run_id, RunStatus::Running, $target, $error_code ) );
		if ( ! $changed ) {
			$current = RunRepository::find( $run_id );
			if ( null !== $current && $current->status()->halts_automatic_execution() ) {
				return $current->status();
			}
			throw new \RuntimeException( 'Automation run status transition conflicted.' );
		}
		return $target;
	}
}
