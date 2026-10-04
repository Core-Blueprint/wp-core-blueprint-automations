<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Persistence\RunContextRepository;
use CB\Automations\Persistence\RunRepository;
use CB\Automations\Persistence\RunStepRepository;
use CoreBlueprint\Core\Automation\ActionInvoker;
use CoreBlueprint\Core\Automation\InvocationContext;

defined( 'ABSPATH' ) || exit;

final class ActionPhaseRunner {
	private const BLOCKING_ERRORS = [
		'cb_core_automation_not_ready',
		'cb_core_automation_unknown_action',
		'cb_core_automation_schema_mismatch',
		'cb_core_automation_principal_missing',
		'cb_core_automation_principal_invalid',
		'cb_core_automation_permission_denied',
	];

	public static function run( int $run_id, RunExecutionLease $lease ): ActionPhaseResult {
		$lease->renew();
		$run = RunRepository::find( $run_id );
		if ( null === $run ) {
			return ActionPhaseResult::blocked( 'runtime.run_missing' );
		}
		if ( RunStatus::Succeeded === $run->status() ) {
			return ActionPhaseResult::complete();
		}
		if ( RunStatus::Indeterminate === $run->status() ) {
			return ActionPhaseResult::indeterminate( $run->failure_code() ?: 'runtime.action_outcome_unknown' );
		}
		if ( RunStatus::Queued === $run->status() ) {
			$started = $lease->transaction( static fn (): bool => RunRepository::transition_status( $run_id, RunStatus::Queued, RunStatus::Running ) );
			if ( ! $started ) {
				return ActionPhaseResult::blocked( 'runtime.run_status_conflict' );
			}
			$run = RunRepository::find( $run_id );
			if ( null === $run ) {
				return ActionPhaseResult::blocked( 'runtime.run_missing' );
			}
		}
		if ( RunStatus::Running !== $run->status() ) {
			return ActionPhaseResult::blocked( 'runtime.run_not_executable' );
		}

		$actions = $run->definition()->actions();
		$cursor = $run->cursor();
		try {
			$index = RunCursor::action_index( $cursor, count( $actions ) );
		} catch ( \UnexpectedValueException ) {
			return self::block_without_attempt( $run_id, 'runtime.cursor_invalid', $lease );
		}
		if ( null === $index ) {
			return self::finish_run( $run_id, $lease );
		}
		if ( $index === count( $actions ) ) {
			$advanced = $lease->transaction( static fn (): bool => RunRepository::advance_cursor( $run_id, $cursor, RunCursor::COMPLETE ) );
			if ( ! $advanced ) {
				return self::block_without_attempt( $run_id, 'runtime.cursor_conflict', $lease );
			}
			return self::finish_run( $run_id, $lease );
		}

		$context = null;
		for ( $position = $index, $total = count( $actions ); $position < $total; $position++ ) {
			$lease->renew();
			$step = $actions[ $position ];
			$reference = $step->capability();

			/*
			 * Check an unfinished mutating attempt before opening Vault context. If
			 * the previous worker died after provider entry and the Vault is now
			 * unreadable, the mutation outcome still takes precedence: unknown.
			 */
			$lingering = RunStepRepository::find_running_attempt( $run_id, NodeType::Action, $step->step_id() );
			if ( null !== $lingering ) {
				self::persist_indeterminate( $run_id, $lingering, 'runtime.action_outcome_unknown', '', $lease );
				return ActionPhaseResult::indeterminate( 'runtime.action_outcome_unknown' );
			}

			if ( null === $context ) {
				$context = RunContextRepository::get( $run_id );
				if ( null === $context || ! isset( $context['outputs'] ) || ! is_array( $context['outputs'] ) ) {
					return self::block_without_attempt( $run_id, 'runtime.context_invalid', $lease );
				}
			}

			$attempt = $lease->transaction( static fn (): RunStepAttempt => RunStepRepository::begin(
				$run_id,
				NodeType::Action,
				$step->step_id(),
				$reference->provider(),
				$reference->id(),
				$reference->schema_version()
			) );

			try {
				$input = BindingResolver::resolve_all( $step->bindings(), $context );
			} catch ( \UnexpectedValueException ) {
				self::persist_failed( $run_id, $attempt, 'runtime.binding_resolution_failed', '', $lease );
				return ActionPhaseResult::failed( 'runtime.binding_resolution_failed' );
			}

			$lease->renew();
			$invocation = new InvocationContext(
				$run->execution_principal_user_id(),
				'automations',
				$run->correlation_id(),
				$run->run_uuid(),
				$step->step_id(),
				$attempt->attempt(),
				(string) $run->workflow_id(),
				(string) $run->workflow_revision()
			);
			$result = ActionInvoker::invoke(
				$reference->provider(),
				$reference->id(),
				$reference->schema_version(),
				$input,
				$invocation
			);

			if ( is_wp_error( $result ) ) {
				$error_code = self::error_code( $result );
				$provider_code = self::provider_error_code( $result );
				if ( in_array( $error_code, self::BLOCKING_ERRORS, true ) ) {
					self::persist_blocked( $run_id, $attempt, $error_code, $provider_code, $lease );
					return ActionPhaseResult::blocked( $error_code, $provider_code );
				}
				if ( 'cb_core_automation_invalid_input' === $error_code ) {
					self::persist_failed( $run_id, $attempt, $error_code, $provider_code, $lease );
					return ActionPhaseResult::failed( $error_code, $provider_code );
				}
				self::persist_indeterminate( $run_id, $attempt, $error_code, $provider_code, $lease );
				return ActionPhaseResult::indeterminate( $error_code, $provider_code );
			}
			if ( ! is_array( $result ) ) {
				self::persist_indeterminate( $run_id, $attempt, 'runtime.action_outcome_unknown', '', $lease );
				return ActionPhaseResult::indeterminate( 'runtime.action_outcome_unknown' );
			}

			$next_context = $context;
			$next_context['outputs'][ $step->step_id() ] = $result;
			$next_cursor = RunCursor::after_action( $position + 1, $total );
			self::persist_success( $run_id, $cursor, $next_cursor, $next_context, $attempt, $lease );
			$context = $next_context;
			$cursor = $next_cursor;
		}

		return self::finish_run( $run_id, $lease );
	}

