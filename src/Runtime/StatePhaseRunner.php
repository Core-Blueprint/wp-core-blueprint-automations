<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Persistence\RunContextRepository;
use CB\Automations\Persistence\RunRepository;
use CB\Automations\Persistence\RunStepRepository;
use CB\Core\Automation\InvocationContext;
use CB\Core\Automation\StateInvoker;

defined( 'ABSPATH' ) || exit;

final class StatePhaseRunner {
	private const BLOCKING_ERRORS = [
		'cb_core_automation_not_ready',
		'cb_core_automation_unknown_state',
		'cb_core_automation_schema_mismatch',
		'cb_core_automation_principal_missing',
		'cb_core_automation_principal_invalid',
		'cb_core_automation_permission_denied',
	];

	public static function run( int $run_id, RunExecutionLease $lease ): StatePhaseResult {
		$lease->renew();
		$run = RunRepository::find( $run_id );
		if ( null === $run ) {
			return StatePhaseResult::blocked( 'runtime.run_missing' );
		}
		if ( ! in_array( $run->status(), [ RunStatus::Queued, RunStatus::Running ], true ) ) {
			return StatePhaseResult::blocked( 'runtime.run_not_executable' );
		}

		$states = $run->definition()->states();
		$cursor = $run->cursor();
		try {
			$index = RunCursor::state_index( $cursor, count( $states ) );
		} catch ( \UnexpectedValueException ) {
			return StatePhaseResult::blocked( 'runtime.cursor_invalid' );
		}
		if ( null === $index ) {
			return StatePhaseResult::complete();
		}
		if ( $index === count( $states ) ) {
			$advanced = $lease->transaction( static fn (): bool => RunRepository::advance_cursor( $run_id, $cursor, RunCursor::CONDITIONS ) );
			return $advanced ? StatePhaseResult::complete() : StatePhaseResult::blocked( 'runtime.cursor_conflict' );
		}

		$context = RunContextRepository::get( $run_id );
		if ( null === $context ) {
			return StatePhaseResult::blocked( 'runtime.context_missing' );
		}
		if ( ! isset( $context['outputs'] ) || ! is_array( $context['outputs'] ) ) {
			return StatePhaseResult::blocked( 'runtime.context_invalid' );
		}

		for ( $position = $index, $total = count( $states ); $position < $total; $position++ ) {
			$lease->renew();
			$step = $states[ $position ];
			$reference = $step->capability();
			$attempt = $lease->transaction( static function () use ( $run_id, $step, $reference ): RunStepAttempt {
				RunStepRepository::interrupt_running_attempts( $run_id, NodeType::State, $step->step_id() );
				return RunStepRepository::begin(
					$run_id,
					NodeType::State,
					$step->step_id(),
					$reference->provider(),
					$reference->id(),
					$reference->schema_version()
				);
			} );

			try {
				$input = BindingResolver::resolve_all( $step->bindings(), $context );
			} catch ( \UnexpectedValueException ) {
				$lease->transaction( static function () use ( $attempt ): void {
					RunStepRepository::fail( $attempt, 'runtime.binding_resolution_failed' );
				} );
				return StatePhaseResult::failed( 'runtime.binding_resolution_failed' );
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
			$result = StateInvoker::resolve(
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
					$lease->transaction( static function () use ( $attempt, $error_code, $provider_code ): void {
						RunStepRepository::block( $attempt, $error_code, $provider_code );
					} );
					return StatePhaseResult::blocked( $error_code, $provider_code );
				}
				$lease->transaction( static function () use ( $attempt, $error_code, $provider_code ): void {
					RunStepRepository::fail( $attempt, $error_code, $provider_code );
				} );
				return StatePhaseResult::failed( $error_code, $provider_code );
			}
			if ( ! is_array( $result ) ) {
				$lease->transaction( static function () use ( $attempt ): void {
					RunStepRepository::fail( $attempt, 'runtime.state_invalid_result' );
				} );
				return StatePhaseResult::failed( 'runtime.state_invalid_result' );
			}

			$next_context = $context;
			$next_context['outputs'][ $step->step_id() ] = $result;
			$next_cursor = RunCursor::after_state( $position + 1, $total );
			self::persist_success( $run_id, $cursor, $next_cursor, $next_context, $attempt, $lease );
			$context = $next_context;
			$cursor = $next_cursor;
		}

		return StatePhaseResult::complete();
	}

	/** @param array<string,mixed> $context */
	private static function persist_success(
		int $run_id,
		string $expected_cursor,
		string $next_cursor,
		array $context,
		RunStepAttempt $attempt,
		RunExecutionLease $lease
	): void {
		$lease->transaction( static function () use ( $run_id, $expected_cursor, $next_cursor, $context, $attempt ): void {
			RunContextRepository::put( $run_id, $context );
			RunStepRepository::succeed( $attempt );
			if ( ! RunRepository::advance_cursor( $run_id, $expected_cursor, $next_cursor ) ) {
				throw new \RuntimeException( 'Automation State acknowledgement cursor conflicted.' );
			}
		} );
	}

	private static function error_code( \WP_Error $error ): string {
		$code = sanitize_key( (string) $error->get_error_code() );
		return '' !== $code ? $code : 'runtime.state_failed';
	}

	private static function provider_error_code( \WP_Error $error ): string {
		$data = $error->get_error_data();
		$code = is_array( $data ) && is_string( $data['provider_error_code'] ?? null )
			? sanitize_key( $data['provider_error_code'] )
			: '';
		return strlen( $code ) <= 191 ? $code : '';
	}
}
