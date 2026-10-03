<?php
declare(strict_types=1);

namespace CB\Automations\Integration;

use CB\Automations\Admin\AutomationRunsPage;
use CB\Automations\Admin\AutomationsPage;
use CB\Automations\Admin\RunHistoryCapability;
use CB\Automations\Support\Requirements;
use CoreBlueprint\Core\Admin\MenuGroup;
use CoreBlueprint\Core\Admin\MenuGroupRegistry;
use CoreBlueprint\Core\ExtensionRegistry;

defined( 'ABSPATH' ) || exit;

final class Suite {
	public const EXTENSION_ID = 'core-blueprint-automations';
	public const MENU_SLUG    = 'core-blueprint-automations';

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;
		add_action( 'core_blueprint_register_extensions', [ self::class, 'register_extension' ] );
		add_action( 'core_blueprint_register_pages', [ self::class, 'register_admin_pages' ] );
	}

	public static function register_extension(): void {
		if ( ! class_exists( ExtensionRegistry::class ) ) {
			return;
		}

		ExtensionRegistry::register( [
			'id'           => self::EXTENSION_ID,
			'plugin_file'  => CB_AUTOMATIONS_BASENAME,
			'requires_api' => CB_AUTOMATIONS_REQUIRED_API,
			'menu_url'     => admin_url( 'admin.php?page=' . self::MENU_SLUG ),
		] );
	}

	public static function register_admin_pages(): void {
		if ( ! Requirements::admin_ready() || ! class_exists( MenuGroupRegistry::class ) || ! class_exists( MenuGroup::class ) ) {
			return;
		}

		$workflows = new AutomationsPage();
		$runs      = new AutomationRunsPage();

		MenuGroupRegistry::register(
			new MenuGroup(
				self::MENU_SLUG,
				__( 'Automations', 'core-blueprint-automations' ),
				__( 'Automations', 'core-blueprint-automations' ),
				RunHistoryCapability::CAPABILITY,
				'dashicons-controls-repeat',
				58
			),
			[ $workflows, $runs ],
			[
				AutomationsPage::SLUG => [
					'components' => [
						'actions',
						'empty-state',
						'fields',
						'form-controls',
						'notices',
						'panels',
						'status',
					],
				],
				AutomationRunsPage::SLUG => [
					'components' => [
						'actions',
						'notices',
						'status',
					],
				],
			]
		);
	}
}
