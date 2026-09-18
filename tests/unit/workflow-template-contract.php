<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$registry = file_get_contents( $root . '/src/Template/WorkflowTemplateRegistry.php' );
$starters = file_get_contents( $root . '/src/Template/StarterTemplates.php' );
$controller = file_get_contents( $root . '/src/Admin/WorkflowController.php' );
$overview = file_get_contents( $root . '/templates/admin/automations.php' );
$plugin = file_get_contents( $root . '/src/Plugin.php' );

if ( false === $registry || false === $starters || false === $controller || false === $overview || false === $plugin ) {
	fwrite( STDERR, "Could not read workflow template sources.\n" );
	exit( 1 );
}

$failures = [];
if ( ! str_contains( $registry, "doing_action( 'cb_automations_register_workflow_templates' )" ) ) {
	$failures[] = 'Template registration is not lifecycle-gated.';
}
if ( ! str_contains( $registry, "do_action( 'cb_automations_register_workflow_templates' )" ) ) {
	$failures[] = 'Public workflow template registration lifecycle is missing.';
}
if ( ! str_contains( $registry, 'DefinitionCodec::decode' ) ) {
	$failures[] = 'Template definitions are not decoded through the canonical workflow codec.';
}
foreach ( [ 'wordpress.welcome_user', 'wordpress.notify_post_author', 'wordpress.acknowledge_comment' ] as $template_id ) {
	if ( ! str_contains( $starters, "'{$template_id}'" ) ) {
		$failures[] = 'Missing WordPress starter template: ' . $template_id;
	}
}
if ( ! str_contains( $starters, "'to' => self::output" ) ) {
	$failures[] = 'Starter mail templates must bind sensitive recipients from workflow output, not literals.';
}
if ( ! str_contains( $controller, 'admin_post_cb_automations_create_from_template' ) || ! str_contains( $controller, 'WorkflowTemplateRegistry::get' ) ) {
	$failures[] = 'Admin template instantiation route is incomplete.';
}
if ( ! str_contains( $overview, "esc_html_e( 'Use template'" ) ) {
	$failures[] = 'Template gallery action is missing from the Automations overview.';
}
if ( ! str_contains( $plugin, 'StarterTemplates::init();' ) ) {
	$failures[] = 'Starter template provider is not booted.';
}

if ( [] !== $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, $failure . PHP_EOL );
	}
	exit( 1 );
}

fwrite( STDOUT, "Workflow template foundation: PASS\n" );
