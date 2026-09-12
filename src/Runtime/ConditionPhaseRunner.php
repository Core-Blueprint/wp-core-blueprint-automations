<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Condition\OperatorCatalog;
use CB\Automations\Persistence\RunContextRepository;
use CB\Automations\Persistence\RunRepository;
use CB\Automations\Persistence\RunStepRepository;

defined( 'ABSPATH' ) || exit;

final class ConditionPhaseRunner {
	public static function run( int $run_id, RunExecutionLease $lease ): ConditionPhaseResult {
		$lease->renew();
		$run = RunRepository::find( $run_id );
		if ( null === $run ) {
			return ConditionPhaseResult::blocked( 'runtime.run_missing' );
		}
		if ( ! in_array( $run->status(), [ RunStatus::Queued, RunStatus::Running ], true ) ) {
			return ConditionPhaseResult::blocked( 'runtime.run_not_executable' );
		}

		$conditions = $run->definition()->conditions();
		$cursor = $run->cursor();
		try {
			$index = RunCursor::condition_index( $cursor, count( $conditions ) );
		} catch ( \UnexpectedValueException ) {
			return ConditionPhaseResult::blocked( 'runtime.cursor_invalid' );
		}
		if ( null === $index ) {
			return ConditionPhaseResult::matched();
		}
		if ( $index === count( $conditions ) ) {
			$advanced = $lease->transaction( static fn (): bool => RunRepository::advance_cursor( $run_id, $cursor, 'actions:0' ) );
			return $advanced ? ConditionPhaseResult::matched() : ConditionPhaseResult::blocked( 'runtime.cursor_conflict' );
		}

		$context = RunContextRepository::get( $run_id );
		if ( null === $context || ! isset( $context['outputs'] ) || ! is_array( $context['outputs'] ) ) {
			return ConditionPhaseResult::blocked( 'runtime.context_invalid' );
		}

		for ( $position = $index, $total = count( $conditions ); $position < $total; $position++ ) {
			$lease->renew();
			$condition = $conditions[ $position ];
			$attempt = $lease->transaction( static function () use ( $run_id, $condition ): RunStepAttempt {
				RunStepRepository::interrupt_running_attempts( $run_id, NodeType::Condition, $condition->condition_id() );
				return RunStepRepository::begin( $run_id, NodeType::Condition, $condition->condition_id() );
			} );

			try {
				$left = BindingResolver::resolve( $condition->left(), $context );
				$right_binding = $condition->right();
				$right = null === $right_binding ? null : BindingResolver::resolve( $right_binding, $context );
				$matches = OperatorCatalog::evaluate( $condition->operator(), $left, $right );
			} catch ( \UnexpectedValueException ) {
				$lease->transaction( static function () use ( $attempt ): void {
					RunStepRepository::fail( $attempt, 'runtime.condition_evaluation_failed' );
				} );
				return ConditionPhaseResult::failed( 'runtime.condition_evaluation_failed' );
			}

			if ( ! $matches ) {
				$lease->transaction( static function () use ( $attempt ): void {
					RunStepRepository::skip( $attempt );
				} );
				return ConditionPhaseResult::skipped();
			}

			$next_cursor = RunCursor::after_condition( $position + 1, $total );
			self::persist_match( $run_id, $cursor, $next_cursor, $attempt, $lease );
			$cursor = $next_cursor;
		}

		return ConditionPhaseResult::matched();
	}

	private static function persist_match(
		int $run_id,
		string $expected_cursor,
		string $next_cursor,
		RunStepAttempt $attempt,
		RunExecutionLease $lease
	): void {
		$lease->transaction( static function () use ( $run_id, $expected_cursor, $next_cursor, $attempt ): void {
			RunStepRepository::succeed( $attempt );
			if ( ! RunRepository::advance_cursor( $run_id, $expected_cursor, $next_cursor ) ) {
				throw new \RuntimeException( 'Automation Condition acknowledgement cursor conflicted.' );
			}
		} );
	}
}
