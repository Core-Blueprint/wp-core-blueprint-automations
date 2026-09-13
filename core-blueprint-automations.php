<?php
/**
 * Plugin Name:       Core Blueprint Automations
 * Plugin URI:        https://coreblueprint.io
 * Description:       Workflow orchestration for Core Blueprint capabilities.
 * Version:           1.0.0-rc1
 * Author:            Core Blueprint
 * Author URI:        https://coreblueprint.io
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       core-blueprint-automations
 * Domain Path:       /languages
 * Requires at least: 7.0
 * Requires PHP:      8.4
 *
 * @package CB_Automations
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( defined( 'CB_AUTOMATIONS_FILE' ) ) {
	return;
}

define( 'CB_AUTOMATIONS_VERSION', '1.0.0-rc1' );
define( 'CB_AUTOMATIONS_DB_VERSION', '2' );
define( 'CB_AUTOMATIONS_REQUIRED_API', '1.0' );
define( 'CB_AUTOMATIONS_FILE', __FILE__ );
define( 'CB_AUTOMATIONS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CB_AUTOMATIONS_URL', plugin_dir_url( __FILE__ ) );
define( 'CB_AUTOMATIONS_BASENAME', plugin_basename( __FILE__ ) );

if ( version_compare( PHP_VERSION, '8.4', '<' ) ) {
	register_activation_hook( __FILE__, static function (): void {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html( sprintf( 'Core Blueprint Automations requires PHP 8.4 or newer. This server runs PHP %s.', PHP_VERSION ) ),
			esc_html( 'Core Blueprint dependency required' ),
			[
				'link_url'  => admin_url( 'plugins.php' ),
				'link_text' => 'Plugins',
			]
		);
	} );

	add_action( 'admin_notices', static function (): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html( 'Core Blueprint Automations:' ),
			esc_html( sprintf( 'PHP 8.4 or newer is required. This server runs PHP %s.', PHP_VERSION ) )
		);
	} );
	return;
}

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\Automations\\';
	$length = strlen( $prefix );
	if ( 0 !== strncmp( $class, $prefix, $length ) ) {
		return;
	}

	$relative = substr( $class, $length );
	$file     = CB_AUTOMATIONS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );

register_activation_hook( __FILE__, [ \CB\Automations\Lifecycle::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \CB\Automations\Lifecycle::class, 'deactivate' ] );

/* Register lightweight suite identity only after canonical Bootstrap readiness. */
add_action( 'plugins_loaded', static function (): void {
	if ( \CB\Automations\Support\Requirements::runtime_ready() ) {
		\CB\Automations\Integration\Suite::init();
	}
}, 1 );

add_action( 'init', static function (): void {
	load_plugin_textdomain(
		'core-blueprint-automations',
		false,
		dirname( CB_AUTOMATIONS_BASENAME ) . '/languages'
	);
}, 0 );

/*
 * Boot the request-neutral product runtime after WordPress `init` begins.
 * Admin-only controllers and assets are gated separately inside Plugin::boot().
 */
add_action( 'init', [ \CB\Automations\Plugin::class, 'boot' ], 1 );

/*
 * Keep admin UX inert when its Base contracts are unavailable while allowing
 * request-neutral runtime readiness to remain a separate concern.
 */
if ( is_admin() ) {
	add_action( 'admin_notices', static function (): void {
		if ( \CB\Automations\Support\Requirements::admin_ready() ) {
			return;
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Core Blueprint Automations:', 'core-blueprint-automations' ),
			esc_html( \CB\Automations\Support\Requirements::operator_message() )
		);
	} );
}
