<?php
declare(strict_types=1);
namespace CB\Automations\Admin;
use CB\Automations\Organisation\FolderRepository;
defined( 'ABSPATH' ) || exit;

final class WorkflowFolderController {
	private static bool $initialized = false;
	public static function init(): void {
		if ( self::$initialized ) { return; }
		self::$initialized = true;
		foreach ( [ 'create', 'rename', 'delete', 'assign' ] as $operation ) {
			add_action( 'admin_post_cb_automations_folder_' . $operation, [ self::class, $operation ] );
		}
	}
	private static function guard( string $action ): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? null ) ) {
			wp_die( esc_html__( 'This action requires HTTP POST.', 'core-blueprint-automations' ), '', [ 'response' => 405 ] );
		}

		if ( ! current_user_can( AutomationsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Automations.', 'core-blueprint-automations' ), '', [ 'response' => 403 ] );
		}
		$nonce = isset( $_POST['_cb_automations_nonce'] ) && is_scalar( $_POST['_cb_automations_nonce'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['_cb_automations_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cb_automations_folder_' . $action ) ) {
			wp_die( esc_html__( 'The request could not be verified. Reload and try again.', 'core-blueprint-automations' ), '', [ 'response' => 403 ] );
		}
	}
	private static function field_int( string $field ): int {
		$raw = isset( $_POST[ $field ] ) && is_scalar( $_POST[ $field ] )
			? wp_unslash( (string) $_POST[ $field ] ) : '';
		return ctype_digit( $raw ) ? (int) $raw : -1;
	}
	private static function field_name(): string {
		return isset( $_POST['folder_name'] ) && is_string( $_POST['folder_name'] )
			? sanitize_text_field( wp_unslash( $_POST['folder_name'] ) ) : '';
	}
	private static function redirect( string $notice ): never {
		wp_safe_redirect( AutomationsPage::url( [ 'view' => 'workflows', 'notice' => $notice ] ) );
		exit;
	}
	public static function create(): void {
		self::guard( 'create' );
		try {
			FolderRepository::ensure_ready();
			FolderRepository::create( self::field_name() );
			self::redirect( 'folder_created' );
		} catch ( \InvalidArgumentException ) { self::redirect( 'folder_invalid' ); }
		  catch ( \Throwable ) { self::redirect( 'folder_failed' ); }
	}
	public static function rename(): void {
		self::guard( 'rename' );
		try {
			FolderRepository::ensure_ready();
			if ( ! FolderRepository::rename( self::field_int( 'folder_id' ), self::field_name() ) ) {
				self::redirect( 'folder_missing' );
			}
			self::redirect( 'folder_renamed' );
		} catch ( \InvalidArgumentException ) { self::redirect( 'folder_invalid' ); }
		  catch ( \Throwable ) { self::redirect( 'folder_failed' ); }
	}
	public static function delete(): void {
		self::guard( 'delete' );
		$confirm = isset( $_POST['confirm_delete'] ) && is_scalar( $_POST['confirm_delete'] )
			&& '1' === sanitize_text_field( wp_unslash( (string) $_POST['confirm_delete'] ) );
		if ( ! $confirm ) { self::redirect( 'folder_confirmation_required' ); }
		try {
			FolderRepository::ensure_ready();
			if ( ! FolderRepository::delete( self::field_int( 'folder_id' ) ) ) { self::redirect( 'folder_missing' ); }
			self::redirect( 'folder_deleted' );
		} catch ( \Throwable ) { self::redirect( 'folder_failed' ); }
	}
	public static function assign(): void {
		self::guard( 'assign' );
		try {
			FolderRepository::ensure_ready();
			if ( ! FolderRepository::assign( self::field_int( 'workflow_id' ), self::field_int( 'folder_id' ) ) ) {
				self::redirect( 'folder_assignment_invalid' );
			}
			self::redirect( 'folder_assigned' );
		} catch ( \Throwable ) { self::redirect( 'folder_failed' ); }
	}
}
