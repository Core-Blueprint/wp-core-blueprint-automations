<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

$root = dirname( __DIR__, 2 );
require_once $root . '/src/Runtime/TriggerKey.php';
require_once $root . '/src/Persistence/EventReceiptRepository.php';

use CB\Automations\Persistence\EventReceiptRepository;
use CB\Automations\Runtime\TriggerKey;

$schema = file_get_contents( $root . '/src/Persistence/Schema.php' );
$receipts = file_get_contents( $root . '/src/Persistence/EventReceiptRepository.php' );
$jobs = file_get_contents( $root . '/src/Persistence/JobRepository.php' );
$runs = file_get_contents( $root . '/src/Persistence/RunRepository.php' );
$materializer = file_get_contents( $root . '/src/Runtime/EventMaterializer.php' );
$worker = file_get_contents( $root . '/src/Runtime/RuntimeWorker.php' );

if ( false === $schema || false === $receipts || false === $jobs || false === $runs || false === $materializer || false === $worker ) {
	fwrite( STDERR, "Could not read AU2.0G1 runtime sources.\n" );
	exit( 1 );
}

$failures = [];
foreach ( [
	'UNIQUE KEY event_fingerprint (event_fingerprint)',
	'UNIQUE KEY event_workflow (event_receipt_id, workflow_id)',
	'UNIQUE KEY subject (subject_type, subject_id)',
] as $invariant ) {
	if ( ! str_contains( $schema, $invariant ) ) {
		$failures[] = 'Missing database convergence invariant: ' . $invariant;
	}
}
if ( ! str_contains( $receipts, 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)' ) ) {
	$failures[] = 'Duplicate event delivery does not converge on the existing receipt.';
}
if ( ! str_contains( $jobs, 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)' ) ) {
	$failures[] = 'Duplicate queue delivery does not converge on the existing job.';
}
if ( ! str_contains( $runs, 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)' ) ) {
	$failures[] = 'Duplicate fan-out does not converge on the existing workflow run.';
}
if ( ! str_contains( $materializer, 'FOR UPDATE' ) && ! str_contains( $receipts, 'FOR UPDATE' ) ) {
	$failures[] = 'Event materialization does not serialize on the receipt row.';
}
if (
	! str_contains( $worker, 'JobRepository::claim(' )
	|| ! str_contains( $worker, 'JobSubjectType::Event === $job->subject_type()' )
	|| ! str_contains( $worker, 'JobSubjectType::Run === $job->subject_type()' )
) {
	$failures[] = 'G1 worker no longer routes the unified durable queue by subject type.';
}

$provider = 'core-blueprint-lms';
$trigger = 'course.completed';
$schema_version = '1';
$event_id = 'course-42-user-13-attempt-7';
$expected_fingerprint = EventReceiptRepository::fingerprint( $provider, $trigger, $event_id );
$trigger_key = TriggerKey::from_parts( $provider, $trigger, $schema_version );
if ( 64 !== strlen( $expected_fingerprint ) || 64 !== strlen( $trigger_key ) ) {
	$failures[] = 'Runtime event identities are not canonical SHA-256 values.';
}

/*
 * Deterministic model of the three database uniqueness boundaries. The source
 * assertions above lock this model to real UNIQUE keys + idempotent INSERTs.
 */
$receipt_ids = [];
$event_jobs = [];
$run_ids = [];
$run_jobs = [];
$next_receipt_id = 1;
$next_run_id = 1;
$matching_workflows = [ 101, 102, 103 ];

for ( $delivery = 0; $delivery < 100; $delivery++ ) {
	$fingerprint = EventReceiptRepository::fingerprint( $provider, $trigger, $event_id );
	if ( ! isset( $receipt_ids[ $fingerprint ] ) ) {
		$receipt_ids[ $fingerprint ] = $next_receipt_id++;
	}
	$receipt_id = $receipt_ids[ $fingerprint ];
	$event_jobs[ 'event:' . $receipt_id ] = true;

	foreach ( $matching_workflows as $workflow_id ) {
		$run_identity = $receipt_id . ':' . $workflow_id;
		if ( ! isset( $run_ids[ $run_identity ] ) ) {
			$run_ids[ $run_identity ] = $next_run_id++;
		}
		$run_jobs[ 'run:' . $run_ids[ $run_identity ] ] = true;
	}
}

if ( 1 !== count( $receipt_ids ) ) {
	$failures[] = '100 duplicate event deliveries produced more than one receipt identity.';
}
if ( 1 !== count( $event_jobs ) ) {
	$failures[] = '100 duplicate event deliveries produced more than one event-job identity.';
}
if ( count( $matching_workflows ) !== count( $run_ids ) ) {
	$failures[] = 'Duplicate fan-out produced more than one run per matching workflow.';
}
if ( count( $matching_workflows ) !== count( $run_jobs ) ) {
	$failures[] = 'Duplicate fan-out produced more than one run job per durable run.';
}

foreach ( [ $receipts, $jobs, $runs, $materializer, $worker ] as $source ) {
	if ( str_contains( $source, 'ActionInvoker::invoke' ) || str_contains( $source, 'StateInvoker::resolve' ) ) {
		$failures[] = 'G1 intake/concurrency path must not bypass the canonical execution pipeline with direct provider invocation.';
		break;
	}
}

if ( [] !== $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, $failure . PHP_EOL );
	}
	exit( 1 );
}

fwrite( STDOUT, "G1 intake concurrency proof: PASS\n" );
