<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Core\Admin\PageRegistry;

defined( 'ABSPATH' ) || exit;

final class AdminAssets {
	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function enqueue( string $hook ): void {
		if ( $hook !== PageRegistry::hook_suffix( AutomationsPage::SLUG ) ) {
			return;
		}

		$css = CB_AUTOMATIONS_DIR . 'assets/css/admin-automations.css';
		$js  = CB_AUTOMATIONS_DIR . 'assets/js/admin-editor.js';

		wp_enqueue_style(
			'cb-automations-admin',
			CB_AUTOMATIONS_URL . 'assets/css/admin-automations.css',
			[],
			is_file( $css ) ? (string) filemtime( $css ) : CB_AUTOMATIONS_VERSION
		);

		wp_enqueue_script(
			'cb-automations-admin-editor',
			CB_AUTOMATIONS_URL . 'assets/js/admin-editor.js',
			[],
			is_file( $js ) ? (string) filemtime( $js ) : CB_AUTOMATIONS_VERSION,
			true
		);
	}
}
