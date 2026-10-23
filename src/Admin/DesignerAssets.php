<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CoreBlueprint\Core\Admin\MenuGroupRegistry;
use CoreBlueprint\Core\Design\Editor\Assets as DesignEditorAssets;
use CoreBlueprint\Core\UI\Assets as UIAssets;

defined( 'ABSPATH' ) || exit;

/** Automations-specific adapter assets for the public Base Designer Shell. */
final class DesignerAssets {
	private const INSPECTOR_HANDLE = 'cb-automations-admin-builder-inspector';
	private const SAVE_HANDLE      = 'cb-automations-admin-builder-save';
	private const HISTORY_MODULE   = 'cb-automations-admin-builder-history';
	private const LAYERS_MODULE    = 'cb-automations-admin-builder-layers';

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}

		self::$initialized = true;
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ], 20 );
	}

	public static function enqueue( string $hook ): void {
		if (
			! MenuGroupRegistry::is_page_hook( AutomationsPage::SLUG, $hook )
			|| AutomationsPage::requested_workflow_id() <= 0
			|| ! self::base_editor_available()
		) {
			return;
		}

		DesignEditorAssets::enqueue_designer_mode( __( 'Automation Builder', 'core-blueprint-automations' ) );
		UIAssets::enqueue_modals( 'core' );

		$css            = CB_AUTOMATIONS_DIR . 'assets/css/admin-designer-shell.css';
		$inspector_css  = CB_AUTOMATIONS_DIR . 'assets/css/admin-builder-inspector.css';
		$authoring_css  = CB_AUTOMATIONS_DIR . 'assets/css/admin-builder-authoring.css';
		$finish_css     = CB_AUTOMATIONS_DIR . 'assets/css/admin-builder-finish.css';
		$js             = CB_AUTOMATIONS_DIR . 'assets/js/admin-designer-shell.js';
		$inspector      = CB_AUTOMATIONS_DIR . 'assets/js/admin-builder-inspector.js';
		$save           = CB_AUTOMATIONS_DIR . 'assets/js/admin-builder-save.js';
		$history        = CB_AUTOMATIONS_DIR . 'assets/js/admin-builder-history.js';
		$layers         = CB_AUTOMATIONS_DIR . 'assets/js/admin-builder-layers.js';

		wp_enqueue_script(
			self::INSPECTOR_HANDLE,
			CB_AUTOMATIONS_URL . 'assets/js/admin-builder-inspector.js',
			[ 'cb-automations-admin-condition-builder' ],
			is_file( $inspector ) ? (string) filemtime( $inspector ) : CB_AUTOMATIONS_VERSION,
			true
		);
		wp_localize_script(
			self::INSPECTOR_HANDLE,
			'cbAutomationsInspectorStrings',
			[
				'inspector'    => __( 'Inspector', 'core-blueprint-automations' ),
				'properties'   => __( 'Properties', 'core-blueprint-automations' ),
				'unsavedTitle' => __( 'Unsaved changes', 'core-blueprint-automations' ),
			'unsavedChanges' => __( 'This workflow has unsaved changes. Save or discard them before switching.', 'core-blueprint-automations' ),
			'unsavedChoice' => __( 'What should happen to your changes?', 'core-blueprint-automations' ),
			'optionSave' => __( 'Save changes', 'core-blueprint-automations' ),
			'optionDiscard' => __( 'Discard changes', 'core-blueprint-automations' ),
			'switchWorkflow' => __( 'Switch workflow', 'core-blueprint-automations' ),
			'cancel' => __( 'Cancel', 'core-blueprint-automations' ),
			'closeTitle' => __( 'Close Automation Builder?', 'core-blueprint-automations' ),
			'closeDescription' => __( 'Return to Automations?', 'core-blueprint-automations' ),
			'closeUnsavedDescription' => __( 'This workflow has unsaved changes. Save or discard them before closing the Builder.', 'core-blueprint-automations' ),
			'closeBuilder' => __( 'Close Builder', 'core-blueprint-automations' ),
				'saveInProgress' => __( 'Wait for the current save to finish.', 'core-blueprint-automations' ),
				'contextUnavailable' => __( 'Workflow switching is not available. Reload the editor.', 'core-blueprint-automations' ),
				'loadFailed' => __( 'Could not load the selected workflow.', 'core-blueprint-automations' ),
				'catalogChanged' => __( 'Available capabilities changed. Reload Designer before switching workflows.', 'core-blueprint-automations' ),
				'navigation'    => [
					'ariaLabel'             => __( 'Workflow stages', 'core-blueprint-automations' ),
					'trigger'               => __( 'WHEN', 'core-blueprint-automations' ),
					'triggerDescription'    => __( 'Trigger', 'core-blueprint-automations' ),
					'states'                => __( 'GET DATA', 'core-blueprint-automations' ),
					'statesDescription'     => __( 'Live state', 'core-blueprint-automations' ),
					'conditions'            => __( 'ONLY IF', 'core-blueprint-automations' ),
					'conditionsDescription' => __( 'Conditions', 'core-blueprint-automations' ),
					'actions'               => __( 'THEN', 'core-blueprint-automations' ),
					'actionsDescription'    => __( 'Actions', 'core-blueprint-automations' ),
					'required'              => __( 'Required', 'core-blueprint-automations' ),
				],
				'selectedStep' => __( 'Selected step', 'core-blueprint-automations' ),
				'noSelection'  => __( 'No workflow step selected', 'core-blueprint-automations' ),
				'editCanvas'   => __( 'Edit this step on the workflow canvas.', 'core-blueprint-automations' ),
				'unavailable'  => __( 'This capability is unavailable. Choose a replacement on the canvas.', 'core-blueprint-automations' ),
				'technicalDetails' => __( 'Technical details', 'core-blueprint-automations' ),
				'detailsSummary' => __( 'Capability reference and workflow identifiers', 'core-blueprint-automations' ),
				'selectStep'   => __( 'Select a workflow step to inspect its context.', 'core-blueprint-automations' ),
				'selectTrigger' => __( 'Configure trigger', 'core-blueprint-automations' ),
				'stage'        => __( 'Stage', 'core-blueprint-automations' ),
				'provider'     => __( 'Provider', 'core-blueprint-automations' ),
				'capability'   => __( 'Capability', 'core-blueprint-automations' ),
				'identifier'   => __( 'Identifier', 'core-blueprint-automations' ),
				'condition'    => __( 'Condition', 'core-blueprint-automations' ),
			]
		);

		wp_enqueue_script(
			self::SAVE_HANDLE,
			CB_AUTOMATIONS_URL . 'assets/js/admin-builder-save.js',
			[ self::INSPECTOR_HANDLE ],
			is_file( $save ) ? (string) filemtime( $save ) : CB_AUTOMATIONS_VERSION,
			true
		);
		wp_localize_script(
			self::SAVE_HANDLE,
			'cbAutomationsSaveStrings',
			[
				'saving'                          => __( 'Saving automation…', 'core-blueprint-automations' ),
				'failed'                          => __( 'The automation could not be saved.', 'core-blueprint-automations' ),
				'conflictReview'                  => __( 'Review latest version in a new tab', 'core-blueprint-automations' ),
				'savedWithChanges'                => __( 'Automation saved. Newer edits are not saved yet.', 'core-blueprint-automations' ),
				'ready'                           => __( 'Ready', 'core-blueprint-automations' ),
				'needsAttention'                  => __( 'Needs attention', 'core-blueprint-automations' ),
				'validDescription'                => __( 'The current definition is valid against the live capability catalog.', 'core-blueprint-automations' ),
				'invalidDescription'              => __( 'Resolve these items before the automation can be enabled.', 'core-blueprint-automations' ),
				'workflowIssue'                   => __( 'Workflow issue', 'core-blueprint-automations' ),
				'activationEnabled'               => __( 'Enabled', 'core-blueprint-automations' ),
				'activationDisabled'              => __( 'Disabled', 'core-blueprint-automations' ),
				'validationValid'                 => __( 'Valid', 'core-blueprint-automations' ),
				'validationNeedsReview'           => __( 'Needs review', 'core-blueprint-automations' ),
				'validationDependencyUnavailable' => __( 'Dependency unavailable', 'core-blueprint-automations' ),
				'validationInvalid'               => __( 'Invalid', 'core-blueprint-automations' ),
			]
		);

		wp_enqueue_style( 'cb-automations-admin-designer-shell', CB_AUTOMATIONS_URL . 'assets/css/admin-designer-shell.css', [ 'cb-automations-admin-condition-builder' ], is_file( $css ) ? (string) filemtime( $css ) : CB_AUTOMATIONS_VERSION );
		wp_enqueue_style( 'cb-automations-admin-builder-inspector', CB_AUTOMATIONS_URL . 'assets/css/admin-builder-inspector.css', [ 'cb-automations-admin-designer-shell' ], is_file( $inspector_css ) ? (string) filemtime( $inspector_css ) : CB_AUTOMATIONS_VERSION );
		wp_enqueue_style( 'cb-automations-admin-builder-authoring', CB_AUTOMATIONS_URL . 'assets/css/admin-builder-authoring.css', [ 'cb-automations-admin-builder-inspector' ], is_file( $authoring_css ) ? (string) filemtime( $authoring_css ) : CB_AUTOMATIONS_VERSION );
		wp_enqueue_style( 'cb-automations-admin-builder-finish', CB_AUTOMATIONS_URL . 'assets/css/admin-builder-finish.css', [ 'cb-automations-admin-builder-authoring' ], is_file( $finish_css ) ? (string) filemtime( $finish_css ) : CB_AUTOMATIONS_VERSION );

		wp_enqueue_script_module( 'cb-automations-admin-designer-shell', CB_AUTOMATIONS_URL . 'assets/js/admin-designer-shell.js', [ '@cb-core/design-editor', '@cb-core/modal' ], is_file( $js ) ? (string) filemtime( $js ) : CB_AUTOMATIONS_VERSION );
		wp_enqueue_script_module( self::HISTORY_MODULE, CB_AUTOMATIONS_URL . 'assets/js/admin-builder-history.js', [ '@cb-core/design-editor', 'cb-automations-admin-designer-shell' ], is_file( $history ) ? (string) filemtime( $history ) : CB_AUTOMATIONS_VERSION );
		wp_enqueue_script_module( self::LAYERS_MODULE, CB_AUTOMATIONS_URL . 'assets/js/admin-builder-layers.js', [ '@cb-core/design-editor', self::HISTORY_MODULE ], is_file( $layers ) ? (string) filemtime( $layers ) : CB_AUTOMATIONS_VERSION );
	}

	private static function base_editor_available(): bool {
		return class_exists( DesignEditorAssets::class )
			&& is_callable( [ DesignEditorAssets::class, 'enqueue' ] )
			&& is_callable( [ DesignEditorAssets::class, 'enqueue_designer_mode' ] )
			&& defined( DesignEditorAssets::class . '::MODULE_ID' )
			&& defined( DesignEditorAssets::class . '::SHELL_STYLE' )
			// No v1/low-level-history fallback: a public, same-build Domain Session
			// v2 is required before this Designer can safely bind Undo/Save.
			&& defined( DesignEditorAssets::class . '::DOMAIN_SESSION_API' )
			&& constant( DesignEditorAssets::class . '::DOMAIN_SESSION_API' ) >= 2;
	}
}
