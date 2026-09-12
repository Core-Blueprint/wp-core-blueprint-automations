<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Core\Admin\MenuGroupRegistry;

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
		if ( $hook !== MenuGroupRegistry::hook_suffix( AutomationsPage::SLUG ) ) {
			return;
		}

		$css           = CB_AUTOMATIONS_DIR . 'assets/css/admin-automations.css';
		$overview_css  = CB_AUTOMATIONS_DIR . 'assets/css/admin-overview.css';
		$picker_css    = CB_AUTOMATIONS_DIR . 'assets/css/admin-capability-picker.css';
		$polish_css    = CB_AUTOMATIONS_DIR . 'assets/css/admin-editor-polish.css';
		$recovery_css  = CB_AUTOMATIONS_DIR . 'assets/css/admin-validation-recovery.css';
		$condition_css = CB_AUTOMATIONS_DIR . 'assets/css/admin-condition-builder.css';
		$js            = CB_AUTOMATIONS_DIR . 'assets/js/admin-editor.js';
		$picker_js     = CB_AUTOMATIONS_DIR . 'assets/js/admin-capability-picker.js';
		$polish_js     = CB_AUTOMATIONS_DIR . 'assets/js/admin-editor-polish.js';
		$recovery_js   = CB_AUTOMATIONS_DIR . 'assets/js/admin-validation-recovery.js';
		$condition_js  = CB_AUTOMATIONS_DIR . 'assets/js/admin-condition-builder.js';

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

		wp_enqueue_style(
			'cb-automations-admin-validation-recovery',
			CB_AUTOMATIONS_URL . 'assets/css/admin-validation-recovery.css',
			[ 'cb-automations-admin-editor-polish' ],
			is_file( $recovery_css ) ? (string) filemtime( $recovery_css ) : CB_AUTOMATIONS_VERSION
		);

		wp_enqueue_style(
			'cb-automations-admin-condition-builder',
			CB_AUTOMATIONS_URL . 'assets/css/admin-condition-builder.css',
			[ 'cb-automations-admin-validation-recovery' ],
			is_file( $condition_css ) ? (string) filemtime( $condition_css ) : CB_AUTOMATIONS_VERSION
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

		wp_enqueue_script(
			'cb-automations-admin-validation-recovery',
			CB_AUTOMATIONS_URL . 'assets/js/admin-validation-recovery.js',
			[ 'cb-automations-admin-editor-polish' ],
			is_file( $recovery_js ) ? (string) filemtime( $recovery_js ) : CB_AUTOMATIONS_VERSION,
			true
		);

		wp_localize_script(
			'cb-automations-admin-validation-recovery',
			'cbAutomationsValidationRecoveryStrings',
			[
				'workflow'           => __( 'Workflow', 'core-blueprint-automations' ),
				'when_trigger'       => __( 'When · Trigger', 'core-blueprint-automations' ),
				'get_data'           => __( 'Get data · Data lookup %d', 'core-blueprint-automations' ),
				'only_if'            => __( 'Only if · Condition %d', 'core-blueprint-automations' ),
				'then_actions'       => __( 'Then · Actions', 'core-blueprint-automations' ),
				'then_action'        => __( 'Then · Action %d', 'core-blueprint-automations' ),
				'choose_trigger'     => __( 'Choose trigger', 'core-blueprint-automations' ),
				'add_action'         => __( 'Add action', 'core-blueprint-automations' ),
				'review_trigger'     => __( 'Review trigger', 'core-blueprint-automations' ),
				'review_capability'  => __( 'Review capability', 'core-blueprint-automations' ),
				'connect_input'      => __( 'Connect input', 'core-blueprint-automations' ),
				'fix_input'          => __( 'Fix input', 'core-blueprint-automations' ),
				'choose_replacement' => __( 'Choose replacement', 'core-blueprint-automations' ),
				'review_condition'   => __( 'Review condition', 'core-blueprint-automations' ),
				'review_issue'       => __( 'Review issue', 'core-blueprint-automations' ),
				'technical_details'  => __( 'Technical details', 'core-blueprint-automations' ),
				'saved_issue'        => __( 'Saved workflow issue', 'core-blueprint-automations' ),
				'save_recheck'       => __( 'Changes made. Save the automation to re-check workflow health.', 'core-blueprint-automations' ),
			]
		);

		wp_enqueue_script(
			'cb-automations-admin-condition-builder',
			CB_AUTOMATIONS_URL . 'assets/js/admin-condition-builder.js',
			[ 'cb-automations-admin-validation-recovery' ],
			is_file( $condition_js ) ? (string) filemtime( $condition_js ) : CB_AUTOMATIONS_VERSION,
			true
		);

		wp_localize_script(
			'cb-automations-admin-condition-builder',
			'cbAutomationsConditionBuilderStrings',
			[
				'only_continue_if'             => __( 'Only continue if', 'core-blueprint-automations' ),
				'rule_preview'                 => __( 'Rule preview', 'core-blueprint-automations' ),
				'condition_value'              => __( 'Value', 'core-blueprint-automations' ),
				'condition_rule'               => __( 'Rule', 'core-blueprint-automations' ),
				'condition_compare'            => __( 'Compare with', 'core-blueprint-automations' ),
				'choose_comparison'            => __( 'Choose a value or workflow data…', 'core-blueprint-automations' ),
				'enter_value'                  => __( 'Enter a value', 'core-blueprint-automations' ),
				'workflow_data'                => __( 'Workflow data', 'core-blueprint-automations' ),
				'unavailable_data'             => __( 'Unavailable data', 'core-blueprint-automations' ),
				'type_text'                    => __( 'Text', 'core-blueprint-automations' ),
				'type_number'                  => __( 'Number', 'core-blueprint-automations' ),
				'type_boolean'                 => __( 'True / false', 'core-blueprint-automations' ),
				'type_list'                    => __( 'List', 'core-blueprint-automations' ),
				'operator_equals'              => __( 'is', 'core-blueprint-automations' ),
				'operator_not_equals'          => __( 'is not', 'core-blueprint-automations' ),
				'operator_contains'            => __( 'contains', 'core-blueprint-automations' ),
				'operator_not_contains'        => __( 'does not contain', 'core-blueprint-automations' ),
				'operator_greater_than'        => __( 'is greater than', 'core-blueprint-automations' ),
				'operator_greater_than_or_equal' => __( 'is at least', 'core-blueprint-automations' ),
				'operator_less_than'           => __( 'is less than', 'core-blueprint-automations' ),
				'operator_less_than_or_equal'  => __( 'is at most', 'core-blueprint-automations' ),
				'operator_is_empty'            => __( 'is empty', 'core-blueprint-automations' ),
				'operator_is_not_empty'        => __( 'is not empty', 'core-blueprint-automations' ),
				'operator_array_is_empty'      => __( 'has no items', 'core-blueprint-automations' ),
				'operator_array_is_not_empty'  => __( 'has items', 'core-blueprint-automations' ),
			]
		);
	}
}
