<?php
declare(strict_types=1);

namespace CB\Automations\Admin;
defined( 'ABSPATH' ) || exit;

final class RunHistoryCapability {
	public const CAPABILITY = 'cb_view_automation_runs';
	private const PARENT_CAPABILITY = 'cb_view_permissions';
	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_filter( 'user_has_cap', [ self::class, 'filter_user_has_cap' ], 20, 4 );
		add_filter( 'cb_core_capability_catalog', [ self::class, 'register_catalog' ] );
	}

	/**
	 * Run-history viewing is an extension-owned effective capability derived
	 * from Base's public read-only permission-governance capability. A stored
	 * cb_view_automation_runs grant alone is deliberately insufficient.
	 *
	 * @param array<string,bool> $allcaps
	 * @param string[]           $caps
	 * @param array              $args
	 * @param \WP_User           $user
	 * @return array<string,bool>
	 */
	public static function filter_user_has_cap( array $allcaps, array $caps, array $args, $user ): array {
		unset( $args, $user );
		if ( ! in_array( self::CAPABILITY, $caps, true ) ) {
			return $allcaps;
		}

		$allcaps[ self::CAPABILITY ] = ! empty( $allcaps[ self::PARENT_CAPABILITY ] );
		return $allcaps;
	}

	/** @param array<string,array<string,mixed>> $catalog @return array<string,array<string,mixed>> */
	public static function register_catalog( array $catalog ): array {
		$catalog[ self::CAPABILITY ] = [
			'label'       => __( 'View automation runs', 'core-blueprint-automations' ),
			'group'       => __( 'Core Blueprint Automations', 'core-blueprint-automations' ),
			'source'      => 'Core Blueprint Automations',
			'description' => __( 'View metadata-only automation run history and execution timelines. This does not grant workflow authoring or recovery authority.', 'core-blueprint-automations' ),
		];
		return $catalog;
	}
}
