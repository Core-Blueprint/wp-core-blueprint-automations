<?php
declare(strict_types=1);

/**
 * Golden-R3: diagnostics may not concatenate raw SQL errors or untrusted
 * exception messages into error_log().
 */
$root = dirname( __DIR__, 2 );
$paths = [
	'src/Persistence/WorkflowRepository.php',
	'src/Admin/AutomationsPage.php',
	'src/Admin/AutomationRunsPage.php',
	'src/Admin/WorkflowController.php',
	'src/Admin/WorkflowDeletionController.php',
	'src/Admin/WorkflowActivationController.php',
	'src/Admin/OperatorRecoveryController.php',
];
foreach ( $paths as $path ) {
	$source = file_get_contents( $root . '/' . $path );
	if ( ! is_string( $source ) ) {
		throw new RuntimeException( 'Missing diagnostic source: ' . $path );
	}
	if ( preg_match( '/error_log\s*\([^;]*?(?:->getMessage\s*\(|\$wpdb->last_error|\$reason)\s*[^;]*?\);/s', $source ) ) {
		throw new RuntimeException( 'Potentially sensitive value can reach diagnostic log: ' . $path );
	}
}
$workflow = file_get_contents( $root . '/src/Persistence/WorkflowRepository.php' );
if ( ! str_contains( $workflow, 'Workflow create database failure.' ) ) {
	throw new RuntimeException( 'Workflow SQL error logging lacks fixed bounded context.' );
}
fwrite( STDOUT, "Golden-R3 fixed privacy-safe diagnostics: PASS\n" );
