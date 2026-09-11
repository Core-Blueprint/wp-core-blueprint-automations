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
define( 'CB_AUTOMATIONS_DB_VERSION', '1' );
define( 'CB_AUTOMATIONS_REQUIRED_API', '1.0' );
define( 'CB_AUTOMATIONS_FILE', __FILE__ );
define( 'CB_AUTOMATIONS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CB_AUTOMATIONS_URL', plugin_dir_url( __FILE__ ) );
define( 'CB_AUTOMATIONS_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\Automations\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$file     = CB_AUTOMATIONS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );

/* Register suite identity before product runtime gating. */
\CB\Automations\Integration\Suite::init();

register_activation_hook( __FILE__, [ \CB\Automations\Lifecycle::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \CB\Automations\Lifecycle::class, 'deactivate' ] );

add_action( 'init', static function (): void {
	load_plugin_textdomain(
		'core-blueprint-automations',
		false,
		dirname( CB_AUTOMATIONS_BASENAME ) . '/languages'
	);
}, 0 );

/* Base emits this after its public services are ready. */
add_action( 'cb_core_booted', [ \CB\Automations\Plugin::class, 'boot' ] );

/* Keep the product inert when Base or the Automation Foundation is unavailable. */
add_action( 'plugins_loaded', static function (): void {
	if ( \CB\Automations\Support\Requirements::runtime_ready() || ! is_admin() ) {
		return;
	}

	add_action( 'admin_notices', static function (): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Core Blueprint Automations:', 'core-blueprint-automations' ),
			esc_html( \CB\Automations\Support\Requirements::operator_message() )
		);
	} );
}, 30 );
