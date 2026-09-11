<?php
declare(strict_types=1);

namespace CB\Automations;

use CB\Automations\Admin\AdminAssets;
use CB\Automations\Admin\WorkflowController;
use CB\Automations\Persistence\Schema;
use CB\Automations\Support\Requirements;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static bool $booted = false;
	private static ?string $boot_error = null;

	public static function boot(): void {
		if ( self::$booted || null !== self::$boot_error || ! Requirements::runtime_ready() ) {
			return;
		}

		try {
			Schema::maybe_upgrade();
		} catch ( \Throwable $error ) {
			self::$boot_error = 'persistence';
			error_log( '[Core Blueprint Automations] Persistence initialization failed: ' . $error->getMessage() );
			return;
		}

		self::$booted = true;

		if ( is_admin() ) {
			WorkflowController::init();
			AdminAssets::init();
		}
	}

	public static function is_booted(): bool {
		return self::$booted;
	}

	public static function boot_error(): ?string {
		return self::$boot_error;
	}
}
