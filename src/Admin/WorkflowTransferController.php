<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Automations\Runtime\Vault;
use CB\Automations\Transfer\WorkflowTransferCodec;
use CB\Automations\Workflow\PersistencePolicy;
use CB\Automations\Workflow\WorkflowService;

defined( 'ABSPATH' ) || exit;

/**
 * Offline-only JSON import/export. Preview payload is encrypted in a short-
 * lived user-bound transient; no Base template service or network transport.
 */
final class WorkflowTransferController {
	private const TRANSIENT_PREFIX = 'cb_automations_import_';
	private const TTL = 600;
	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) { return; }
		self::$initialized = true;
		add_action( 'admin_post_cb_automations_import_preview', [ self::class, 'upload' ] );
		add_action( 'admin_post_cb_automations_import_confirm', [ self::class, 'confirm' ] );
		add_action( 'admin_post_cb_automations_export_workflow', [ self::class, 'export' ] );
		add_action( 'cb_automations_import_claim_expire', [ self::class, 'expire_claim' ], 10, 1 );
	}

	public static function upload(): void {
		self::guard( 'cb_automations_import_preview' );
		$file = $_FILES['workflow_file'] ?? null;
		if ( ! is_array( $file ) || (int) ( $file['error'] ?? -1 ) !== UPLOAD_ERR_OK
			|| ! is_string( $file['name'] ?? null ) || ! is_string( $file['tmp_name'] ?? null )
			|| ! is_numeric( $file['size'] ?? null ) || (int) $file['size'] < 1
			|| (int) $file['size'] > WorkflowTransferCodec::MAX_FILE_BYTES
			|| strtolower( (string) pathinfo( $file['name'], PATHINFO_EXTENSION ) ) !== 'json'
			|| ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::redirect( 'import_invalid_file' );
		}
		$json = file_get_contents( $file['tmp_name'], false, null, 0, WorkflowTransferCodec::MAX_FILE_BYTES + 1 );
		if ( ! is_string( $json ) || strlen( $json ) > WorkflowTransferCodec::MAX_FILE_BYTES ) {
			self::redirect( 'import_invalid_file' );
		}
		try {
			$transferred = WorkflowTransferCodec::import_json( $json, $file['name'] );
			$service = new WorkflowService();
			$validation = $service->validate( $transferred['definition'] );
			if ( ! PersistencePolicy::allows( $validation ) ) {
				self::redirect( 'import_sensitive' );
			}
			$token = bin2hex( random_bytes( 16 ) );
			$envelope = Vault::seal(
				[ 'owner' => get_current_user_id(), 'json' => $json, 'filename' => $file['name'] ],
				self::aad( $token )
			);
			if ( ! set_transient( self::key( $token ), $envelope, self::TTL ) ) {
				self::redirect( 'import_unavailable' );
			}
			wp_safe_redirect( AutomationsPage::url( [ 'view' => 'import', 'token' => $token ] ) );
			exit;
		} catch ( \Throwable ) {
			self::redirect( 'import_invalid' );
		}
	}

	/** @return array{name:string,definition:\CB\Automations\Workflow\Definition,validation:\CB\Automations\Validation\ValidationResult}|null */
	public static function preview( string $token ): ?array {
		if ( ! current_user_can( AutomationsPage::CAPABILITY ) || ! self::valid_token( $token ) ) { return null; }
		$envelope = get_transient( self::key( $token ) );
		if ( ! is_string( $envelope ) || '' === $envelope ) { return null; }
		try {
			$decoded = Vault::open( $envelope, self::aad( $token ) );
			if ( ! isset( $decoded['owner'], $decoded['json'], $decoded['filename'] )
				|| (int) $decoded['owner'] !== get_current_user_id()
				|| ! is_string( $decoded['json'] ) || ! is_string( $decoded['filename'] ) ) {
				return null;
			}
			$loaded = WorkflowTransferCodec::import_json( $decoded['json'], $decoded['filename'] );
			$validation = ( new WorkflowService() )->validate( $loaded['definition'] );
			if ( ! PersistencePolicy::allows( $validation ) ) { return null; }
			return $loaded + [ 'validation' => $validation ];
		} catch ( \Throwable ) {
			return null;
		}
	}

	public static function confirm(): void {
		self::guard( 'cb_automations_import_confirm' );
		$token = isset( $_POST['token'] ) && is_scalar( $_POST['token'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['token'] ) ) : '';
		if ( ! self::valid_token( $token ) ) { self::redirect( 'import_invalid' ); }
		$preview = self::preview( $token );
		if ( null === $preview ) { self::redirect( 'import_expired' ); }
		// Claim atomically across concurrent requests before creating a record.
		if ( ! add_option( 'cb_automations_import_claim_' . $token, get_current_user_id(), '', false ) ) {
			self::redirect( 'import_expired' );
		}
		wp_schedule_single_event( time() + self::TTL * 2, 'cb_automations_import_claim_expire', [ $token ] );
		// Invalidate the preview before creation; replaying this token must not
		// create another workflow. WorkflowService::create defaults to Disabled
		// and never inherits the exporting site's execution authority.
		delete_transient( self::key( $token ) );
		try {
			$id = ( new WorkflowService() )->create( $preview['name'], $preview['definition'], get_current_user_id() );
			wp_safe_redirect( AutomationsPage::url( [ 'workflow' => $id, 'notice' => 'created_template' ] ) );
			exit;
		} catch ( \Throwable ) {
			self::redirect( 'import_unavailable' );
		}
	}

	/** Delete only the one-time claim; the preview expires independently. */
	public static function expire_claim( string $token ): void {
		if ( self::valid_token( $token ) ) {
			delete_option( 'cb_automations_import_claim_' . $token );
		}
	}

	public static function export(): void {
		$id = isset( $_POST['workflow_id'] ) && is_scalar( $_POST['workflow_id'] )
			? absint( wp_unslash( (string) $_POST['workflow_id'] ) ) : 0;
		if ( $id < 1 ) { self::redirect( 'export_unavailable' ); }
		self::guard( 'cb_automations_export_workflow_' . $id );
		try {
			$service = new WorkflowService();
			$workflow = $service->find( $id );
			$validation = null !== $workflow ? $service->validate( $workflow->definition() ) : null;
			// Fail closed if a provider disappeared: older stored fields could
			// otherwise reveal literals whose current sensitivity is unknown.
			if ( null === $workflow || null === $validation || ! $validation->is_valid() || ! PersistencePolicy::allows( $validation ) ) {
				self::redirect( 'export_unavailable' );
			}
			$body = WorkflowTransferCodec::export_json( $workflow );
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="cb-automation-' . $id . '.json"' );
			header( 'X-Content-Type-Options: nosniff' );
			echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Strict bounded JSON download, never HTML.
			exit;
		} catch ( \Throwable ) {
			self::redirect( 'export_unavailable' );
		}
	}

	private static function guard( string $nonce_action ): void {
		if ( ! current_user_can( AutomationsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Automations.', 'core-blueprint-automations' ), '', [ 'response' => 403 ] );
		}
		$nonce = isset( $_POST['_cb_automations_nonce'] ) && is_scalar( $_POST['_cb_automations_nonce'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['_cb_automations_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'The request could not be verified. Reload and try again.', 'core-blueprint-automations' ), '', [ 'response' => 403 ] );
		}
	}

	private static function valid_token( string $token ): bool {
		return 1 === preg_match( '/^[a-f0-9]{32}$/D', $token );
	}

	private static function key( string $token ): string {
		return self::TRANSIENT_PREFIX . get_current_user_id() . '_' . $token;
	}

	private static function aad( string $token ): string {
		return 'workflow-import:' . get_current_user_id() . ':' . $token;
	}

	private static function redirect( string $notice ): never {
		wp_safe_redirect( AutomationsPage::url( [ 'view' => str_starts_with( $notice, 'export_' ) ? 'workflows' : 'import', 'notice' => sanitize_key( $notice ) ] ) );
		exit;
	}
}
