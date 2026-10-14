<?php
declare(strict_types=1);
namespace CB\Automations\Admin;

use CB\Automations\Integration\Suite;
use CB\Automations\Template\StoredTemplateRepository;
use CB\Automations\Template\TemplateSourceAdapter;
use CB\Automations\Template\WorkflowTemplateRegistry;

defined( 'ABSPATH' ) || exit;

/** Simple no-JS management/metadata preview for the owning product. */
final class TemplateManagementPage {
	public static function render( string $id, bool $manage ): void {
		if ( ! current_user_can( AutomationsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'Forbidden', 'core-blueprint-automations' ), '', [ 'response' => 403 ] );
		}
		$actor = get_current_user_id();
		$source = new TemplateSourceAdapter();
		if ( ! $source->can_view( $id, $actor ) ) {
			self::unavailable();
			return;
		}
		$saved_id = TemplateSourceAdapter::saved_id( $id );
		$post = null !== $saved_id ? StoredTemplateRepository::get( $saved_id, $actor ) : null;
		$builtin = null === $saved_id ? WorkflowTemplateRegistry::get( Suite::EXTENSION_ID, $id ) : null;
		$title = null !== $post ? $post->post_title : ( null !== $builtin ? $builtin->title() : '' );
		if ( '' === $title ) {
			self::unavailable();
			return;
		}
		$can_manage = $manage && null !== $post && StoredTemplateRepository::can_manage( $saved_id, $actor );
		if ( $manage && ! $can_manage ) {
			self::unavailable();
			return;
		}
		$notice = isset( $_GET['notice'] ) && is_scalar( $_GET['notice'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice.
			? sanitize_key( wp_unslash( (string) $_GET['notice'] ) ) : '';
		echo '<div class="wrap cb-core-wrap cb-automations-template-management">';
		echo '<p><a href="' . esc_url( AutomationsPage::url() ) . '">' . esc_html__( 'Back to Automations', 'core-blueprint-automations' ) . '</a></p>';
		echo '<h1 class="cb-core-title">' . esc_html( $title ) . '</h1>';
		if ( 'template_saved' === $notice ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Automation saved.', 'core-blueprint-automations' ) . '</p></div>';
		}
		echo '<p>' . esc_html( null !== $post ? $post->post_excerpt : $builtin->description() ) . '</p>';
		echo '<p>' . esc_html__( 'Automation created from template. Review it, assign execution authority and enable it when ready.', 'core-blueprint-automations' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="cb_automations_create_from_template">';
		echo '<input type="hidden" name="template_source" value="' . esc_attr( TemplateSourceAdapter::SOURCE_ID ) . '">';
		echo '<input type="hidden" name="template_provider" value="' . esc_attr( Suite::EXTENSION_ID ) . '">';
		echo '<input type="hidden" name="template_id" value="' . esc_attr( $id ) . '">';
		wp_nonce_field( 'cb_automations_create_from_template', '_cb_automations_nonce' );
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Use template', 'core-blueprint-automations' ) . '</button></form>';
		if ( $can_manage ) {
			self::form( 'rename', $saved_id, [
				'template_name' => [ 'type' => 'text', 'value' => $title ],
			], 'Automation name' );
			self::form( 'duplicate', $saved_id, [], 'Create automation' );
			self::form( 'export', $saved_id, [], 'Use template' );
			self::form( 'delete', $saved_id, [], 'Remove' );
		}
		echo '</div>';
	}

	/** Render a separate POST form, avoiding nested forms and GET mutations. */
	private static function form( string $operation, int $id, array $fields, string $button ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="cb-automations-template-operation">';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'cb_automations_template_' . $operation ) . '">';
		echo '<input type="hidden" name="template_id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( TemplateManagementController::nonce( $operation, $id ), '_cb_automations_nonce' );
		foreach ( $fields as $name => $field ) {
			echo '<label><span class="screen-reader-text">' . esc_html__( 'Automation name', 'core-blueprint-automations' ) . '</span>';
			echo '<input type="' . esc_attr( $field['type'] ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $field['value'] ) . '" maxlength="191" required></label>';
		}
		// Existing strings keep the six first-party locales in sync in this
		// bounded candidate. Specific translated operation labels are A3 polish.
		echo '<button type="submit" class="button">' . esc_html__( $button, 'core-blueprint-automations' ) . '</button></form>';
	}

	private static function unavailable(): void {
		echo '<div class="wrap cb-core-wrap"><h1>' . esc_html__( 'That workflow template is no longer available. No automation was created.', 'core-blueprint-automations' ) . '</h1></div>';
	}
}
