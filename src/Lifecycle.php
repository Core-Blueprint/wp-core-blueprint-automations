<?php
declare(strict_types=1);

namespace CB\Automations;

use CB\Automations\Persistence\Schema;
use CB\Automations\Support\Requirements;

defined( 'ABSPATH' ) || exit;

final class Lifecycle {
	public static function activate(): void {
		if ( ! Requirements::runtime_ready() ) {
			self::fail_activation( Requirements::activation_message() );
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
			esc_html( 'Core Blueprint requirements not met' ),
			[
				'link_url'  => admin_url( 'plugins.php' ),
				'link_text' => 'Plugins',
			]
		);
	}
}
