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
use CB\Automations\Admin\SchemaSetupController;
use CB\Automations\Admin\WorkflowController;
use CB\Automations\Admin\WorkflowFolderController;
use CB\Automations\Admin\WorkflowTransferController;
use CB\Automations\Persistence\Schema;
use CB\Automations\Provider\WordPress\WordPressProvider;
use CB\Automations\Runtime\RuntimeWorker;
use CB\Automations\Runtime\TriggerIntake;
use CB\Automations\Support\Requirements;
use CB\Automations\Template\StarterTemplates;
use CB\Automations\Template\StoredTemplateRepository;
use CB\Automations\Template\TemplateLibraryBridge;
use CB\Automations\Admin\TemplateManagementController;
defined( 'ABSPATH' ) || exit;

final class Plugin {
	private static bool $runtime_booted = false;
	private static bool $admin_booted = false;
	private static ?string $boot_error = null;

	public static function boot(): void {
		if ( self::$runtime_booted || null !== self::$boot_error ) {
			return;
		}
		if ( ! Requirements::product_ready() ) {
			return;
		}

		// Always expose the privileged, site-scoped setup controller on HOLD.
		if ( is_admin() ) {
			SchemaSetupController::init();
		}
		// Capability discovery and disabled draft authoring do not use the queue.
		StoredTemplateRepository::register();
		WordPressProvider::init();
		StarterTemplates::init();
		TemplateLibraryBridge::init();
		WorkflowTransferController::init_maintenance();

		// Never register runtime workers or trigger intake without full execution readiness.
		if ( Requirements::execution_ready() ) {
			self::$runtime_booted = true;
			RuntimeWorker::init();
			TriggerIntake::init();
		} else {
			self::$boot_error = 'execution';
		}

		if ( ! is_admin() || ! Requirements::admin_ready() ) {
			return;
		}

		self::$admin_booted = true;
		RunHistoryCapability::init();
		RecoveryCapability::init();
		OperatorRecoveryController::init();
		WorkflowController::init();
		TemplateManagementController::init();
		WorkflowFolderController::init();
		WorkflowTransferController::init();
		WorkflowActivationController::init();
		WorkflowDeletionController::init();
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
