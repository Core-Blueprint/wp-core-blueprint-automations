<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

defined( 'ABSPATH' ) || exit;

/** ADV-02C C5: explicit opt-in; this build never permits production v2 runs. */
final class GraphRuntimeGate {
	public static function enabled(): bool {
		return defined( 'CB_AUTOMATIONS_ADVANCED_SANDBOX' )
			&& true === constant( 'CB_AUTOMATIONS_ADVANCED_SANDBOX' )
			&& function_exists( 'wp_get_environment_type' )
			&& in_array( wp_get_environment_type(), [ 'local', 'development', 'staging' ], true );
	}

	public static function require_cli(): void {
		if ( ! self::enabled()
			|| ! defined( 'WP_CLI' ) || true !== constant( 'WP_CLI' )
			|| ! function_exists( 'get_current_user_id' )
			|| ! user_can( get_current_user_id(), 'manage_options' ) ) {
			throw new \RuntimeException( 'Advanced sandbox admission is unavailable.' );
		}
	}
}