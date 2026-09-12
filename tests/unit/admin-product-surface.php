<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );

$files = [
	'suite'       => $root . '/src/Integration/Suite.php',
	'requirements'=> $root . '/src/Support/Requirements.php',
	'designer'    => $root . '/src/Admin/DesignerAssets.php',
	'activation'  => $root . '/src/Admin/WorkflowActivationController.php',
	'template'    => $root . '/templates/admin/automations.php',
	'finish_css'  => $root . '/assets/css/admin-builder-finish.css',
];

foreach ( $files as $name => $path ) {
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "Missing {$name} source: {$path}\n" );
		exit( 1 );
	}
	$files[ $name ] = (string) file_get_contents( $path );
}

$expectations = [
	[ str_contains( $files['suite'], 'MenuGroupRegistry::register(' ), 'Automations must register its product area through Base MenuGroupRegistry.' ],
	[ str_contains( $files['suite'], 'new MenuGroup(' ), 'Automations must declare a Base-owned top-level product menu group.' ],
	[ ! str_contains( $files['suite'], 'PageRegistry::register(' ), 'Automations must not keep the old Core Blueprint submenu registration path.' ],
	[ str_contains( $files['requirements'], "'\\\\CB\\\\Core\\\\Admin\\\\MenuGroupRegistry'" ), 'Admin requirements must fail closed when the canonical Base menu-group contract is unavailable.' ],
	[ str_contains( $files['designer'], "enqueue_designer_mode( __( 'Automation Builder'" ), 'Designer Mode must declare the Automation Builder mode title.' ],
	[ ! str_contains( $files['designer'], 'admin-builder-layout.js' ), 'Automations must not enqueue its removed local pane-collapse implementation.' ],
	[ str_contains( $files['activation'], 'new WorkflowService()' ), 'Overview activation must route through WorkflowService.' ],
	[ str_contains( $files['activation'], '$service->save(' ), 'Overview activation must use the canonical workflow save policy.' ],
	[ ! str_contains( $files['activation'], 'WorkflowRepository::update' ), 'Overview activation must never update workflow state directly.' ],
	[ str_contains( $files['template'], 'cb_automations_toggle_workflow' ), 'Workflow cards must expose the canonical activation POST action.' ],
	[ str_contains( $files['template'], 'WorkflowActivationController::nonce_action' ), 'Workflow card activation must be nonce-protected.' ],
	[ ! str_contains( $files['finish_css'], 'is-palette-collapsed' ), 'Legacy Automations-local palette collapse CSS must be removed.' ],
	[ ! str_contains( $files['finish_css'], 'is-sidebar-collapsed' ), 'Legacy Automations-local sidebar collapse CSS must be removed.' ],
];

foreach ( $expectations as [ $passed, $message ] ) {
	if ( ! $passed ) {
		fwrite( STDERR, $message . PHP_EOL );
		exit( 1 );
	}
}

if ( is_file( $root . '/assets/js/admin-builder-layout.js' ) ) {
	fwrite( STDERR, "Legacy Automations-local admin-builder-layout.js must be removed.\n" );
	exit( 1 );
}

fwrite( STDOUT, "admin-product-surface: PASS\n" );
