<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$repo = file_get_contents( $root . '/src/Persistence/RunHistoryRepository.php' );
$service = file_get_contents( $root . '/src/Admin/RunHistoryReadService.php' );
$page = file_get_contents( $root . '/src/Admin/AutomationRunsPage.php' );
$list = file_get_contents( $root . '/templates/admin/runs.php' );
$detail = file_get_contents( $root . '/templates/admin/run-detail.php' );
if ( in_array( false, [ $repo, $service, $page, $list, $detail ], true ) ) { throw new RuntimeException( 'Could not read runtime observability sources.' ); }

foreach ( [ 'definition_json', 'context_envelope', 'event_envelope', 'payload_envelope', 'RunContextRepository', 'Vault::open' ] as $forbidden ) {
	if ( str_contains( $repo, $forbidden ) || str_contains( $service, $forbidden ) || str_contains( $list, $forbidden ) || str_contains( $detail, $forbidden ) ) {
		throw new RuntimeException( 'Run history crossed privacy boundary: ' . $forbidden );
	}
}
foreach ( [ 'workflow_revision', 'execution_principal_user_id', 'failure_code', 'created_at', 'started_at', 'finished_at' ] as $required ) {
	if ( ! str_contains( $repo, $required ) ) { throw new RuntimeException( 'Run history lost operational metadata: ' . $required ); }
}
foreach ( [ 'node_id', 'node_type', 'provider', 'capability_id', 'schema_version', 'attempt', 'provider_error_code' ] as $required ) {
	if ( ! str_contains( $repo, $required ) ) { throw new RuntimeException( 'Step history lost operational metadata: ' . $required ); }
}
if ( ! str_contains( $page, 'final class AutomationRunsPage' ) || ! str_contains( $page, "'run-detail.php'" ) || ! str_contains( $page, "'runs.php'" ) || ! str_contains( $page, 'RunHistoryCapability::CAPABILITY' ) ) {
	throw new RuntimeException( 'Read-only run history is not routed through the dedicated Automations Runs page.' );
}
if ( ! str_contains( $detail, 'Action outcome unknown.' ) || ! str_contains( $detail, 'not retried automatically' ) ) {
	throw new RuntimeException( 'Indeterminate operator guidance disappeared from run detail.' );
}
if ( ! str_contains( $detail, 'Runtime values are intentionally excluded from run history.' ) ) {
	throw new RuntimeException( 'Run history privacy boundary is not explicit in the operator UI.' );
}

fwrite( STDOUT, "Runtime observability: PASS\n" );
