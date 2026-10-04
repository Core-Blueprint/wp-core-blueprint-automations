<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$schema = file_get_contents( $root . '/src/Persistence/Schema.php' );
$repository = file_get_contents( $root . '/src/Persistence/OperatorRecoveryRepository.php' );
$service = file_get_contents( $root . '/src/Runtime/OperatorRecoveryService.php' );
$decision = file_get_contents( $root . '/src/Runtime/OperatorRecoveryDecision.php' );
$state_machine = file_get_contents( $root . '/src/Runtime/RunStateMachine.php' );
$capability = file_get_contents( $root . '/src/Admin/RecoveryCapability.php' );
$controller = file_get_contents( $root . '/src/Admin/OperatorRecoveryController.php' );
$history = file_get_contents( $root . '/src/Admin/RunHistoryReadService.php' );
$detail = file_get_contents( $root . '/templates/admin/run-detail.php' );
$bootstrap = file_get_contents( $root . '/core-blueprint-automations.php' );

if ( in_array( false, [ $schema, $repository, $service, $decision, $state_machine, $capability, $controller, $history, $detail, $bootstrap ], true ) ) {
	throw new RuntimeException( 'Could not read AU2.9 operator recovery sources.' );
}

if ( ! str_contains( $bootstrap, "define( 'CB_AUTOMATIONS_DB_VERSION', '2' );" ) || str_contains( $bootstrap, "CB_AUTOMATIONS_DB_VERSION', '3'" ) ) {
	throw new RuntimeException( 'AU2.9 must remain inside the single unpublished v2 schema upgrade.' );
}
if ( ! str_contains( $schema, 'cb_automations_run_recoveries' ) || ! str_contains( $schema, 'UNIQUE KEY run_node_attempt' ) ) {
	throw new RuntimeException( 'Operator recovery ledger is missing from the complete v2 schema.' );
}
if ( str_contains( $state_machine, 'RunStatus::Indeterminate => in_array( $to, [ RunStatus::Queued' ) ) {
	throw new RuntimeException( 'Indeterminate to Queued became a generic runtime transition.' );
}
foreach ( [ 'confirmed_succeeded', 'confirmed_did_not_occur', 'abandon_unresolved' ] as $value ) {
	if ( ! str_contains( $decision, $value ) ) {
		throw new RuntimeException( 'Missing operator recovery decision: ' . $value );
	}
}
if ( str_contains( $decision, "failed" ) ) {
	throw new RuntimeException( 'AU2.9 MVP must not expose a generic/manual failure resolution.' );
}

