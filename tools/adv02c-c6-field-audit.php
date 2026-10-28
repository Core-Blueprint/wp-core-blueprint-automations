<?php
declare(strict_types=1);

/**
 * ADV-02C C6 non-provider FIELD proof. Execute ONLY via wp eval-file on a
 * disposable local/staging WordPress clone, after installing the C6 ZIP.
 * The script creates and deletes isolated synthetic run/step/job fixtures.
 * It never invokes a real provider, enqueues work or changes a workflow.
 */

use CB\Automations\Persistence\JobRepository;
use CB\Automations\Persistence\Schema;
use CB\Automations\Runtime\GraphActionRecoveryFence;
use CB\Automations\Runtime\GraphRuntimeGate;
use CB\Automations\Runtime\JobRecord;
use CB\Automations\Runtime\RunExecutionLease;
use CB\Automations\Runtime\RunStatus;

defined( 'ABSPATH' ) || exit;
GraphRuntimeGate::require_cli();
if ( ! Schema::ready() || ! class_exists( GraphActionRecoveryFence::class ) ) {
	throw new RuntimeException( 'C6 runtime/schema is not installed and ready.' );
}
if ( ! ( wp_get_environment_type() === 'local' || wp_get_environment_type() === 'development' || wp_get_environment_type() === 'staging' ) ) {
	throw new RuntimeException( 'C6 FIELD audit cannot run on production.' );
}

global $wpdb;
$runs = Schema::runs_table();
$steps = Schema::run_steps_table();
$jobs = Schema::jobs_table();
$receipts = Schema::event_receipts_table();
$now = current_time( 'mysql', true );
$uuid = wp_generate_uuid4();
$correlation = wp_generate_uuid4();
if ( ! is_string( $uuid ) || ! is_string( $correlation ) ) {
	throw new RuntimeException( 'Could not create isolated test identities.' );
}
$hash = hash( 'sha256', 'advanced-c6-field:' . $uuid );
$payload = wp_json_encode( [
	'definition_version' => 2,
	'mode' => 'advanced',
	'graph' => [ 'nodes' => [], 'edges' => [] ],
	'layout' => [ 'nodes' => (object) [] ],
] );
if ( ! is_string( $payload ) ) {
	throw new RuntimeException( 'Could not encode synthetic immutable test definition.' );
}
$receipt_id = 0;
$run_id = 0;
$job_id = 0;
$step_id = 0;
$started = false;
$passed = [];
$check = static function ( bool $yes, string $name ) use ( &$passed ): void {
	if ( ! $yes ) {
		throw new RuntimeException( 'C6 FIELD failed: ' . $name );
	}
	$passed[] = $name;
};
$read_status = static function ( string $table, int $id ) use ( $wpdb ): string {
	$value = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id = %d", $id ) );
	if ( ! is_string( $value ) || '' !== (string) $wpdb->last_error ) {
		throw new RuntimeException( 'C6 status evidence could not be read.' );
	}
	return $value;
};
$insert = static function ( string $sql ) use ( $wpdb ): int {
	if ( false === $wpdb->query( $sql ) || (int) $wpdb->insert_id < 1 ) {
		throw new RuntimeException( 'C6 isolated test fixture could not be inserted.' );
	}
	return (int) $wpdb->insert_id;
};

