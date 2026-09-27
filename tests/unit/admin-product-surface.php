<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );

$files = [
	'suite'        => $root . '/src/Integration/Suite.php',
	'page'         => $root . '/src/Admin/AutomationsPage.php',
	'reader'       => $root . '/src/Admin/WorkflowAdminReadService.php',
	'requirements' => $root . '/src/Support/Requirements.php',
	'admin_assets' => $root . '/src/Admin/AdminAssets.php',
	'designer'     => $root . '/src/Admin/DesignerAssets.php',
	'activation'   => $root . '/src/Admin/WorkflowActivationController.php',
	'overview'     => $root . '/templates/admin/automations.php',
	'editor'       => $root . '/templates/admin/workflow-editor.php',
	'designer_css' => $root . '/assets/css/admin-designer-shell.css',
	'finish_css'   => $root . '/assets/css/admin-builder-finish.css',
];

foreach ( $files as $name => $path ) {
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "Missing {$name} source: {$path}\n" );
		exit( 1 );
	}
	$files[ $name ] = (string) file_get_contents( $path );
}

$expectations = [
	[ str_contains( $files['suite'], "public const MENU_SLUG    = 'core-blueprint-automations';" ), 'Automations must own a dedicated product-group slug.' ],
	[ str_contains( $files['page'], "public const SLUG       = 'core-blueprint-automations-workflows';" ), 'Workflows must own a screen slug distinct from the product-group slug.' ],
	[ str_contains( $files['suite'], 'MenuGroupRegistry::register(' ), 'Automations must register its product area through Base MenuGroupRegistry.' ],
	[ ! str_contains( $files['suite'], "'design-editor'" ), 'The overview page must not load the Designer foundation unconditionally.' ],
	[ ! str_contains( $files['suite'], 'PageRegistry::register(' ), 'Automations must not keep the old Core Blueprint submenu registration path.' ],
	[ str_contains( $files['requirements'], "'\\\\CB\\\\Core\\\\Admin\\\\MenuGroupRegistry'" ), 'Admin requirements must fail closed when the canonical Base menu-group contract is unavailable.' ],
	[ str_contains( $files['admin_assets'], 'MenuGroupRegistry::is_page_hook' ), 'Admin assets must honor the canonical landing and child page hooks.' ],
	[ str_contains( $files['admin_assets'], 'AutomationsPage::requested_workflow_id()' ), 'Overview and editor assets must be route-scoped.' ],
	[ str_contains( $files['designer'], 'MenuGroupRegistry::is_page_hook' ), 'Designer assets must honor the canonical landing and child page hooks.' ],
	[ str_contains( $files['designer'], 'AutomationsPage::requested_workflow_id() <= 0' ), 'Designer assets must never load on the workflow overview.' ],
	[ str_contains( $files['designer'], "enqueue_designer_mode( __( 'Automation Builder'" ), 'Designer Mode must declare the Automation Builder mode title.' ],
	[ ! str_contains( $files['designer'], 'admin-builder-bootstrap.js' ), 'Designer structure must not depend on client-side bootstrap composition.' ],
	[ str_contains( $files['overview'], "'Automation Builder'" ), 'The overview must render its Builder label server-side.' ],
	[ str_contains( $files['editor'], 'data-cb-design-shell-context' ), 'Designer workflow context must use the public Base context selector contract.' ],
	[ str_contains( $files['editor'], 'data-cb-automations-workflow-switcher' ), 'Designer workflow context must expose the Automations switcher behavior hook.' ],
	[ ! str_contains( $files['editor'], 'cb-automations-design-shell__identity' ), 'Legacy Automations Designer identity markup must remain removed.' ],
	[ ! str_contains( $files['editor'], 'cb-core-wrap' ), 'Canonical Designer routes must not depend on the Core Admin wrapper for presentation.' ],
	[ str_contains( $files['reader'], 'public function context_items(): array' ), 'Designer workflow switching must reuse the canonical admin read service.' ],
	[ str_contains( $files['reader'], 'WorkflowRepository::list( 100, $offset )' ), 'Designer workflow context must reuse canonical workflow listing persistence.' ],
	[ str_contains( $files['editor'], 'AutomationsPage::url(' ), 'Designer workflow switching must reuse the canonical workflow route builder.' ],
	[ str_contains( $files['editor'], 'data-cb-design-shell-undo' ), 'Undo must be declared in the server-rendered Designer toolbar.' ],
	[ str_contains( $files['editor'], 'data-cb-design-shell-redo' ), 'Redo must be declared in the server-rendered Designer toolbar.' ],
	[ str_contains( $files['editor'], 'data-cb-design-shell-status' ), 'Save status must be declared in the server-rendered Designer toolbar.' ],
	[ str_contains( $files['editor'], 'data-cb-design-shell-panel="inspector"' ), 'Inspector must be declared server-side before its behavior attaches.' ],
	[ str_contains( $files['editor'], 'data-cb-design-shell-primary-action' ), 'Primary Save must be declared server-side for Base header composition.' ],
	[ ! str_contains( $files['designer_css'], '.cb-automations-design-shell .cb-core-design-shell__workspace' ), 'Automations must never own the shared Designer workspace grid.' ],
	[ ! str_contains( $files['designer_css'], 'cb-automations-design-shell__identity' ), 'Automations must not restyle the Base-owned Designer identity.' ],
	[ ! str_contains( $files['activation'], 'WorkflowRepository::update' ), 'Overview activation must never update workflow state directly.' ],
	[ str_contains( $files['activation'], '$service->save(' ), 'Overview activation must use the canonical workflow save policy.' ],
	[ ! str_contains( $files['finish_css'], 'is-palette-collapsed' ), 'Legacy Automations-local palette collapse CSS must remain removed.' ],
	[ ! str_contains( $files['finish_css'], 'is-sidebar-collapsed' ), 'Legacy Automations-local sidebar collapse CSS must remain removed.' ],
];

foreach ( $expectations as [ $passed, $message ] ) {
	if ( ! $passed ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
}

foreach ( [ 'admin-builder-layout.js', 'admin-builder-bootstrap.js' ] as $legacy_script ) {
	if ( is_file( $root . '/assets/js/' . $legacy_script ) ) {
		fwrite( STDERR, "Obsolete client-side Designer structure script remains: {$legacy_script}.\n" );
		exit( 1 );
	}
}

fwrite( STDOUT, "admin-product-surface: PASS\n" );
