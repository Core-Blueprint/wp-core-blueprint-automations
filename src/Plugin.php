<?php
declare(strict_types=1);

namespace CB\Automations;

use CB\Automations\Admin\AdminAssets;
use CB\Automations\Admin\DesignerAssets;
use CB\Automations\Admin\OperatorRecoveryController;
use CB\Automations\Admin\RecoveryCapability;
use CB\Automations\Admin\RunHistoryCapability;
use CB\Automations\Admin\RunsAssets;
use CB\Automations\Admin\WorkflowActivationController;
use CB\Automations\Admin\WorkflowController;
use CB\Automations\Persistence\Schema;
use CB\Automations\Runtime\RuntimeWorker;
use CB\Automations\Runtime\TriggerIntake;
use CB\Automations\Support\Requirements;
defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static bool $runtime_booted = false;
	private static bool $admin_booted = false;
	private static ?string $boot_error = null;

	public static function boot(): void {
		if ( self::$runtime_booted || null !== self::$boot_error ) {
			return;
		}
		if ( ! Requirements::runtime_ready() ) {
			return;
		}

		try {
			Schema::maybe_upgrade();
		} catch ( \Throwable $error ) {
			self::$boot_error = 'persistence';
			error_log( '[Core Blueprint Automations] Persistence initialization failed: ' . $error->getMessage() );
			return;
		}

		self::$runtime_booted = true;
		RuntimeWorker::init();
		TriggerIntake::init();

		if ( ! is_admin() || ! Requirements::admin_ready() ) {
			return;
		}

		self::$admin_booted = true;
		RunHistoryCapability::init();
		RecoveryCapability::init();
		OperatorRecoveryController::init();
		WorkflowController::init();
		WorkflowActivationController::init();
		AdminAssets::init();
		RunsAssets::init();
		DesignerAssets::init();
	}

	/** Existing product boot status now means the request-neutral runtime booted. */
	public static function is_booted(): bool {
		return self::$runtime_booted;
	}

	public static function is_runtime_booted(): bool {
		return self::$runtime_booted;
	}

	public static function is_admin_booted(): bool {
		return self::$admin_booted;
	}

	public static function boot_error(): ?string {
		return self::$boot_error;
	}
}
