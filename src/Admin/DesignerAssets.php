<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Core\Admin\MenuGroupRegistry;
use CB\Core\Design\Editor\Assets as DesignEditorAssets;

defined( 'ABSPATH' ) || exit;

/** Automations-specific adapter assets for the public Base Designer Shell. */
final class DesignerAssets {
	private const INSPECTOR_HANDLE = 'cb-automations-admin-builder-inspector';
	private const SAVE_HANDLE      = 'cb-automations-admin-builder-save';
	private const HISTORY_MODULE   = 'cb-automations-admin-builder-history';

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

		$css            = CB_AUTOMATIONS_DIR . 'assets/css/admin-designer-shell.css';
		$inspector_css  = CB_AUTOMATIONS_DIR . 'assets/css/admin-builder-inspector.css';
		$authoring_css  = CB_AUTOMATIONS_DIR . 'assets/css/admin-builder-authoring.css';
		$finish_css     = CB_AUTOMATIONS_DIR . 'assets/css/admin-builder-finish.css';
		$js             = CB_AUTOMATIONS_DIR . 'assets/js/admin-designer-shell.js';
		$inspector      = CB_AUTOMATIONS_DIR . 'assets/js/admin-builder-inspector.js';
		$save           = CB_AUTOMATIONS_DIR . 'assets/js/admin-builder-save.js';
		$history        = CB_AUTOMATIONS_DIR . 'assets/js/admin-builder-history.js';

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
				'selectedStep' => __( 'Selected step', 'core-blueprint-automations' ),
				'selectStep'   => __( 'Select a workflow step to inspect its context.', 'core-blueprint-automations' ),
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
				'saving'             => __( 'Saving automation…', 'core-blueprint-automations' ),
				'failed'             => __( 'The automation could not be saved.', 'core-blueprint-automations' ),
				'savedWithChanges'   => __( 'Automation saved. Newer edits are not saved yet.', 'core-blueprint-automations' ),
				'ready'              => __( 'Ready', 'core-blueprint-automations' ),
				'needsAttention'     => __( 'Needs attention', 'core-blueprint-automations' ),
				'validDescription'   => __( 'The current definition is valid against the live capability catalog.', 'core-blueprint-automations' ),
				'invalidDescription' => __( 'Resolve these items before the automation can be enabled.', 'core-blueprint-automations' ),
				'workflowIssue'      => __( 'Workflow issue', 'core-blueprint-automations' ),
			]
		);

		wp_enqueue_style( 'cb-automations-admin-designer-shell', CB_AUTOMATIONS_URL . 'assets/css/admin-designer-shell.css', [ 'cb-automations-admin-condition-builder' ], is_file( $css ) ? (string) filemtime( $css ) : CB_AUTOMATIONS_VERSION );
		wp_enqueue_style( 'cb-automations-admin-builder-inspector', CB_AUTOMATIONS_URL . 'assets/css/admin-builder-inspector.css', [ 'cb-automations-admin-designer-shell' ], is_file( $inspector_css ) ? (string) filemtime( $inspector_css ) : CB_AUTOMATIONS_VERSION );
		wp_enqueue_style( 'cb-automations-admin-builder-authoring', CB_AUTOMATIONS_URL . 'assets/css/admin-builder-authoring.css', [ 'cb-automations-admin-builder-inspector' ], is_file( $authoring_css ) ? (string) filemtime( $authoring_css ) : CB_AUTOMATIONS_VERSION );
		wp_enqueue_style( 'cb-automations-admin-builder-finish', CB_AUTOMATIONS_URL . 'assets/css/admin-builder-finish.css', [ 'cb-automations-admin-builder-authoring' ], is_file( $finish_css ) ? (string) filemtime( $finish_css ) : CB_AUTOMATIONS_VERSION );

		wp_enqueue_script_module( 'cb-automations-admin-designer-shell', CB_AUTOMATIONS_URL . 'assets/js/admin-designer-shell.js', [ '@cb-core/design-editor' ], is_file( $js ) ? (string) filemtime( $js ) : CB_AUTOMATIONS_VERSION );
		wp_enqueue_script_module( self::HISTORY_MODULE, CB_AUTOMATIONS_URL . 'assets/js/admin-builder-history.js', [ '@cb-core/design-editor', 'cb-automations-admin-designer-shell' ], is_file( $history ) ? (string) filemtime( $history ) : CB_AUTOMATIONS_VERSION );
	}

	private static function base_editor_available(): bool {
		return class_exists( DesignEditorAssets::class )
			&& is_callable( [ DesignEditorAssets::class, 'enqueue' ] )
			&& is_callable( [ DesignEditorAssets::class, 'enqueue_designer_mode' ] )
			&& defined( DesignEditorAssets::class . '::MODULE_ID' )
			&& defined( DesignEditorAssets::class . '::SHELL_STYLE' );
	}
}
