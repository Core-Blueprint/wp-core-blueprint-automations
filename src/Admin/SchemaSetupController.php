<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Automations\Persistence\Schema;
use CB\Automations\Support\Requirements;

defined( 'ABSPATH' ) || exit;

/** Privileged, nonce-protected setup or repair. Never mutates on GET or bootstrap. */
final class SchemaSetupController {
	private const ACTION = 'cb_automations_reauthorize_v1_setup';
	private static bool $registered = false;

	public static function init(): void {
		if ( self::$registered ) { return; }
		self::$registered = true;
		add_action( 'admin_post_' . self::ACTION, [ self::class, 'submit' ] );
		add_action( 'admin_notices', [ self::class, 'notice' ] );
	}

	public static function notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) || ! Requirements::runtime_ready() || Schema::ready() ) {
			return;
		}
		$authoring = Schema::authoring_ready();
		$message = $authoring
			? __( 'Automations runtime storage is unavailable. Disabled drafts can still be created, edited and saved, but execution is blocked.', 'core-blueprint-automations' )
			: __( 'Automations storage is not ready. Workflow editing and execution are disabled.', 'core-blueprint-automations' );

		echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p>';
		$can_install = Schema::can_install_new() || Schema::can_reauthorize();
		$can_repair = Schema::can_repair();
		if ( $can_install || $can_repair ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
			wp_nonce_field( self::ACTION );
			$label = $can_repair
				? __( 'Check or repair verified Automations storage', 'core-blueprint-automations' )
				: __( 'Retry clean installation before database creation', 'core-blueprint-automations' );
			submit_button( $label, 'secondary', 'submit', false );
			echo '</form>';
		} else {
			echo '<p>' . esc_html__( 'Storage requires manual review. No tables were modified. Back up the database and inspect the existing installation.', 'core-blueprint-automations' ) . '</p>';
		}
		echo '</div>';
	}

	public static function submit(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
			wp_die( esc_html__( 'POST required.', 'core-blueprint-automations' ), '', [ 'response' => 405 ] );
		}
		if ( ! current_user_can( 'activate_plugins' ) || ! Requirements::runtime_ready() ) {
			wp_die( esc_html__( 'Not authorized.', 'core-blueprint-automations' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::ACTION );
		try {
			if ( Schema::can_install_new() ) {
				Schema::install_new();
			} elseif ( Schema::can_reauthorize() ) {
				Schema::install_new( true );
			} elseif ( Schema::can_repair() ) {
				Schema::repair_verified();
			}
		} catch ( \Throwable ) {
			error_log( '[Core Blueprint Automations] Authorized storage setup remains on HOLD.' );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=' . AutomationsPage::SLUG ) );
		exit;
	}
}
