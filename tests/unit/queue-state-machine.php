<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\Automations\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$file = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );

use CB\Automations\Runtime\CrashRecoveryPolicy;
use CB\Automations\Runtime\NodeType;
use CB\Automations\Runtime\RecoveryDecision;
use CB\Automations\Runtime\RunStateMachine;
use CB\Automations\Runtime\RunStatus;
use CB\Automations\Runtime\StepStatus;

if ( RecoveryDecision::ReplaySafe !== CrashRecoveryPolicy::for_running_step( NodeType::State, StepStatus::Running ) ) {
	throw new RuntimeException( 'Crashed read-only State must be replay-safe.' );
}
if ( RecoveryDecision::ReplaySafe !== CrashRecoveryPolicy::for_running_step( NodeType::Condition, StepStatus::Running ) ) {
	throw new RuntimeException( 'Crashed Condition must be replay-safe.' );
}
if ( RecoveryDecision::Indeterminate !== CrashRecoveryPolicy::for_running_step( NodeType::Action, StepStatus::Running ) ) {
	throw new RuntimeException( 'Crashed mutating Action must become indeterminate.' );
}
if ( RecoveryDecision::None !== CrashRecoveryPolicy::for_running_step( NodeType::Action, StepStatus::Succeeded ) ) {
	throw new RuntimeException( 'Crash recovery must not reinterpret terminal step history.' );
}
if ( ! RunStateMachine::allows( RunStatus::Running, RunStatus::Indeterminate ) ) {
	throw new RuntimeException( 'Running run cannot enter first-class indeterminate state.' );
}
if ( ! RunStatus::Indeterminate->halts_automatic_execution() || RunStatus::Indeterminate->is_terminal() ) {
	throw new RuntimeException( 'Indeterminate must halt automation while remaining operator-resolvable.' );
}
if ( ! RunStateMachine::allows( RunStatus::Indeterminate, RunStatus::Succeeded ) ) {
	throw new RuntimeException( 'Operator recovery cannot resolve an indeterminate run as succeeded.' );
}
if ( RunStateMachine::allows( RunStatus::Succeeded, RunStatus::Queued ) ) {
	throw new RuntimeException( 'Succeeded runs must remain terminal.' );
}
if ( ! StepStatus::Interrupted->is_terminal() ) {
	throw new RuntimeException( 'Interrupted read-only attempts must be closed before replay.' );
}

$root = dirname( __DIR__, 2 );
$jobs = file_get_contents( $root . '/src/Persistence/JobRepository.php' );
$architecture = file_get_contents( $root . '/tests/unit/architecture.php' );
if ( false === $jobs || false === $architecture ) {
	throw new RuntimeException( 'Could not read AU2 queue sources.' );
}

foreach ( [
	'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
	'lease_expires_at <= %s',
	'worker_attempts = worker_attempts + 1',
	'WHERE id = %d AND status = %s AND lease_token = %s',
	'hash_equals( $token, $record->lease_token() )',
] as $needle ) {
	if ( ! str_contains( $jobs, $needle ) ) {
		throw new RuntimeException( 'Durable queue lost lease/dedupe invariant: ' . $needle );
	}
}
if ( str_contains( strtoupper( $jobs ), 'SKIP LOCKED' ) ) {
	throw new RuntimeException( 'Durable queue must not require SKIP LOCKED.' );
}
if ( str_contains( $jobs, 'as_enqueue_async_action' ) || str_contains( $jobs, 'as_schedule_single_action' ) ) {
	throw new RuntimeException( 'Durable queue must not depend on Action Scheduler.' );
}
if ( str_contains( $architecture, "'StateInvoker::resolve' =>" ) || str_contains( $architecture, "'ActionInvoker::invoke' =>" ) ) {
	throw new RuntimeException( 'Architecture guard still forbids the governed public execution slices.' );
}
if ( ! str_contains( $architecture, 'CapabilityRegistry::executor' ) || ! str_contains( $architecture, 'CapabilityRegistry::state_resolver' ) ) {
	throw new RuntimeException( 'Private Base execution bypasses are no longer architecture-guarded.' );
}

fwrite( STDOUT, "Queue state machine: PASS\n" );
