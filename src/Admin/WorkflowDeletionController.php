<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Automations\Persistence\WorkflowRepository;

defined( 'ABSPATH' ) || exit;

/** POST-only, nonce-protected, all-or-nothing deletion of disabled workflows. */
final class WorkflowDeletionController {
	private const SINGLE = 'cb_automations_delete_workflow';
	private const BULK = 'cb_automations_delete_workflows';
	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) { return; }
		self::$initialized = true;
		add_action( 'admin_post_' . self::SINGLE, [ self::class, 'single' ] );
		add_action( 'admin_post_' . self::BULK, [ self::class, 'bulk' ] );
	}

	public static function single(): void { self::handle( false ); }
	public static function bulk(): void { self::handle( true ); }

	private static function handle( bool $bulk ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? null ) ) {
			wp_die( esc_html__( 'This action requires HTTP POST.', 'core-blueprint-automations' ), '', [ 'response' => 405 ] );
		}
		if ( ! current_user_can( AutomationsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Automations.', 'core-blueprint-automations' ), '', [ 'response' => 403 ] );
		}
		$nonce = isset( $_POST['_cb_automations_nonce'] ) && is_string( $_POST['_cb_automations_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['_cb_automations_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $bulk ? self::BULK : self::SINGLE ) ) {
			wp_die( esc_html__( 'The request could not be verified. Reload the page and try again.', 'core-blueprint-automations' ), '', [ 'response' => 403 ] );
		}
		if ( ! isset( $_POST['confirm_delete'] ) || ! is_scalar( $_POST['confirm_delete'] )
			|| '1' !== sanitize_text_field( wp_unslash( (string) $_POST['confirm_delete'] ) ) ) {
			self::redirect( 'delete_confirmation' );
		}
		$selection = $_POST['workflow_revisions'] ?? null;
		if ( ! is_array( $selection ) || count( $selection ) < 1 || count( $selection ) > 25
			|| ( ! $bulk && 1 !== count( $selection ) ) ) {
			self::redirect( 'delete_invalid' );
		}
		$expected = [];
		foreach ( $selection as $raw_id => $raw_revision ) {
			$id = self::positive_int( $raw_id );
			$revision = self::positive_int( $raw_revision );
			if ( 0 === $id || 0 === $revision || isset( $expected[ $id ] ) ) {
				self::redirect( 'delete_invalid' );
			}
			$expected[ $id ] = $revision;
		}
		try {
			$outcome = WorkflowRepository::delete_disabled_batch( $expected );
		} catch ( \Throwable $error ) {
			// The COMMIT acknowledgement may have been lost. Never auto-retry a deletion.
			error_log( '[Core Blueprint Automations] Workflow deletion could not be verified: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			self::redirect( 'delete_unverified' );
		}
		self::redirect( match ( $outcome ) {
			'deleted' => $bulk ? 'deleted_selected' : 'deleted',
			'enabled' => 'delete_active',
			default => 'delete_conflict',
		} );
	}

	private static function positive_int( mixed $value ): int {
		if ( ! is_string( $value ) && ! is_int( $value ) ) { return 0; }
		$raw = (string) $value;
		if ( 1 !== preg_match( '/^[1-9][0-9]*$/D', $raw ) ) { return 0; }
		$parsed = filter_var( $raw, FILTER_VALIDATE_INT, [
			'options' => [ 'min_range' => 1, 'max_range' => PHP_INT_MAX - 1 ],
		] );
		return false === $parsed ? 0 : $parsed;
	}

	private static function redirect( string $notice ): never {
		wp_safe_redirect( AutomationsPage::url( [ 'view' => 'workflows', 'notice' => $notice ] ) );
		exit;
	}

	private function __construct() {}
}
