<?php
declare(strict_types=1);
namespace CB\Automations\Admin;

use CB\Automations\Template\StoredTemplateRepository;
use CB\Automations\Workflow\DefinitionCodec;

defined( 'ABSPATH' ) || exit;

/** Domain-owned POST-only template operations; Base never mutates content. */
final class TemplateManagementController {
	public static function init(): void {
		foreach ( [ 'save', 'rename', 'duplicate', 'delete', 'export' ] as $operation ) {
			add_action( 'admin_post_cb_automations_template_' . $operation, [ self::class, $operation ] );
		}
	}

	public static function nonce( string $operation, int $id ): string {
		return 'cb_automations_template_' . $operation . '_' . $id;
	}

	private static function authorize( string $operation, int $id ): int {
		if ( ! current_user_can( AutomationsPage::CAPABILITY ) || get_current_user_id() < 1 ) {
			wp_die( esc_html__( 'Forbidden', 'core-blueprint-automations' ), '', [ 'response' => 403 ] );
		}
		$nonce = isset( $_POST['_cb_automations_nonce'] ) && is_scalar( $_POST['_cb_automations_nonce'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['_cb_automations_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::nonce( $operation, $id ) ) ) {
			wp_die( esc_html__( 'Invalid request', 'core-blueprint-automations' ), '', [ 'response' => 403 ] );
		}
		return get_current_user_id();
	}

	private static function requested_id( string $key ): int {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] )
			? absint( wp_unslash( (string) $_POST[ $key ] ) ) : 0;
	}

	private static function name(): string {
		return isset( $_POST['template_name'] ) && is_string( $_POST['template_name'] )
			? sanitize_text_field( wp_unslash( $_POST['template_name'] ) ) : '';
	}

	private static function go( array $query ): never {
		wp_safe_redirect( AutomationsPage::url( $query ) );
		exit;
	}

	public static function save(): void {
		$source = self::requested_id( 'workflow_id' );
		$actor = self::authorize( 'save', $source );
		$scope = isset( $_POST['template_scope'] ) && is_string( $_POST['template_scope'] )
			? sanitize_key( wp_unslash( $_POST['template_scope'] ) ) : '';
		try {
			$id = StoredTemplateRepository::save_workflow( $source, self::name(), $scope, $actor );
			self::go( [ 'template_manage' => 'saved-' . $id, 'notice' => 'template_saved' ] );
		} catch ( \Throwable ) {
			self::go( [ 'workflow' => $source, 'notice' => 'template_unavailable' ] );
		}
	}

	public static function rename(): void {
		$id = self::requested_id( 'template_id' );
		$actor = self::authorize( 'rename', $id );
		$ok = false;
		try { $ok = StoredTemplateRepository::rename( $id, self::name(), $actor ); }
		catch ( \Throwable ) {}
		self::go( [ 'template_manage' => 'saved-' . $id, 'notice' => $ok ? 'template_saved' : 'template_unavailable' ] );
	}

	public static function duplicate(): void {
		$id = self::requested_id( 'template_id' );
		$actor = self::authorize( 'duplicate', $id );
		try {
			$new = StoredTemplateRepository::duplicate( $id, $actor );
			self::go( [ 'template_manage' => 'saved-' . $new, 'notice' => 'template_saved' ] );
		} catch ( \Throwable ) {
			self::go( [ 'template_manage' => 'saved-' . $id, 'notice' => 'template_unavailable' ] );
		}
	}

	public static function delete(): void {
		$id = self::requested_id( 'template_id' );
		$actor = self::authorize( 'delete', $id );
		if ( ! StoredTemplateRepository::remove( $id, $actor ) ) {
			self::go( [ 'template_manage' => 'saved-' . $id, 'notice' => 'template_unavailable' ] );
		}
		self::go( [ 'notice' => 'template_removed' ] );
	}

	public static function export(): void {
		$id = self::requested_id( 'template_id' );
		$actor = self::authorize( 'export', $id );
		$post = StoredTemplateRepository::get( $id, $actor );
		$definition = StoredTemplateRepository::definition( $id, $actor );
		if ( null === $post || null === $definition ) {
			self::go( [ 'template_manage' => 'saved-' . $id, 'notice' => 'template_unavailable' ] );
		}
		$data = wp_json_encode( [
			'format' => 'core-blueprint-automations-template',
			'version' => 1,
			'title' => $post->post_title,
			'definition' => DefinitionCodec::encode( $definition ),
		] );
		if ( ! is_string( $data ) ) {
			self::go( [ 'template_manage' => 'saved-' . $id, 'notice' => 'template_unavailable' ] );
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="automation-template-' . $id . '.json"' );
		header( 'X-Content-Type-Options: nosniff' );
		echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode serializes a typed, scrubbed definition.
		exit;
	}
}
