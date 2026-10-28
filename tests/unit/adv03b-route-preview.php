<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$controller = file_get_contents( $root . '/src/Admin/AdvancedRoutePreviewController.php' );
$plugin = file_get_contents( $root . '/src/Plugin.php' );
$template = file_get_contents( $root . '/templates/admin/advanced-workflow-editor.php' );
if ( ! is_string( $controller ) || ! is_string( $plugin ) || ! is_string( $template ) ) {
	throw new RuntimeException( 'Missing ADV-03B preview source.' );
}
foreach ( [
	'admin_post_cb_automations_preview_advanced',
	'wp_verify_nonce(', 'AutomationsPage::CAPABILITY',
	'ActivationState::Disabled', 'hash_equals(',
	'new GraphValidator(', 'new GraphRoutePlanner(',
	'->trace(', 'wp_send_json_success(',
	'! empty( $spec[\'sensitive\'] )',
] as $required ) {
	if ( ! str_contains( $controller, $required ) ) throw new RuntimeException( 'ADV-03B preview missing fence: ' . $required );
}
foreach ( [ 'GraphSandboxAdmission', 'GraphProviderExecutor', 'GraphRunPipeline', 'RunRepository', 'JobRepository', 'START TRANSACTION', 'trigger_intake' ] as $forbidden ) {
	if ( str_contains( $controller, $forbidden ) ) throw new RuntimeException( 'ADV-03B preview crossed runtime boundary: ' . $forbidden );
}
if ( ! str_contains( $plugin, 'AdvancedRoutePreviewController::init();' )
	|| ! str_contains( $template, "wp_create_nonce( 'cb_automations_preview_advanced' )" ) ) {
	throw new RuntimeException( 'ADV-03B preview is not registered as a protected UI-only action.' );
}
echo "ADV-03B read-only route simulation safety contract: PASS\n";