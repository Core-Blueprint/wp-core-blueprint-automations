<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

$root = dirname( __DIR__, 2 );
$pipeline = file_get_contents( $root . '/src/Runtime/RunPipeline.php' );
$worker = file_get_contents( $root . '/src/Runtime/RuntimeWorker.php' );
$wakeup = file_get_contents( $root . '/src/Runtime/WorkerWakeup.php' );
$action = file_get_contents( $root . '/src/Runtime/ActionPhaseRunner.php' );
$lease = file_get_contents( $root . '/src/Runtime/RunExecutionLease.php' );
if ( false === $pipeline || false === $worker || false === $wakeup || false === $action || false === $lease ) {
	throw new RuntimeException( 'Could not read AU2 Golden run-pipeline sources.' );
}

$state_pos = strpos( $pipeline, 'StatePhaseRunner::run(' );
$condition_pos = strpos( $pipeline, 'ConditionPhaseRunner::run(' );
$action_pos = strpos( $pipeline, 'ActionPhaseRunner::run(' );
if ( false === $state_pos || false === $condition_pos || false === $action_pos || ! ( $state_pos < $condition_pos && $condition_pos < $action_pos ) ) {
	throw new RuntimeException( 'Run pipeline does not preserve GET DATA → ONLY IF → THEN ordering.' );
}

foreach ( [
	'RunExecutionLease::from_job(',
	'StatePhaseRunner::run( $run_id, $lease )',
	'ConditionPhaseRunner::run( $run_id, $lease )',
	'ActionPhaseRunner::run( $run_id, $lease )',
] as $needle ) {
	if ( ! str_contains( $pipeline, $needle ) ) {
		throw new RuntimeException( 'Run pipeline lost lease/orchestration invariant: ' . $needle );
	}
}
foreach ( [
	'JobRepository::claim( 120 )',
	'JobSubjectType::Event',
	'JobSubjectType::Run',
	'EventMaterializer::materialize(',
	'RunPipeline::execute( $job->subject_id(), $job )',
	'handle_run_failure(',
] as $needle ) {
	if ( ! str_contains( $worker, $needle ) ) {
		throw new RuntimeException( 'Runtime worker lost orchestration invariant: ' . $needle );
	}
}
if ( str_contains( $worker, 'StateInvoker::resolve' ) || str_contains( $worker, 'ActionInvoker::invoke' ) ) {
	throw new RuntimeException( 'Queue worker bypasses phase-owned public execution boundaries.' );
}
if ( ! str_contains( $wakeup, 'JobSubjectType::Event, JobSubjectType::Run' ) || ! str_contains( $wakeup, 'wp_schedule_single_event' ) ) {
	throw new RuntimeException( 'Worker wake-up does not cover both durable job types through one-shot scheduling.' );
}
if ( ! str_contains( $action, 'RunStepRepository::find_running_attempt(' ) || ! str_contains( $action, 'RunStepRepository::indeterminate(' ) ) {
	throw new RuntimeException( 'Golden run pipeline lost the no-replay Action crash boundary.' );
}
if ( ! str_contains( $lease, 'RunLeaseRepository::lock_active(' ) ) {
	throw new RuntimeException( 'Run pipeline acknowledgements are not fenced by durable queue ownership.' );
}

fwrite( STDOUT, "Run pipeline integration: PASS\n" );
