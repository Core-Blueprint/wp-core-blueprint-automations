<?php
declare(strict_types=1);

namespace CB\Automations;

use CB\Automations\Support\Requirements;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted || ! Requirements::runtime_ready() ) {
			return;
		}

		self::$booted = true;
	}

	public static function is_booted(): bool {
		return self::$booted;
	}
}
