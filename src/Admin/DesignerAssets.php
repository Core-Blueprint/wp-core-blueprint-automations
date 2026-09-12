<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Core\Admin\PageRegistry;

defined( 'ABSPATH' ) || exit;

/** Automations-specific adapter assets for the public Base Designer Shell. */
final class DesignerAssets {
	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ], 20 );
	}

	public static function enqueue( string $hook ): void {
		if ( $hook !== PageRegistry::hook_suffix( AutomationsPage::SLUG ) ) {
			return;
		}

		$css = CB_AUTOMATIONS_DIR . 'assets/css/admin-designer-shell.css';
		$js  = CB_AUTOMATIONS_DIR . 'assets/js/admin-designer-shell.js';

		wp_enqueue_style(
			'cb-automations-admin-designer-shell',
			CB_AUTOMATIONS_URL . 'assets/css/admin-designer-shell.css',
			[ 'cb-automations-admin-condition-builder' ],
			is_file( $css ) ? (string) filemtime( $css ) : CB_AUTOMATIONS_VERSION
		);

		wp_enqueue_script_module(
			'cb-automations-admin-designer-shell',
			CB_AUTOMATIONS_URL . 'assets/js/admin-designer-shell.js',
			[ '@cb-core/design-editor' ],
			is_file( $js ) ? (string) filemtime( $js ) : CB_AUTOMATIONS_VERSION
		);
	}
}
