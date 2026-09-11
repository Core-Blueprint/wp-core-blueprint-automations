<?php
declare(strict_types=1);

namespace CB\Automations\Integration;

use CB\Automations\Admin\AutomationsPage;
use CB\Automations\Support\Requirements;
use CB\Core\Admin\PageRegistry;
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
		add_action( 'cb_core_register_pages', [ self::class, 'register_admin_page' ] );
	}

	public static function register_extension(): void {
		if ( ! class_exists( ExtensionRegistry::class ) ) {
			return;
		}

		ExtensionRegistry::register( [
			'id'           => self::EXTENSION_ID,
			'plugin_file'  => CB_AUTOMATIONS_BASENAME,
			'requires_api' => CB_AUTOMATIONS_REQUIRED_API,
			'menu_url'     => admin_url( 'admin.php?page=' . self::EXTENSION_ID ),
		] );
	}

	public static function register_admin_page(): void {
		if ( ! Requirements::runtime_ready() || ! class_exists( PageRegistry::class ) ) {
			return;
		}

		PageRegistry::register(
			new AutomationsPage(),
			[
				'foundations' => [
					'design-editor',
				],
				'components' => [
					'actions',
					'empty-state',
					'fields',
					'form-controls',
					'notices',
					'panels',
					'status',
				],
			]
		);
	}
}
