<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Core\Admin\PageRegistry;

defined( 'ABSPATH' ) || exit;

final class AdminAssets {
	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function enqueue( string $hook ): void {
		if ( $hook !== PageRegistry::hook_suffix( AutomationsPage::SLUG ) ) {
			return;
		}

		$css        = CB_AUTOMATIONS_DIR . 'assets/css/admin-automations.css';
		$polish_css = CB_AUTOMATIONS_DIR . 'assets/css/admin-editor-polish.css';
		$js         = CB_AUTOMATIONS_DIR . 'assets/js/admin-editor.js';
		$polish_js  = CB_AUTOMATIONS_DIR . 'assets/js/admin-editor-polish.js';

		wp_enqueue_style(
			'cb-automations-admin',
			CB_AUTOMATIONS_URL . 'assets/css/admin-automations.css',
			[],
			is_file( $css ) ? (string) filemtime( $css ) : CB_AUTOMATIONS_VERSION
		);

		wp_enqueue_style(
			'cb-automations-admin-editor-polish',
			CB_AUTOMATIONS_URL . 'assets/css/admin-editor-polish.css',
			[ 'cb-automations-admin' ],
			is_file( $polish_css ) ? (string) filemtime( $polish_css ) : CB_AUTOMATIONS_VERSION
		);

		wp_enqueue_script(
			'cb-automations-admin-editor',
			CB_AUTOMATIONS_URL . 'assets/js/admin-editor.js',
			[],
			is_file( $js ) ? (string) filemtime( $js ) : CB_AUTOMATIONS_VERSION,
			true
		);

		wp_enqueue_script(
			'cb-automations-admin-editor-polish',
			CB_AUTOMATIONS_URL . 'assets/js/admin-editor-polish.js',
			[ 'cb-automations-admin-editor' ],
			is_file( $polish_js ) ? (string) filemtime( $polish_js ) : CB_AUTOMATIONS_VERSION,
			true
		);

		wp_localize_script(
			'cb-automations-admin-editor-polish',
			'cbAutomationsEditorPolishStrings',
			[
				'advanced_details'    => __( 'Advanced details', 'core-blueprint-automations' ),
				'capability_id'       => __( 'Capability ID', 'core-blueprint-automations' ),
				'schema_version'      => __( 'Schema version', 'core-blueprint-automations' ),
				'step_id'             => __( 'Step ID', 'core-blueprint-automations' ),
				'condition_id'        => __( 'Condition ID', 'core-blueprint-automations' ),
				'outputs'             => __( 'Outputs', 'core-blueprint-automations' ),
				'condition'           => __( 'Condition', 'core-blueprint-automations' ),
				'reads_as'            => __( 'Reads as', 'core-blueprint-automations' ),
				'required_capability' => __( 'Required capability', 'core-blueprint-automations' ),
			]
		);
	}
}
