<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Automations\Persistence\PersistenceFailure;
use CB\Automations\Workflow\ActivationState;
use CB\Automations\Workflow\ExecutionAuthorityDecision;
use CB\Automations\Workflow\WorkflowSaveResult;
use CB\Automations\Workflow\WorkflowService;

defined( 'ABSPATH' ) || exit;

final class WorkflowActivationController {
	private const ACTION = 'cb_automations_toggle_workflow';

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'toggle' ] );
	}

	public static function toggle(): void {
		// WordPress admin_post_ also dispatches GET, HEAD and other methods.
		// Reject before looking up any workflow or parsing any submitted fields.
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? null ) ) {
			wp_die(
				esc_html__( 'This action requires HTTP POST.', 'core-blueprint-automations' ),
				esc_html__( 'Method not allowed', 'core-blueprint-automations' ),
				[ 'response' => 405 ]
			);
		}

		if ( ! current_user_can( AutomationsPage::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage Automations.', 'core-blueprint-automations' ),
				esc_html__( 'Forbidden', 'core-blueprint-automations' ),
				[ 'response' => 403 ]
			);
		}

		$id = isset( $_POST['workflow_id'] ) && is_scalar( $_POST['workflow_id'] )
			? absint( wp_unslash( (string) $_POST['workflow_id'] ) )
			: 0;
		$target = isset( $_POST['activation_state'] ) && is_scalar( $_POST['activation_state'] )
			? ActivationState::tryFrom( sanitize_key( wp_unslash( (string) $_POST['activation_state'] ) ) )
			: null;

		if ( $id < 1 || null === $target ) {
			self::redirect( 'invalid' );
		}

		$nonce = isset( $_POST['_cb_automations_nonce'] ) && is_scalar( $_POST['_cb_automations_nonce'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['_cb_automations_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $id ) ) ) {
			wp_die(
				esc_html__( 'The request could not be verified. Reload the page and try again.', 'core-blueprint-automations' ),
				esc_html__( 'Invalid request', 'core-blueprint-automations' ),
				[ 'response' => 403 ]
			);
		}

		// Exact render-time revision is mandatory, and must remain a PHP integer
		// without JS-Number rounding, scalar coercion, overflow or implied refresh.
		$revision_input = isset( $_POST['expected_revision'] ) && is_string( $_POST['expected_revision'] )
			? wp_unslash( $_POST['expected_revision'] ) : '';
		$expected_revision = 0;
		if ( 1 === preg_match( '/^[1-9][0-9]*$/D', $revision_input ) ) {
			$parsed = filter_var( $revision_input, FILTER_VALIDATE_INT, [
				'options' => [ 'min_range' => 1, 'max_range' => PHP_INT_MAX - 1 ],
			] );
			$expected_revision = false === $parsed ? 0 : $parsed;
		}
		if ( $expected_revision < 1 ) {
			self::redirect( 'invalid' );
		}

		// This read checks what the operator actually saw, but is not a lock.
		// WorkflowService performs the authoritative SQL WHERE revision CAS.
		try {
			$service = new WorkflowService();
			$record = $service->find( $id );
		} catch ( \Throwable $error ) {
			error_log( '[Core Blueprint Automations] Workflow activation read failed.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			self::redirect( 'storage_failed' );
		}
		if ( null === $record ) {
			self::redirect( 'not_found' );
		}
		if ( $record->definition() instanceof \CB\Automations\Workflow\GraphDefinition ) {
			self::redirect( 'invalid' ); // V2 may not be activated or status-toggled in ADV-02A.
		}
		if ( $record->revision() !== $expected_revision ) {
			self::redirect( 'conflict' );
		}
		if ( $record->activation_state() === $target ) {
			self::redirect( 'invalid' );
		}

		try {
			$result = $service->save(
				$record->id(),
				$expected_revision,
				$record->name(),
				$target,
				$record->definition(),
				get_current_user_id(),
				false
			);
			$notice = self::notice_for_result( $result, $target );
		} catch ( \Throwable $error ) {
			// Save might already have reached SQL commit. Neither success nor
			// definitive failure is provable; never repeat the operator's POST.
			error_log( '[Core Blueprint Automations] Workflow activation result unverified.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			$notice = 'activation_unverified';
		}
		self::redirect( $notice );
	}

	public static function nonce_action( int $workflow_id ): string {
		return self::ACTION . '_' . max( 0, $workflow_id );
	}

	private static function notice_for_result( WorkflowSaveResult $result, ActivationState $requested_state ): string {
		if ( WorkflowSaveResult::SAVED_DISABLED === $result->status() ) {
			return match ( $result->activation_block_reason() ) {
				WorkflowSaveResult::EXECUTION_UNAVAILABLE => 'saved_disabled_execution_unavailable',
				ExecutionAuthorityDecision::PRINCIPAL_MISSING => 'saved_disabled_principal_missing',
				ExecutionAuthorityDecision::PRINCIPAL_INVALID => 'saved_disabled_principal_invalid',
				ExecutionAuthorityDecision::PRINCIPAL_PERMISSION_DENIED => 'saved_disabled_principal_denied',
				ExecutionAuthorityDecision::OPERATOR_PERMISSION_DENIED => 'saved_disabled_operator_denied',
				ExecutionAuthorityDecision::CAPABILITY_UNAVAILABLE => 'saved_disabled_capability_unavailable',
				default => 'saved_disabled',
			};
		}

		return match ( $result->status() ) {
			WorkflowSaveResult::SAVED => ActivationState::Enabled === $requested_state ? 'enabled' : 'disabled',
			WorkflowSaveResult::PERSISTENCE_BLOCKED => 'persistence_blocked',
			WorkflowSaveResult::CONFLICT => 'conflict',
			WorkflowSaveResult::READBACK_UNVERIFIED => 'activation_unverified',
			default => 'failed',
		};
	}

	private static function redirect( string $notice ): never {
		wp_safe_redirect( AutomationsPage::url( [ 'notice' => sanitize_key( $notice ) ] ) );
		exit;
	}

	private function __construct() {}
}
