<?php
declare(strict_types=1);

namespace CB\Automations\Provider\WordPress;

defined( 'ABSPATH' ) || exit;

final class WordPressProvider {
	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'cb_core_register_automation_capabilities', [ CapabilityRegistrar::class, 'register' ] );
		TriggerListeners::init();
	}
}
