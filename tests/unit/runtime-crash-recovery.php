<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\Automations\\';
	if ( ! str_starts_with( $class, $prefix ) ) { return; }
	$file = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( is_file( $file ) ) { require_once $file; }
} );

use CB\Automations\Runtime\CrashRecoveryPolicy;
use CB\Automations\Runtime\NodeType;
use CB\Automations\Runtime\RecoveryDecision;
use CB\Automations\Runtime\StepStatus;

if ( RecoveryDecision::ReplaySafe !== CrashRecoveryPolicy::for_running_step( NodeType::State, StepStatus::Running ) ) { throw new RuntimeException( 'State crash is no longer replay-safe.' ); }
if ( RecoveryDecision::ReplaySafe !== CrashRecoveryPolicy::for_running_step( NodeType::Condition, StepStatus::Running ) ) { throw new RuntimeException( 'Condition crash is no longer replay-safe.' ); }
if ( RecoveryDecision::Indeterminate !== CrashRecoveryPolicy::for_running_step( NodeType::Action, StepStatus::Running ) ) { throw new RuntimeException( 'Action crash is no longer indeterminate.' ); }

$root = dirname( __DIR__, 2 );
$lease_repo = file_get_contents( $root . '/src/Persistence/RunLeaseRepository.php' );
$lease = file_get_contents( $root . '/src/Runtime/RunExecutionLease.php' );
$state = file_get_contents( $root . '/src/Runtime/StatePhaseRunner.php' );
$condition = file_get_contents( $root . '/src/Runtime/ConditionPhaseRunner.php' );
$action = file_get_contents( $root . '/src/Runtime/ActionPhaseRunner.php' );
$pipeline = file_get_contents( $root . '/src/Runtime/RunPipeline.php' );
$worker = file_get_contents( $root . '/src/Runtime/RuntimeWorker.php' );
if ( false === $lease_repo || false === $lease || false === $state || false === $condition || false === $action || false === $pipeline || false === $worker ) { throw new RuntimeException( 'Could not read crash/recovery sources.' ); }

foreach ( [ 'lease_expires_at > %s', 'lease_token = %s', 'FOR UPDATE', 'JobSubjectType::Run->value', 'self::is_active( $job )' ] as $needle ) {
	if ( ! str_contains( $lease_repo, $needle ) ) { throw new RuntimeException( 'Run lease fence lost persistence invariant: ' . $needle ); }
}
foreach ( [ 'START TRANSACTION', 'RunLeaseRepository::lock_active(', 'COMMIT', 'ROLLBACK' ] as $needle ) {
	if ( ! str_contains( $lease, $needle ) ) { throw new RuntimeException( 'Run execution lease lost transaction fence: ' . $needle ); }
}
foreach ( [ $state, $condition, $action ] as $phase ) {
	if ( ! str_contains( $phase, 'RunExecutionLease $lease' ) || ! str_contains( $phase, '$lease->renew(' ) || ! str_contains( $phase, '$lease->transaction(' ) ) {
		throw new RuntimeException( 'A runtime phase can acknowledge without active lease ownership.' );
	}
}
if ( ! str_contains( $state, 'interrupt_running_attempts(' ) || ! str_contains( $condition, 'interrupt_running_attempts(' ) ) { throw new RuntimeException( 'Replay-safe phases lost interrupted-attempt recovery.' ); }
if ( str_contains( $action, 'interrupt_running_attempts(' ) || ! str_contains( $action, 'find_running_attempt(' ) || ! str_contains( $action, 'persist_indeterminate(' ) ) { throw new RuntimeException( 'Mutating Action no-replay recovery changed.' ); }
$invoke = strpos( $action, 'ActionInvoker::invoke(' );
$persist = strpos( $action, 'self::persist_success(' );
if ( false === $invoke || false === $persist || $invoke >= $persist ) { throw new RuntimeException( 'Action acknowledgement order is invalid.' ); }
if ( ! str_contains( $pipeline, 'RunExecutionLease::from_job(' ) || ! str_contains( $worker, 'RunPipeline::execute( $job->subject_id(), $job )' ) ) { throw new RuntimeException( 'Durable run-job lease is not propagated into execution.' ); }
if ( ! str_contains( $worker, '$lease->renew()' ) || ! str_contains( $worker, '$lease->transaction(' ) ) { throw new RuntimeException( 'Run worker recovery can mutate after losing lease ownership.' ); }

fwrite( STDOUT, "Crash/recovery lease fencing: PASS\n" );
