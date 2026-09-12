<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Core\Admin\PageRegistry;
defined( 'ABSPATH' ) || exit;

final class RunsAssets {
	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function enqueue( string $hook ): void {
		if ( $hook !== PageRegistry::hook_suffix( AutomationRunsPage::SLUG ) ) {
			return;
		}

		$css = CB_AUTOMATIONS_DIR . 'assets/css/admin-automations.css';
		wp_enqueue_style(
			'cb-automations-admin',
			CB_AUTOMATIONS_URL . 'assets/css/admin-automations.css',
			[],
			is_file( $css ) ? (string) filemtime( $css ) : CB_AUTOMATIONS_VERSION
		);
	}
}