	private static function finish_run( int $run_id, RunExecutionLease $lease ): ActionPhaseResult {
		$success = $lease->transaction( static fn (): bool => RunRepository::transition_status( $run_id, RunStatus::Running, RunStatus::Succeeded ) );
		return $success ? ActionPhaseResult::complete() : ActionPhaseResult::blocked( 'runtime.run_status_conflict' );
	}

	private static function block_without_attempt( int $run_id, string $error_code, RunExecutionLease $lease ): ActionPhaseResult {
		$blocked = $lease->transaction( static fn (): bool => RunRepository::transition_status( $run_id, RunStatus::Running, RunStatus::Blocked, $error_code ) );
		return $blocked ? ActionPhaseResult::blocked( $error_code ) : ActionPhaseResult::blocked( 'runtime.run_status_conflict' );
	}

	/** @param array<string,mixed> $context */
	private static function persist_success( int $run_id, string $expected_cursor, string $next_cursor, array $context, RunStepAttempt $attempt, RunExecutionLease $lease ): void {
		$lease->transaction( static function () use ( $run_id, $expected_cursor, $next_cursor, $context, $attempt ): void {
			RunContextRepository::put( $run_id, $context );
			RunStepRepository::succeed( $attempt );
			if ( ! RunRepository::advance_cursor( $run_id, $expected_cursor, $next_cursor ) ) {
				throw new \RuntimeException( 'Automation Action acknowledgement cursor conflicted.' );
			}
		} );
	}

	private static function persist_failed( int $run_id, RunStepAttempt $attempt, string $error_code, string $provider_error_code, RunExecutionLease $lease ): void {
		$lease->transaction( static function () use ( $run_id, $attempt, $error_code, $provider_error_code ): void {
			RunStepRepository::fail( $attempt, $error_code, $provider_error_code );
			if ( ! RunRepository::transition_status( $run_id, RunStatus::Running, RunStatus::Failed, $error_code ) ) {
				throw new \RuntimeException( 'Automation Action failure status conflicted.' );
			}
		} );
	}

	private static function persist_blocked( int $run_id, RunStepAttempt $attempt, string $error_code, string $provider_error_code, RunExecutionLease $lease ): void {
		$lease->transaction( static function () use ( $run_id, $attempt, $error_code, $provider_error_code ): void {
			RunStepRepository::block( $attempt, $error_code, $provider_error_code );
			if ( ! RunRepository::transition_status( $run_id, RunStatus::Running, RunStatus::Blocked, $error_code ) ) {
				throw new \RuntimeException( 'Automation Action blocked status conflicted.' );
			}
		} );
	}

	private static function persist_indeterminate( int $run_id, RunStepAttempt $attempt, string $error_code, string $provider_error_code, RunExecutionLease $lease ): void {
		$lease->transaction( static function () use ( $run_id, $attempt, $error_code, $provider_error_code ): void {
			RunStepRepository::indeterminate( $attempt, $error_code, $provider_error_code );
			if ( ! RunRepository::transition_status( $run_id, RunStatus::Running, RunStatus::Indeterminate, $error_code ) ) {
				throw new \RuntimeException( 'Automation Action indeterminate status conflicted.' );
			}
		} );
	}

	private static function error_code( \WP_Error $error ): string {
		$code = sanitize_key( (string) $error->get_error_code() );
		return '' !== $code ? $code : 'runtime.action_outcome_unknown';
	}

	private static function provider_error_code( \WP_Error $error ): string {
		$data = $error->get_error_data();
		$code = is_array( $data ) && is_string( $data['provider_error_code'] ?? null )
			? sanitize_key( $data['provider_error_code'] )
			: '';
		return strlen( $code ) <= 191 ? $code : '';
	}
}
