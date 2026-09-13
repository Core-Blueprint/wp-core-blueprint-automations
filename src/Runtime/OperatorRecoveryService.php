<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Binding\Binding;
use CB\Automations\Persistence\OperatorRecoveryRepository;
use CB\Automations\Persistence\RunRepository;
use CB\Automations\Workflow\Step;

defined( 'ABSPATH' ) || exit;

final class OperatorRecoveryService {
	public function recover(
		int $run_id,
		string $node_id,
		int $attempt,
		OperatorRecoveryDecision $decision,
		int $operator_user_id
	): RunStatus {
		if ( $run_id < 1 || $attempt < 1 || $operator_user_id < 1 || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $node_id ) ) {
			throw new \InvalidArgumentException( 'Invalid automation operator recovery request.' );
		}

		$run = RunRepository::find( $run_id );
		if ( null === $run ) {
			throw new OperatorRecoveryFailure( 'recovery.run_missing' );
		}
		if ( RunStatus::Indeterminate !== $run->status() ) {
			throw new OperatorRecoveryFailure( 'recovery.run_not_indeterminate' );
		}

		$actions = $run->definition()->actions();
		try {
			$index = RunCursor::action_index( $run->cursor(), count( $actions ) );
		} catch ( \UnexpectedValueException ) {
			throw new OperatorRecoveryFailure( 'recovery.cursor_invalid' );
		}
		if ( null === $index || $index >= count( $actions ) || $actions[ $index ]->step_id() !== $node_id ) {
			throw new OperatorRecoveryFailure( 'recovery.action_mismatch' );
		}

		$target = RunStatus::Cancelled;
		$result_cursor = $run->cursor();
		$rearm = false;

		if ( OperatorRecoveryDecision::ConfirmedSucceeded === $decision ) {
			if ( self::has_downstream_dependency_on( $actions, $index, $node_id ) ) {
				throw new OperatorRecoveryFailure( 'recovery.output_required_downstream' );
			}
			$result_cursor = RunCursor::after_action( $index + 1, count( $actions ) );
			$target = RunCursor::COMPLETE === $result_cursor ? RunStatus::Succeeded : RunStatus::Queued;
			$rearm = RunStatus::Queued === $target;
		} elseif ( OperatorRecoveryDecision::ConfirmedDidNotOccur === $decision ) {
			$target = RunStatus::Queued;
			$rearm = true;
		}

		OperatorRecoveryRepository::resolve(
			$run_id,
			$node_id,
			$attempt,
			$decision,
			$operator_user_id,
			$run->cursor(),
			$result_cursor,
			$target,
			$rearm
		);

		if ( $rearm ) {
			try {
				WorkerWakeup::request_if_pending();
			} catch ( \Throwable ) {
				// The durable queued job remains authoritative; a later request can wake it.
			}
		}

		return $target;
	}

	/** @param Step[] $actions */
	private static function has_downstream_dependency_on( array $actions, int $current_index, string $node_id ): bool {
		for ( $index = $current_index + 1, $total = count( $actions ); $index < $total; $index++ ) {
			foreach ( $actions[ $index ]->bindings() as $binding ) {
				if ( Binding::SOURCE_STEP_OUTPUT === $binding->source() && $binding->step_id() === $node_id ) {
					return true;
				}
			}
		}
		return false;
	}
}
