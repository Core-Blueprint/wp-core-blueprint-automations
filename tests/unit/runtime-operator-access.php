<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$builder = file_get_contents( $root . '/src/Admin/AutomationsPage.php' );
$runs = file_get_contents( $root . '/src/Admin/AutomationRunsPage.php' );
$view_cap = file_get_contents( $root . '/src/Admin/RunHistoryCapability.php' );
$recovery_cap = file_get_contents( $root . '/src/Admin/RecoveryCapability.php' );
$recovery_controller = file_get_contents( $root . '/src/Admin/OperatorRecoveryController.php' );
$workflow_controller = file_get_contents( $root . '/src/Admin/WorkflowController.php' );
$suite = file_get_contents( $root . '/src/Integration/Suite.php' );
$plugin = file_get_contents( $root . '/src/Plugin.php' );
$list = file_get_contents( $root . '/templates/admin/runs.php' );
$detail = file_get_contents( $root . '/templates/admin/run-detail.php' );
$history = file_get_contents( $root . '/src/Admin/RunHistoryReadService.php' );

if ( in_array( false, [ $builder, $runs, $view_cap, $recovery_cap, $recovery_controller, $workflow_controller, $suite, $plugin, $list, $detail, $history ], true ) ) {
	throw new RuntimeException( 'Could not read AU2.9 operator access sources.' );
}

if ( ! str_contains( $builder, "public const CAPABILITY = 'manage_options'" ) ) {
	throw new RuntimeException( 'Builder authoring capability changed from manage_options.' );
}
if ( str_contains( $builder, "'runs' === \$view" ) || str_contains( $builder, 'render_runs(' ) || str_contains( $builder, "'run-detail.php'" ) || str_contains( $builder, "'runs.php'" ) ) {
	throw new RuntimeException( 'Run History is still routed through the Builder page.' );
}
if ( ! str_contains( $workflow_controller, 'current_user_can( AutomationsPage::CAPABILITY )' ) ) {
	throw new RuntimeException( 'Direct workflow mutation no longer uses the Builder authoring boundary.' );
}
if ( ! str_contains( $runs, 'RunHistoryCapability::CAPABILITY' ) || str_contains( $runs, "CAPABILITY = 'manage_options'" ) ) {
	throw new RuntimeException( 'Automation Runs does not have an independent read-only access boundary.' );
}
if ( ! str_contains( $view_cap, "public const CAPABILITY = 'cb_view_automation_runs'" ) || ! str_contains( $view_cap, "PARENT_CAPABILITY = 'cb_view_permissions'" ) ) {
	throw new RuntimeException( 'Run-history view authority is not derived from the public Base read boundary.' );
}
if ( str_contains( $view_cap, 'cb_operator' ) || str_contains( $runs, 'cb_operator' ) || str_contains( $suite, 'cb_operator' ) ) {
	throw new RuntimeException( 'Operator access must not use role-name checks.' );
}
if ( ! str_contains( $view_cap, 'cb_core_capability_catalog' ) || ! str_contains( $view_cap, 'user_has_cap' ) ) {
	throw new RuntimeException( 'Run-history view capability is not integrated through public capability contracts.' );
}
if ( ! str_contains( $suite, 'new AutomationsPage()' ) || ! str_contains( $suite, 'new AutomationRunsPage()' ) ) {
	throw new RuntimeException( 'Builder and Runs are not registered as separate admin pages.' );
}
if ( ! str_contains( $plugin, 'RunHistoryCapability::init();' ) || ! str_contains( $plugin, 'RecoveryCapability::init();' ) ) {
	throw new RuntimeException( 'Operator capability boundaries are not bootstrapped.' );
}
if ( ! str_contains( $recovery_cap, "public const CAPABILITY = 'cb_recover_automations'" ) || ! str_contains( $recovery_cap, "PARENT_CAPABILITY = 'cb_manage_permissions'" ) ) {
	throw new RuntimeException( 'Recovery authority lost its separate PAG-protected capability boundary.' );
}
if ( ! str_contains( $recovery_controller, 'current_user_can( RecoveryCapability::CAPABILITY )' ) || ! str_contains( $recovery_controller, 'wp_verify_nonce' ) ) {
	throw new RuntimeException( 'Recovery POST lost capability or nonce authorization.' );
}
if ( ! str_contains( $recovery_controller, 'AutomationRunsPage::url(' ) ) {
	throw new RuntimeException( 'Recovery POST does not return to the operator-facing Runs page.' );
}
if ( ! str_contains( $list, 'AutomationRunsPage::url(' ) || ! str_contains( $detail, 'AutomationRunsPage::url(' ) ) {
	throw new RuntimeException( 'Run navigation still depends on the Builder route.' );
}
if ( ! str_contains( $list, 'current_user_can( \\CB\\Automations\\Admin\\AutomationsPage::CAPABILITY )' ) || ! str_contains( $detail, 'current_user_can( \\CB\\Automations\\Admin\\AutomationsPage::CAPABILITY )' ) ) {
	throw new RuntimeException( 'Builder links are not hidden from run-only operators.' );
}
if ( ! str_contains( $history, 'RunHistoryRepository::' ) || str_contains( $runs, 'RunHistoryRepository::' ) ) {
	throw new RuntimeException( 'Runs page duplicated run-history repository logic instead of reusing the read service.' );
}
foreach ( [ 'definition_json', 'context_envelope', 'payload_envelope', 'Vault::open', 'RunContextRepository' ] as $forbidden ) {
	if ( str_contains( $runs, $forbidden ) || str_contains( $list, $forbidden ) || str_contains( $detail, $forbidden ) ) {
		throw new RuntimeException( 'Operator Runs page crossed metadata-only boundary: ' . $forbidden );
	}
}
if ( str_contains( $detail, 'Retry Action' ) || ! str_contains( $detail, 'does not have recovery authority' ) ) {
	throw new RuntimeException( 'Run viewer/recovery separation regressed.' );
}

fwrite( STDOUT, "Operator Runs access closure: PASS\n" );
