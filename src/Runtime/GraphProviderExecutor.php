<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Discovery\CapabilitySource;
use CB\Automations\Persistence\GraphCheckpointStore;
use CB\Automations\Persistence\RunRepository;
use CB\Automations\Persistence\RunStepRepository;
use CoreBlueprint\Core\Automation\ActionInvoker;
use CoreBlueprint\Core\Automation\InvocationContext;
use CoreBlueprint\Core\Automation\StateInvoker;

defined( 'ABSPATH' ) || exit;

/**
 * ADV-02C C4: internal authoritative provider dispatcher for one v2 node.
 *
 * The only returned success output comes directly from the public Base
 * invokers and is acknowledged with the existing C2/C3 lease-fenced store.
 * No worker, admin route or v2 admission invokes this class yet (C5).
 */
final class GraphProviderExecutor {
	private const BLOCKING_ERRORS = [
		'cb_core_automation_not_ready',
		'cb_core_automation_unknown_state',
		'cb_core_automation_unknown_action',
		'cb_core_automation_schema_mismatch',
		'cb_core_automation_principal_missing',
		'cb_core_automation_principal_invalid',
		'cb_core_automation_permission_denied',
	];

	/**
	 * Execute exactly one pending State/Action; never accept output from caller.
	 *
	 * @return array{status:string,cursor:?string} Checkpointed, blocked, failed or indeterminate.
	 */
	public static function execute(
		int $run_id,
		string $expected_cursor,
		RunExecutionLease $lease,
		CapabilitySource $catalog
	): array {
		$lease->renew();
		$snapshot = GraphInvocationSnapshot::prepare( $run_id, $expected_cursor, $lease, $catalog );
		$intent = $snapshot['intent'];
		$reference = $intent['capability'];
		$kind = $intent['kind'];
		$node_id = $intent['node_id'];
		$attempt = $snapshot['attempt'];

		// A failed causal check must occur before the durable Action start.
		$origin = null;
		if ( 'action' === $kind ) {
			$origin = ActionCausality::for_run(
				$snapshot['workflow_id'], $snapshot['correlation_id'], $snapshot['causality']
			);
			$lease->renew();
			$attempt = GraphActionStartFence::begin( $run_id, $node_id, $expected_cursor, $lease, $catalog );
			if ( null === $attempt ) {
				return [ 'status' => RunStatus::Indeterminate->value, 'cursor' => null ];
			}
		}
		if ( ! $attempt instanceof RunStepAttempt ) {
			throw new \LogicException( 'Advanced provider invocation was not reserved.' );
		}

		try {
			// The lease may expire while a provider is running; only the final
			// transaction can acknowledge success. No provider call is in SQL.
			$lease->renew();
			$invocation = new InvocationContext(
				$snapshot['principal'],
				'automations',
				$snapshot['correlation_id'],
				$snapshot['run_uuid'],
				$node_id,
				$attempt->attempt(),
				(string) $snapshot['workflow_id'],
				(string) $snapshot['workflow_revision']
			);
			if ( 'action' === $kind ) {
				$result = ActionCausality::during_action( $origin, static fn (): array|\WP_Error =>
					ActionInvoker::invoke(
						$reference->provider(), $reference->id(), $reference->schema_version(),
						$intent['input'], $invocation
					)
				);
			} else {
				$result = StateInvoker::resolve(
					$reference->provider(), $reference->id(), $reference->schema_version(),
					$intent['input'], $invocation
				);
			}
		} catch ( \Throwable ) {
			// An Action may already have performed an external mutation.
			return self::settle(
				$run_id, $attempt, $lease,
				'action' === $kind ? RunStatus::Indeterminate : RunStatus::Failed,
				'runtime.' . $kind . '_execution_failed', ''
			);
		}

		if ( is_wp_error( $result ) ) {
			$code = sanitize_key( (string) $result->get_error_code() );
			if ( '' === $code ) {
				$code = 'runtime.' . $kind . '_execution_failed';
			}
			$data = $result->get_error_data();
			$provider_code = is_array( $data ) && is_string( $data['provider_error_code'] ?? null )
				? sanitize_key( $data['provider_error_code'] ) : '';
			if ( strlen( $provider_code ) > 191 ) {
				$provider_code = '';
			}
			$target = in_array( $code, self::BLOCKING_ERRORS, true )
				? RunStatus::Blocked
				: ( 'action' === $kind ? RunStatus::Indeterminate : RunStatus::Failed );
			return self::settle( $run_id, $attempt, $lease, $target, $code, $provider_code );
		}
		if ( ! is_array( $result ) ) {
			return self::settle(
				$run_id, $attempt, $lease,
				'action' === $kind ? RunStatus::Indeterminate : RunStatus::Failed,
				'runtime.' . $kind . '_invalid_result', ''
			);
		}

		// Classify a demonstrably invalid current provider output before the
		// acknowledgement transaction. C3 repeats this gate under the lease.
		// An already-entered Action is still indeterminate, never retried.
		try {
			GraphProviderOutputGuard::require_valid( $catalog, $reference, $result );
		} catch ( \Throwable ) {
			return self::settle(
				$run_id, $attempt, $lease,
				'action' === $kind ? RunStatus::Indeterminate : RunStatus::Failed,
				'runtime.' . $kind . '_invalid_result', ''
			);
		}

		// Do not catch COMMIT uncertainty: after an Action there must be no
		// settlement that could be interpreted as proof of non-execution.
		$cursor = GraphCheckpointStore::commit_completion(
			$run_id, $lease, $catalog, $expected_cursor,
			$attempt, $node_id, $kind, $result
		);
		return [ 'status' => 'checkpointed', 'cursor' => $cursor ];
	}

	/** @return array{status:string,cursor:null} */
	private static function settle(
		int $run_id,
		RunStepAttempt $attempt,
		RunExecutionLease $lease,
		RunStatus $status,
		string $code,
		string $provider_code
	): array {
		$lease->transaction( static function () use ( $run_id, $attempt, $status, $code, $provider_code ): void {
			match ( $status ) {
				RunStatus::Blocked => RunStepRepository::block( $attempt, $code, $provider_code ),
				RunStatus::Failed => RunStepRepository::fail( $attempt, $code, $provider_code ),
				RunStatus::Indeterminate => RunStepRepository::indeterminate( $attempt, $code, $provider_code ),
				default => throw new \LogicException( 'Unsupported Advanced invocation settlement.' ),
			};
			if ( ! RunRepository::transition_status( $run_id, RunStatus::Running, $status, $code ) ) {
				throw new \RuntimeException( 'Advanced invocation settlement was not confirmed.' );
			}
		} );
		return [ 'status' => $status->value, 'cursor' => null ];
	}
}