try {
	$receipt_id = $insert( $wpdb->prepare(
		"INSERT INTO {$receipts}
		 (event_fingerprint, event_id, provider, trigger_id, schema_version, occurred_at,
		 payload_envelope, status, created_at, materialized_at, purged_at)
		 VALUES (%s, %s, %s, %s, %s, %s, '', 'materialized', %s, %s, %s)",
		$hash, 'c6-audit:' . $uuid, 'c6-audit', 'synthetic', '1', $now, $now, $now, $now
	) );
	$run_id = $insert( $wpdb->prepare(
		"INSERT INTO {$runs}
		 (run_uuid, correlation_id, event_receipt_id, workflow_id, workflow_revision,
		 execution_principal_user_id, definition_version, definition_json, definition_hash,
		 status, run_cursor, failure_code, created_at, started_at, finished_at, updated_at)
		 VALUES (%s, %s, %d, %d, 1, %d, 2, %s, %s, 'running', 'graph:0', '', %s, %s, NULL, %s)",
		$uuid, $correlation, $receipt_id, PHP_INT_MAX - 1000, get_current_user_id(),
		$payload, hash( 'sha256', $payload ), $now, $now, $now
	) );
	$token = bin2hex( random_bytes( 32 ) );
	$job_id = $insert( $wpdb->prepare(
		"INSERT INTO {$jobs}
		 (subject_type, subject_id, status, available_at, lease_token, lease_expires_at,
		 worker_attempts, last_error_code, created_at, updated_at)
		 VALUES ('run', %d, 'leased', %s, %s, %s, 1, '', %s, %s)",
		$run_id, $now, $token, gmdate( 'Y-m-d H:i:s', time() + 600 ), $now, $now
	) );
	$job = JobRepository::find( $job_id );
	if ( ! $job instanceof JobRecord ) {
		throw new RuntimeException( 'C6 leased fixture could not be hydrated.' );
	}
	$lease = RunExecutionLease::from_job( $job, $run_id );
	$lease->renew();
	$check( null === GraphActionRecoveryFence::before_vault( $run_id, $lease ), 'no-Action path remains runnable' );
	$check( 'running' === $read_status( $runs, $run_id ), 'no-Action run remains unchanged' );

	$step_id = $insert( $wpdb->prepare(
		"INSERT INTO {$steps}
		 (run_id, node_id, node_type, provider, capability_id, schema_version,
		 attempt, status, error_code, provider_error_code, started_at, finished_at)
		 VALUES (%d, 'c6_probe', 'action', 'c6-audit', 'synthetic', '1', 1,
		 'running', '', '', %s, NULL)", $run_id, $now
	) );

	// An invalid lease may not mark the Action or run. This also proves the
	// transaction callback did not run before the durable lease lock.
	$stale = new JobRecord(
		$job->id(), $job->subject_type(), $job->subject_id(), $job->status(),
		$job->available_at(), str_repeat( 'f', 64 ), $job->lease_expires_at(),
		$job->worker_attempts(), $job->last_error_code(), $job->created_at(), $job->updated_at()
	);
	$rejected = false;
	try {
		GraphActionRecoveryFence::before_vault( $run_id, RunExecutionLease::from_job( $stale, $run_id ) );
	} catch ( Throwable ) {
		$rejected = true;
	}
	$check( $rejected, 'stale lease is rejected' );
	$check( 'running' === $read_status( $steps, $step_id ) && 'running' === $read_status( $runs, $run_id ),
		'stale lease cannot mutate persisted evidence' );

	// Native InnoDB rollback proof without calling an external provider.
	if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
		throw new RuntimeException( 'C6 rollback fixture could not begin.' );
	}
	$started = true;
	if ( 1 !== $wpdb->query( $wpdb->prepare(
		"UPDATE {$steps} SET status = 'indeterminate' WHERE id = %d AND status = 'running'", $step_id
	) ) ) {
		throw new RuntimeException( 'C6 rollback fixture could not update step.' );
	}
	if ( false === $wpdb->query( 'ROLLBACK' ) ) {
		throw new RuntimeException( 'C6 rollback failed.' );
	}
	$started = false;
	$check( 'running' === $read_status( $steps, $step_id ), 'native InnoDB rollback restores Action attempt' );

	$check( RunStatus::Indeterminate === GraphActionRecoveryFence::before_vault( $run_id, $lease ),
		'crashed Action is quarantined before Vault' );
	$check( 'indeterminate' === $read_status( $steps, $step_id )
		&& 'indeterminate' === $read_status( $runs, $run_id ), 'step and run settle in one durable transaction' );
	$check( RunStatus::Indeterminate === GraphActionRecoveryFence::before_vault( $run_id, $lease ),
		'repeating recovery cannot invoke or rewrite an Action' );
	$check( 'indeterminate' === $read_status( $steps, $step_id ), 'original attempt remains indeterminate' );
} finally {
	if ( $started ) {
		$wpdb->query( 'ROLLBACK' );
	}
	// Only exact auto-generated primary keys are cleaned. Production is
	// excluded by the strict gate; no existing workflows are touched.
	foreach ( [ [ $steps, $step_id ], [ $jobs, $job_id ], [ $runs, $run_id ], [ $receipts, $receipt_id ] ] as [ $table, $id ] ) {
		if ( $id > 0 ) {
			$wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
		}
	}
}
WP_CLI::success( 'ADV-02C C6 sandbox InnoDB/lease/recovery FIELD PASS (' . count( $passed ) . ' assertions, no providers invoked).' );