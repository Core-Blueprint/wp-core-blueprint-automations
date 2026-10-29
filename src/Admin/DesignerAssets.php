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
	private const DOMAIN_PROJECT_MODULE = 'cb-automations-domain-project';
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
		) {
			return;
		}
		if ( ! self::base_editor_available() ) {
			// Fail closed with a translated administrator-facing explanation.
			add_action( 'admin_notices', static function (): void {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'Workflow switching is not available. Reload the editor.', 'core-blueprint-automations' );
				echo '</p></div>';
			} );
			return;
		}

		// The Advanced canvas is a separate, typed v2 surface. Never mount the
		// Standard Domain Session, Save adapter or its JavaScript on v2 drafts.
		try {
			$record = \CB\Automations\Persistence\WorkflowRepository::find( AutomationsPage::requested_workflow_id() );
			if ( null !== $record && $record->definition() instanceof \CB\Automations\Workflow\GraphDefinition ) {
				// R3 ESM imports require both optional public Base capabilities
				// from the SAME installed Base package. Fail closed, never import
				// unavailable named exports into the fullscreen Designer.
				if ( ! self::base_canvas_available() ) {
					add_action( 'admin_notices', static function (): void {
						echo '<div class="notice notice-error"><p>';
						echo esc_html( 'Advanced canvas interactions require the matching Core Blueprint Base development build.' );
						echo '</p></div>';
					} );
					return;
				}
				DesignEditorAssets::enqueue_designer_mode( __( 'Automation Builder', 'core-blueprint-automations' ) );
				$advanced_css = CB_AUTOMATIONS_DIR . 'assets/css/admin-advanced-editor.css';
				$advanced_js = CB_AUTOMATIONS_DIR . 'assets/js/admin-advanced-editor.js';
				wp_enqueue_style( 'cb-automations-advanced-editor', CB_AUTOMATIONS_URL . 'assets/css/admin-advanced-editor.css',
					[ 'cb-automations-admin' ], is_file( $advanced_css ) ? (string) filemtime( $advanced_css ) : CB_AUTOMATIONS_VERSION );
				wp_enqueue_script( 'cb-automations-advanced-editor', CB_AUTOMATIONS_URL . 'assets/js/admin-advanced-editor.js',
					[], is_file( $advanced_js ) ? (string) filemtime( $advanced_js ) : CB_AUTOMATIONS_VERSION, true );
				$advanced_shell = CB_AUTOMATIONS_DIR . 'assets/js/admin-advanced-designer-shell.js';
				wp_enqueue_script_module(
					'cb-automations-advanced-designer-shell',
					CB_AUTOMATIONS_URL . 'assets/js/admin-advanced-designer-shell.js',
					[ '@cb-core/design-editor' ],
					is_file( $advanced_shell ) ? (string) filemtime( $advanced_shell ) : CB_AUTOMATIONS_VERSION
				);
				return;
			}
		} catch ( \Throwable ) {
			// A broken v2 read must not cause a partial Standard editor mount.
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
		$domain_project = CB_AUTOMATIONS_DIR . 'assets/js/automations-domain-project.js';
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

		wp_enqueue_script_module( 'cb-automations-admin-designer-shell', CB_AUTOMATIONS_URL . 'assets/js/admin-designer-shell.js', [ '@cb-core/design-editor', '@cb-core/toast' ], is_file( $js ) ? (string) filemtime( $js ) : CB_AUTOMATIONS_VERSION );
		// Import this consumer-owned domain codec through WordPress' versioned
		// script-module registry. A bare relative import would retain a stale,
		// unversioned browser cache entry after RC2 package replacement.
		wp_enqueue_script_module( self::DOMAIN_PROJECT_MODULE, CB_AUTOMATIONS_URL . 'assets/js/automations-domain-project.js', [], is_file( $domain_project ) ? (string) filemtime( $domain_project ) : CB_AUTOMATIONS_VERSION );
		wp_enqueue_script_module( self::HISTORY_MODULE, CB_AUTOMATIONS_URL . 'assets/js/admin-builder-history.js', [ '@cb-core/design-editor', 'cb-automations-admin-designer-shell', self::DOMAIN_PROJECT_MODULE ], is_file( $history ) ? (string) filemtime( $history ) : CB_AUTOMATIONS_VERSION );
		wp_enqueue_script_module( self::LAYERS_MODULE, CB_AUTOMATIONS_URL . 'assets/js/admin-builder-layers.js', [ '@cb-core/design-editor', self::HISTORY_MODULE ], is_file( $layers ) ? (string) filemtime( $layers ) : CB_AUTOMATIONS_VERSION );
	}

	private static function base_canvas_available(): bool {
		return defined( DesignEditorAssets::class . '::CANVAS_NAVIGATION_API' )
			&& constant( DesignEditorAssets::class . '::CANVAS_NAVIGATION_API' ) >= 2
			&& defined( DesignEditorAssets::class . '::DESIGNER_CONTEXT_MENU_API' )
			&& constant( DesignEditorAssets::class . '::DESIGNER_CONTEXT_MENU_API' ) >= 1
			&& defined( DesignEditorAssets::class . '::DOMAIN_SESSION_API' )
			&& constant( DesignEditorAssets::class . '::DOMAIN_SESSION_API' ) >= 3;
	}

	private static function base_editor_available(): bool {
		return class_exists( DesignEditorAssets::class )
			&& is_callable( [ DesignEditorAssets::class, 'enqueue' ] )
			&& is_callable( [ DesignEditorAssets::class, 'enqueue_designer_mode' ] )
			&& defined( DesignEditorAssets::class . '::MODULE_ID' )
			&& defined( DesignEditorAssets::class . '::SHELL_STYLE' )
			// B2 typed Selection requires the same installed Base ESM and API v3.
			// Never fall back to legacy local Layers/Inspector selection.
			&& defined( DesignEditorAssets::class . '::DOMAIN_SESSION_API' )
			&& constant( DesignEditorAssets::class . '::DOMAIN_SESSION_API' ) >= 3
			// Base B1 owns context authorization. Older Exit Guard contracts fail closed.
			&& defined( DesignEditorAssets::class . '::DESIGNER_EXIT_GUARD_API' )
			&& constant( DesignEditorAssets::class . '::DESIGNER_EXIT_GUARD_API' ) >= 2;
	}
}
