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

		$css          = CB_AUTOMATIONS_DIR . 'assets/css/admin-automations.css';
		$overview_css = CB_AUTOMATIONS_DIR . 'assets/css/admin-overview.css';
		$picker_css   = CB_AUTOMATIONS_DIR . 'assets/css/admin-capability-picker.css';
		$polish_css   = CB_AUTOMATIONS_DIR . 'assets/css/admin-editor-polish.css';
		$js           = CB_AUTOMATIONS_DIR . 'assets/js/admin-editor.js';
		$picker_js    = CB_AUTOMATIONS_DIR . 'assets/js/admin-capability-picker.js';
		$polish_js    = CB_AUTOMATIONS_DIR . 'assets/js/admin-editor-polish.js';

		wp_enqueue_style(
			'cb-automations-admin',
			CB_AUTOMATIONS_URL . 'assets/css/admin-automations.css',
			[],
			is_file( $css ) ? (string) filemtime( $css ) : CB_AUTOMATIONS_VERSION
		);

		wp_enqueue_style(
			'cb-automations-admin-overview',
			CB_AUTOMATIONS_URL . 'assets/css/admin-overview.css',
			[ 'cb-automations-admin' ],
			is_file( $overview_css ) ? (string) filemtime( $overview_css ) : CB_AUTOMATIONS_VERSION
		);

		wp_enqueue_style(
			'cb-automations-admin-capability-picker',
			CB_AUTOMATIONS_URL . 'assets/css/admin-capability-picker.css',
			[ 'cb-automations-admin' ],
			is_file( $picker_css ) ? (string) filemtime( $picker_css ) : CB_AUTOMATIONS_VERSION
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
			'cb-automations-admin-capability-picker',
			CB_AUTOMATIONS_URL . 'assets/js/admin-capability-picker.js',
			[ 'cb-automations-admin-editor' ],
			is_file( $picker_js ) ? (string) filemtime( $picker_js ) : CB_AUTOMATIONS_VERSION,
			true
		);

		wp_localize_script(
			'cb-automations-admin-capability-picker',
			'cbAutomationsCapabilityPickerStrings',
			[
				'choose_capability'       => __( 'Choose capability', 'core-blueprint-automations' ),
				'picker_label'            => __( 'Choose automation capability', 'core-blueprint-automations' ),
				'search_label'            => __( 'Search capabilities', 'core-blueprint-automations' ),
				'search_placeholder'      => __( 'Search by capability, provider or description…', 'core-blueprint-automations' ),
				'search_help'             => __( 'Search by capability, provider or description.', 'core-blueprint-automations' ),
				'clear_selection'         => __( 'Clear selection', 'core-blueprint-automations' ),
				'no_results'              => __( 'No capabilities match your search.', 'core-blueprint-automations' ),
				'unavailable'             => __( 'Unavailable', 'core-blueprint-automations' ),
				'unavailable_description' => __( 'This stored capability is no longer available. Choose a replacement.', 'core-blueprint-automations' ),
			]
		);

		wp_enqueue_script(
			'cb-automations-admin-editor-polish',
			CB_AUTOMATIONS_URL . 'assets/js/admin-editor-polish.js',
			[ 'cb-automations-admin-capability-picker' ],
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
