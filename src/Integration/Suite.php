<?php
declare(strict_types=1);

namespace CB\Automations\Integration;

use CB\Core\ExtensionRegistry;

defined( 'ABSPATH' ) || exit;

final class Suite {
	public const EXTENSION_ID = 'core-blueprint-automations';

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;
		add_action( 'cb_core_register_extensions', [ self::class, 'register_extension' ] );
	}

	public static function register_extension(): void {
		if ( ! class_exists( ExtensionRegistry::class ) ) {
			return;
		}

		ExtensionRegistry::register( [
			'id'           => self::EXTENSION_ID,
			'plugin_file'  => CB_AUTOMATIONS_BASENAME,
			'requires_api' => CB_AUTOMATIONS_REQUIRED_API,
		] );
	}
}