$job_lock = strpos( $repository, 'private static function lock_terminal_run_job' );
$run_lock = strpos( $repository, 'private static function lock_indeterminate_run' );
$attempt_lock = strpos( $repository, 'private static function lock_indeterminate_attempt' );
if ( false === $job_lock || false === $run_lock || false === $attempt_lock || ! ( $job_lock < $run_lock && $run_lock < $attempt_lock ) ) {
	throw new RuntimeException( 'Recovery lock order must be durable run-job first, then run, then Action attempt.' );
}
if ( ! str_contains( substr( $repository, $job_lock, $run_lock - $job_lock ), 'FOR UPDATE' ) || ! str_contains( substr( $repository, $run_lock, $attempt_lock - $run_lock ), 'FOR UPDATE' ) ) {
	throw new RuntimeException( 'Recovery durable job/run locks are no longer row-locked.' );
}
if ( ! str_contains( $repository, "JobStatus::Leased === \$status" ) || ! str_contains( $repository, "'recovery.job_active'" ) ) {
	throw new RuntimeException( 'Recovery does not refuse an actively leased run-job.' );
}
if ( ! str_contains( $repository, 'RunStatus::Indeterminate->value' ) || ! str_contains( $repository, 'StepStatus::Indeterminate->value' ) ) {
	throw new RuntimeException( 'Recovery does not revalidate the indeterminate run and Action attempt under lock.' );
}
if ( ! str_contains( $repository, 'SET status = %s, run_cursor = %s' ) || ! str_contains( $repository, 'rearm_locked_job' ) ) {
	throw new RuntimeException( 'Recovery-only requeue/rearm path is missing.' );
}
if ( ! str_contains( $repository, 'START TRANSACTION' ) || ! str_contains( $repository, 'COMMIT' ) || ! str_contains( $repository, 'ROLLBACK' ) ) {
	throw new RuntimeException( 'Recovery ledger/state/job mutation is not transactionally bounded.' );
}
if ( ! str_contains( $service, 'has_downstream_dependency_on' ) || ! str_contains( $service, 'recovery.output_required_downstream' ) ) {
	throw new RuntimeException( 'Confirmed-success recovery lost the downstream output dependency fail-closed gate.' );
}
if ( ! str_contains( $service, 'RunStatus::Cancelled' ) || ! str_contains( $repository, 'recovery.abandoned_unresolved' ) ) {
	throw new RuntimeException( 'Abandon unresolved must resolve to Cancelled.' );
}
if ( str_contains( $service, 'ActionInvoker' ) || str_contains( $repository, 'ActionInvoker' ) ) {
	throw new RuntimeException( 'Operator recovery must never invoke an Action directly.' );
}
if ( 1 !== substr_count( $service, 'OperatorRecoveryRepository::resolve(' ) ) {
	throw new RuntimeException( 'OperatorRecoveryService must own the single recovery mutation call.' );
}
$attempt_lock_start = strpos( $repository, 'private static function lock_indeterminate_attempt' );
$attempt_lock_end = strpos( $repository, 'private static function assert_unresolved', false === $attempt_lock_start ? 0 : $attempt_lock_start );
if ( false === $attempt_lock_start || false === $attempt_lock_end || str_contains( substr( $repository, $attempt_lock_start, $attempt_lock_end - $attempt_lock_start ), 'UPDATE ' ) ) {
	throw new RuntimeException( 'Recovery must never rewrite the original indeterminate Action attempt.' );
}

$resolve_callers = 0;
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$contents = file_get_contents( $file->getPathname() );
	if ( false !== $contents ) {
		$resolve_callers += substr_count( $contents, 'OperatorRecoveryRepository::resolve(' );
	}
}
if ( 1 !== $resolve_callers ) {
	throw new RuntimeException( 'Recovery-only Indeterminate to Queued path escaped OperatorRecoveryService.' );
}

if ( ! str_contains( $capability, "public const CAPABILITY = 'cb_recover_automations'" ) || ! str_contains( $capability, "PARENT_CAPABILITY = 'cb_manage_permissions'" ) ) {
	throw new RuntimeException( 'Recovery authority is not rooted in the public PAG-protected capability boundary.' );
}
if ( str_contains( $capability, 'cb_operator' ) || str_contains( $capability, "get_role(" ) ) {
	throw new RuntimeException( 'Recovery capability provisioning must not hard-code Base roles.' );
}
if ( ! str_contains( $capability, 'core_blueprint_capability_catalog' ) || ! str_contains( $capability, 'user_has_cap' ) ) {
	throw new RuntimeException( 'Recovery capability is not integrated through public capability contracts.' );
}
if ( ! str_contains( $controller, 'wp_verify_nonce' ) || ! str_contains( $controller, 'current_user_can( RecoveryCapability::CAPABILITY )' ) ) {
	throw new RuntimeException( 'Recovery mutation route is missing capability or CSRF authorization.' );
}

foreach ( [ 'definition_json', 'context_envelope', 'payload_envelope', 'RunContextRepository', 'Vault::open' ] as $forbidden ) {
	if ( str_contains( $history, $forbidden ) || str_contains( $detail, $forbidden ) || str_contains( $repository, 'SELECT ' . $forbidden ) ) {
		throw new RuntimeException( 'Operator recovery history crossed metadata-only boundary: ' . $forbidden );
	}
}
if ( ! str_contains( $detail, 'Confirm succeeded' ) || ! str_contains( $detail, 'Confirm did not occur' ) || ! str_contains( $detail, 'Abandon unresolved run' ) ) {
	throw new RuntimeException( 'Operator recovery UI is incomplete.' );
}
if ( str_contains( $detail, 'Retry Action' ) || str_contains( $controller, 'Retry Action' ) || str_contains( $service, 'Retry Action' ) ) {
	throw new RuntimeException( 'Generic Retry Action UI/route must remain forbidden.' );
}

fwrite( STDOUT, "Operator recovery AU2.9: PASS\n" );
