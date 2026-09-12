<?php
declare(strict_types=1);

namespace CB\Automations\Persistence;

use CB\Automations\Runtime\JobStatus;
use CB\Automations\Runtime\JobSubjectType;
use CB\Automations\Runtime\NodeType;
use CB\Automations\Runtime\OperatorRecoveryDecision;
use CB\Automations\Runtime\OperatorRecoveryFailure;
use CB\Automations\Runtime\RunCursor;
use CB\Automations\Runtime\RunStatus;
use CB\Automations\Runtime\StepStatus;
defined( 'ABSPATH' ) || exit;

final class OperatorRecoveryRepository {
	public static function resolve(
		int $run_id,
		string $node_id,
		int $attempt,
		OperatorRecoveryDecision $decision,
		int $operator_user_id,
		string $expected_cursor,
		string $resulting_cursor,
		RunStatus $target,
		bool $rearm_job
	): void {
		global $wpdb;
		if (
			$run_id < 1
			|| $attempt < 1
			|| $operator_user_id < 1
			|| 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $node_id )
			|| ! RunCursor::is_valid( $expected_cursor )
			|| ! RunCursor::is_valid( $resulting_cursor )
		) {
			throw new \InvalidArgumentException( 'Invalid automation operator recovery persistence request.' );
		}
		self::assert_resolution_shape( $decision, $expected_cursor, $resulting_cursor, $target, $rearm_job );

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- recovery owns one multi-table atomic resolution.
			throw PersistenceFailure::database( 'start automation operator recovery transaction' );
		}

		try {
			$job = self::lock_terminal_run_job( $run_id );
			self::lock_indeterminate_run( $run_id, $expected_cursor );
			self::lock_indeterminate_attempt( $run_id, $node_id, $attempt );
			self::assert_unresolved( $run_id, $node_id, $attempt );
			self::insert_ledger(
				$run_id,
				$node_id,
				$attempt,
				$decision,
				$operator_user_id,
				$expected_cursor,
				$resulting_cursor,
				$target
			);
			self::update_run( $run_id, $expected_cursor, $resulting_cursor, $target );
			if ( $rearm_job ) {
				self::rearm_locked_job( (int) $job['id'], (string) $job['status'] );
			}

			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- recovery owns one multi-table atomic resolution.
				throw PersistenceFailure::database( 'commit automation operator recovery transaction' );
			}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- recovery rollback after failed atomic resolution.
			throw $error;
		}
	}

	/** @return array<int,array<string,mixed>> */
	public static function history( int $run_id ): array {
		global $wpdb;
		if ( $run_id < 1 ) {
			throw new \InvalidArgumentException( 'Recovery history run id must be positive.' );
		}
		$table = Schema::run_recoveries_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, node_id, attempt, decision, operator_user_id, previous_status, resulting_status, previous_cursor, resulting_cursor, created_at
				 FROM {$table} WHERE run_id = %d ORDER BY id ASC",
				$run_id
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			throw PersistenceFailure::database( 'load automation operator recovery history' );
		}

		$result = [];
		foreach ( $rows as $row ) {
			$decision = OperatorRecoveryDecision::tryFrom( (string) ( $row['decision'] ?? '' ) );
			$previous = RunStatus::tryFrom( (string) ( $row['previous_status'] ?? '' ) );
			$resulting = RunStatus::tryFrom( (string) ( $row['resulting_status'] ?? '' ) );
			$node_id = (string) ( $row['node_id'] ?? '' );
			$previous_cursor = (string) ( $row['previous_cursor'] ?? '' );
			$resulting_cursor = (string) ( $row['resulting_cursor'] ?? '' );
			if (
				(int) ( $row['id'] ?? 0 ) < 1
				|| (int) ( $row['attempt'] ?? 0 ) < 1
				|| (int) ( $row['operator_user_id'] ?? 0 ) < 1
				|| 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $node_id )
				|| ! RunCursor::is_valid( $previous_cursor )
				|| ! RunCursor::is_valid( $resulting_cursor )
				|| RunStatus::Indeterminate !== $previous
				|| null === $decision
				|| null === $resulting
			) {
				throw PersistenceFailure::definition();
			}
			$result[] = [
				'id' => (int) $row['id'],
				'node_id' => $node_id,
				'attempt' => (int) $row['attempt'],
				'decision' => $decision,
				'operator_user_id' => (int) $row['operator_user_id'],
				'previous_status' => $previous,
				'resulting_status' => $resulting,
				'previous_cursor' => $previous_cursor,
				'resulting_cursor' => $resulting_cursor,
				'created_at' => is_string( $row['created_at'] ?? null ) ? $row['created_at'] : null,
			];
		}
		return $result;
	}

	/** @return array<string,mixed> */
	private static function lock_terminal_run_job( int $run_id ): array {
		global $wpdb;
		$table = Schema::jobs_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, lease_token, lease_expires_at FROM {$table}
				 WHERE subject_type = %s AND subject_id = %d LIMIT 1 FOR UPDATE",
				JobSubjectType::Run->value,
				$run_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			if ( '' !== (string) $wpdb->last_error ) {
				throw PersistenceFailure::database( 'lock settled automation run job for recovery' );
			}
			throw new OperatorRecoveryFailure( 'recovery.job_missing' );
		}
		$status = JobStatus::tryFrom( (string) ( $row['status'] ?? '' ) );
		if ( null === $status || (int) ( $row['id'] ?? 0 ) < 1 ) {
			throw PersistenceFailure::definition();
		}
		if ( JobStatus::Leased === $status || '' !== (string) ( $row['lease_token'] ?? '' ) || null !== ( $row['lease_expires_at'] ?? null ) ) {
			throw new OperatorRecoveryFailure( 'recovery.job_active' );
		}
		if ( ! $status->is_terminal() ) {
			throw new OperatorRecoveryFailure( 'recovery.job_not_settled' );
		}
		return $row;
	}

	private static function lock_indeterminate_run( int $run_id, string $expected_cursor ): void {
		global $wpdb;
		$table = Schema::runs_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status, cursor FROM {$table} WHERE id = %d LIMIT 1 FOR UPDATE",
				$run_id
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			if ( '' !== (string) $wpdb->last_error ) {
				throw PersistenceFailure::database( 'lock indeterminate automation run for recovery' );
			}
			throw new OperatorRecoveryFailure( 'recovery.run_missing' );
		}
		if ( RunStatus::Indeterminate->value !== (string) ( $row['status'] ?? '' ) || $expected_cursor !== (string) ( $row['cursor'] ?? '' ) ) {
			throw new OperatorRecoveryFailure( 'recovery.run_conflict' );
		}
	}

	private static function lock_indeterminate_attempt( int $run_id, string $node_id, int $attempt ): void {
		global $wpdb;
		$table = Schema::run_steps_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, status FROM {$table}
				 WHERE run_id = %d AND node_type = %s AND node_id = %s AND attempt = %d
				 LIMIT 1 FOR UPDATE",
				$run_id,
				NodeType::Action->value,
				$node_id,
				$attempt
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			if ( '' !== (string) $wpdb->last_error ) {
				throw PersistenceFailure::database( 'lock indeterminate automation Action attempt for recovery' );
			}
			throw new OperatorRecoveryFailure( 'recovery.attempt_conflict' );
		}
		if ( StepStatus::Indeterminate->value !== (string) ( $row['status'] ?? '' ) ) {
			throw new OperatorRecoveryFailure( 'recovery.attempt_conflict' );
		}
	}

	private static function assert_unresolved( int $run_id, string $node_id, int $attempt ): void {
		global $wpdb;
		$table = Schema::run_recoveries_table();
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE run_id = %d AND node_id = %s AND attempt = %d LIMIT 1 FOR UPDATE",
				$run_id,
				$node_id,
				$attempt
			)
		);
		if ( null !== $existing ) {
			throw new OperatorRecoveryFailure( 'recovery.already_resolved' );
		}
		if ( '' !== (string) $wpdb->last_error ) {
			throw PersistenceFailure::database( 'verify automation recovery resolution uniqueness' );
		}
	}

	private static function insert_ledger(
		int $run_id,
		string $node_id,
		int $attempt,
		OperatorRecoveryDecision $decision,
		int $operator_user_id,
		string $previous_cursor,
		string $resulting_cursor,
		RunStatus $target
	): void {
		global $wpdb;
		$result = $wpdb->insert(
			Schema::run_recoveries_table(),
			[
				'run_id' => $run_id,
				'node_id' => $node_id,
				'attempt' => $attempt,
				'decision' => $decision->value,
				'operator_user_id' => $operator_user_id,
				'previous_status' => RunStatus::Indeterminate->value,
				'resulting_status' => $target->value,
				'previous_cursor' => $previous_cursor,
				'resulting_cursor' => $resulting_cursor,
				'created_at' => current_time( 'mysql', true ),
			],
			[ '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
		);
		if ( false === $result || 1 !== $result ) {
			throw PersistenceFailure::database( 'append automation operator recovery ledger' );
		}
	}

	private static function update_run( int $run_id, string $expected_cursor, string $resulting_cursor, RunStatus $target ): void {
		global $wpdb;
		$table = Schema::runs_table();
		$now = current_time( 'mysql', true );
		if ( RunStatus::Queued === $target ) {
			$sql = $wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, cursor = %s, failure_code = '', finished_at = NULL, updated_at = %s
				 WHERE id = %d AND status = %s AND cursor = %s",
				$target->value,
				$resulting_cursor,
				$now,
				$run_id,
				RunStatus::Indeterminate->value,
				$expected_cursor
			);
		} else {
			$failure_code = RunStatus::Cancelled === $target ? 'recovery.abandoned_unresolved' : '';
			$sql = $wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, cursor = %s, failure_code = %s, finished_at = %s, updated_at = %s
				 WHERE id = %d AND status = %s AND cursor = %s",
				$target->value,
				$resulting_cursor,
				$failure_code,
				$now,
				$now,
				$run_id,
				RunStatus::Indeterminate->value,
				$expected_cursor
			);
		}
		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query is fully prepared above.
		if ( false === $result ) {
			throw PersistenceFailure::database( 'resolve indeterminate automation run' );
		}
		if ( 1 !== $result ) {
			throw new OperatorRecoveryFailure( 'recovery.run_conflict' );
		}
	}

	private static function rearm_locked_job( int $job_id, string $expected_status ): void {
		global $wpdb;
		$table = Schema::jobs_table();
		$now = current_time( 'mysql', true );
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, available_at = %s, lease_token = '', lease_expires_at = NULL,
				     worker_attempts = 0, last_error_code = '', updated_at = %s
				 WHERE id = %d AND status = %s AND lease_token = '' AND lease_expires_at IS NULL",
				JobStatus::Queued->value,
				$now,
				$now,
				$job_id,
				$expected_status
			)
		);
		if ( false === $result ) {
			throw PersistenceFailure::database( 'rearm settled automation run job for operator recovery' );
		}
		if ( 1 !== $result ) {
			throw new OperatorRecoveryFailure( 'recovery.job_conflict' );
		}
	}

	private static function assert_resolution_shape(
		OperatorRecoveryDecision $decision,
		string $expected_cursor,
		string $resulting_cursor,
		RunStatus $target,
		bool $rearm_job
	): void {
		$valid = match ( $decision ) {
			OperatorRecoveryDecision::ConfirmedSucceeded => (
				$expected_cursor !== $resulting_cursor
					&& in_array( $target, [ RunStatus::Queued, RunStatus::Succeeded ], true )
					&& $rearm_job === ( RunStatus::Queued === $target )
			),
			OperatorRecoveryDecision::ConfirmedDidNotOccur => (
				$expected_cursor === $resulting_cursor
					&& RunStatus::Queued === $target
					&& $rearm_job
			),
			OperatorRecoveryDecision::AbandonUnresolved => (
				$expected_cursor === $resulting_cursor
					&& RunStatus::Cancelled === $target
					&& ! $rearm_job
			),
		};
		if ( ! $valid ) {
			throw new \InvalidArgumentException( 'Invalid automation operator recovery transition.' );
		}
	}
}
