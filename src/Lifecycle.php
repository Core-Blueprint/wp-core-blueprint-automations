<?php
declare(strict_types=1);

namespace CB\Automations;

use CB\Automations\Persistence\Schema;
use CB\Automations\Support\Requirements;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {
	public static function activate(): void {
		if ( ! Requirements::runtime_ready() ) {
			self::fail_activation(
				'Core Blueprint Automations requires PHP 8.4 and an active, Core API 1.x compatible Core Blueprint Base installation that provides the Automation Foundation.'
			);
		}

		try {
			Schema::activate();
		} catch ( \Throwable $error ) {
			error_log( '[Core Blueprint Automations] Persistence schema initialization failed: ' . $error->getMessage() );
			self::fail_activation( 'Core Blueprint Automations could not initialize its persistence schema.' );
		}
	}

	public static function deactivate(): void {
		/* Workflow definitions are persistent product data and remain intact. */
	}

	private static function fail_activation( string $message ): never {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		deactivate_plugins( CB_AUTOMATIONS_BASENAME );

		wp_die(
			esc_html( $message ),
			esc_html( 'Core Blueprint dependency required' ),
			[ 'back_link' => true ]
		);
	}
}
