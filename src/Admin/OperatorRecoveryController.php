<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Automations\Persistence\PersistenceFailure;
use CB\Automations\Runtime\OperatorRecoveryDecision;
use CB\Automations\Runtime\OperatorRecoveryFailure;
use CB\Automations\Runtime\OperatorRecoveryService;
defined( 'ABSPATH' ) || exit;

final class OperatorRecoveryController {
	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'admin_post_cb_automations_recover_run', [ self::class, 'recover' ] );
	}

	public static function recover(): void {
		if ( ! current_user_can( RecoveryCapability::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to recover automation runs.', 'core-blueprint-automations' ),
				esc_html__( 'Forbidden', 'core-blueprint-automations' ),
				[ 'response' => 403 ]
			);
		}

		$run_id = isset( $_POST['run_id'] ) && is_scalar( $_POST['run_id'] ) ? absint( wp_unslash( (string) $_POST['run_id'] ) ) : 0;
		$node_id = isset( $_POST['node_id'] ) && is_scalar( $_POST['node_id'] ) ? sanitize_key( wp_unslash( (string) $_POST['node_id'] ) ) : '';
		$attempt = isset( $_POST['attempt'] ) && is_scalar( $_POST['attempt'] ) ? absint( wp_unslash( (string) $_POST['attempt'] ) ) : 0;
		$decision = isset( $_POST['decision'] ) && is_scalar( $_POST['decision'] )
			? OperatorRecoveryDecision::tryFrom( sanitize_key( wp_unslash( (string) $_POST['decision'] ) ) )
			: null;
		$confirmed = isset( $_POST['recovery_confirm'] ) && is_scalar( $_POST['recovery_confirm'] )
			&& '1' === sanitize_text_field( wp_unslash( (string) $_POST['recovery_confirm'] ) );
		$nonce = isset( $_POST['_cb_automations_nonce'] ) && is_scalar( $_POST['_cb_automations_nonce'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['_cb_automations_nonce'] ) )
			: '';

		if ( $run_id < 1 || $attempt < 1 || '' === $node_id || null === $decision || ! $confirmed ) {
			self::redirect( $run_id, 'invalid' );
		}
		if ( ! wp_verify_nonce( $nonce, 'cb_automations_recover_run_' . $run_id ) ) {
			wp_die(
				esc_html__( 'The recovery request could not be verified. Reload the page and try again.', 'core-blueprint-automations' ),
				esc_html__( 'Invalid request', 'core-blueprint-automations' ),
				[ 'response' => 403 ]
			);
		}

		try {
			( new OperatorRecoveryService() )->recover(
				$run_id,
				$node_id,
				$attempt,
				$decision,
				get_current_user_id()
			);
			self::redirect( $run_id, $decision->value );
		} catch ( OperatorRecoveryFailure $error ) {
			self::redirect( $run_id, $error->machine_code() );
		} catch ( PersistenceFailure $error ) {
			error_log( '[Core Blueprint Automations] Operator recovery persistence failure: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- bounded diagnostic without runtime values.
			self::redirect( $run_id, 'storage_failed' );
		} catch ( \Throwable $error ) {
			error_log( '[Core Blueprint Automations] Operator recovery failed: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- bounded diagnostic without runtime values.
			self::redirect( $run_id, 'failed' );
		}
	}

	private static function redirect( int $run_id, string $notice ): never {
		wp_safe_redirect(
			AutomationRunsPage::url( [
				'run' => max( 0, $run_id ),
				'recovery_notice' => sanitize_key( str_replace( '.', '_', $notice ) ),
			] )
		);
		exit;
	}
}
