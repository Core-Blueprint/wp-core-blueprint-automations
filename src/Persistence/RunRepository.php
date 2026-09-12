<?php
declare(strict_types=1);

namespace CB\Automations\Persistence;

use CB\Automations\Runtime\RunCursor;
use CB\Automations\Runtime\RunRecord;
use CB\Automations\Runtime\RunStateMachine;
use CB\Automations\Runtime\RunStatus;
use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\DefinitionCodec;

defined( 'ABSPATH' ) || exit;

final class RunRepository {
	public static function create_snapshot(
		int $event_receipt_id,
		WorkflowRecord $workflow,
		string $correlation_id
	): int {
		global $wpdb;
		if ( $event_receipt_id < 1 || ! self::is_uuid( $correlation_id ) ) {
			throw new \InvalidArgumentException( 'Invalid automation run snapshot identity.' );
		}

		$encoded = DefinitionCodec::encode( $workflow->definition() );
		try {
			$definition_json = json_encode(
				$encoded,
				JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			);
		} catch ( \JsonException ) {
			throw PersistenceFailure::definition();
		}
		$definition_hash = hash( 'sha256', $definition_json );
		$run_uuid = wp_generate_uuid4();
		if ( ! is_string( $run_uuid ) || ! self::is_uuid( $run_uuid ) ) {
			throw PersistenceFailure::definition();
		}

		$table = Schema::runs_table();
		$now = current_time( 'mysql', true );
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
				 (run_uuid, correlation_id, event_receipt_id, workflow_id, workflow_revision, execution_principal_user_id,
				  definition_version, definition_json, definition_hash, status, cursor, failure_code, created_at, started_at, finished_at, updated_at)
				 VALUES (%s, %s, %d, %d, %d, %d, %d, %s, %s, %s, '', '', %s, NULL, NULL, %s)
				 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
				$run_uuid,
				$correlation_id,
				$event_receipt_id,
				$workflow->id(),
				$workflow->revision(),
				$workflow->execution_principal_user_id(),
				$workflow->definition()->definition_version(),
				$definition_json,
				$definition_hash,
				RunStatus::Queued->value,
				$now,
				$now
			)
		);
		if ( false === $result ) {
			throw PersistenceFailure::database( 'create immutable automation run snapshot' );
		}

		$id = (int) $wpdb->insert_id;
		if ( $id < 1 ) {
			$id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE event_receipt_id = %d AND workflow_id = %d LIMIT 1",
					$event_receipt_id,
					$workflow->id()
				)
			);
		}
		if ( $id < 1 ) {
			throw PersistenceFailure::database( 'resolve immutable automation run snapshot' );
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT event_receipt_id, workflow_id, workflow_revision, execution_principal_user_id, definition_hash
				 FROM {$table} WHERE id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		);
		if (
			! is_array( $row )
			|| (int) ( $row['event_receipt_id'] ?? 0 ) !== $event_receipt_id
			|| (int) ( $row['workflow_id'] ?? 0 ) !== $workflow->id()
			|| (int) ( $row['workflow_revision'] ?? 0 ) !== $workflow->revision()
			|| (int) ( $row['execution_principal_user_id'] ?? -1 ) !== $workflow->execution_principal_user_id()
			|| ! hash_equals( $definition_hash, (string) ( $row['definition_hash'] ?? '' ) )
		) {
			throw PersistenceFailure::definition();
		}

		return $id;
	}

	public static function find( int $id ): ?RunRecord {
		global $wpdb;
		if ( $id < 1 ) {
			throw new \InvalidArgumentException( 'Run id must be positive.' );
		}
		$table = Schema::runs_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, run_uuid, correlation_id, event_receipt_id, workflow_id, workflow_revision,
				        execution_principal_user_id, definition_version, definition_json, definition_hash,
				        status, cursor, failure_code
				 FROM {$table} WHERE id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		);
		if ( null === $row ) {
			if ( '' !== (string) $wpdb->last_error ) {
				throw PersistenceFailure::database( 'load automation run snapshot' );
			}
			return null;
		}
		if ( ! is_array( $row ) ) {
			throw PersistenceFailure::definition();
		}
		return self::hydrate( $row );
	}

	public static function advance_cursor( int $run_id, string $expected_cursor, string $next_cursor ): bool {
		global $wpdb;
		if (
			$run_id < 1
			|| ! RunCursor::is_valid( $expected_cursor )
			|| ! RunCursor::is_valid( $next_cursor )
			|| $expected_cursor === $next_cursor
		) {
			throw new \InvalidArgumentException( 'Invalid automation run cursor transition.' );
		}
		$table = Schema::runs_table();
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET cursor = %s, updated_at = %s
				 WHERE id = %d AND cursor = %s AND status IN (%s, %s)",
				$next_cursor,
				current_time( 'mysql', true ),
				$run_id,
				$expected_cursor,
				RunStatus::Queued->value,
				RunStatus::Running->value
			)
		);
		if ( false === $result ) {
			throw PersistenceFailure::database( 'advance automation run cursor' );
		}
		return 1 === $result;
	}

	public static function transition_status( int $run_id, RunStatus $from, RunStatus $to, string $failure_code = '' ): bool {
		global $wpdb;
		if ( $run_id < 1 || ! RunStateMachine::allows( $from, $to ) ) {
			throw new \InvalidArgumentException( 'Invalid automation run status transition.' );
		}
		$failure_code = self::normalize_error_code( $failure_code );
		if ( in_array( $to, [ RunStatus::Running, RunStatus::Succeeded, RunStatus::Skipped ], true ) ) {
			$failure_code = '';
		}
		$table = Schema::runs_table();
		$now = current_time( 'mysql', true );

		if ( RunStatus::Running === $to ) {
			$sql = $wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, failure_code = '', started_at = COALESCE(started_at, %s), finished_at = NULL, updated_at = %s
				 WHERE id = %d AND status = %s",
				$to->value,
				$now,
				$now,
				$run_id,
				$from->value
			);
		} elseif ( $to->is_terminal() ) {
			$sql = $wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, failure_code = %s, finished_at = %s, updated_at = %s
				 WHERE id = %d AND status = %s",
				$to->value,
				$failure_code,
				$now,
				$now,
				$run_id,
				$from->value
			);
		} else {
			$sql = $wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, failure_code = %s, updated_at = %s
				 WHERE id = %d AND status = %s",
				$to->value,
				$failure_code,
				$now,
				$run_id,
				$from->value
			);
		}

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query is fully prepared above.
		if ( false === $result ) {
			throw PersistenceFailure::database( 'transition automation run status' );
		}
		if ( 1 === $result && $to->is_terminal() ) {
			RunContextRepository::purge( $run_id );
		}
		return 1 === $result;
	}

	/** @param array<string,mixed> $row */
	private static function hydrate( array $row ): RunRecord {
		$id = (int) ( $row['id'] ?? 0 );
		$event_receipt_id = (int) ( $row['event_receipt_id'] ?? 0 );
		$workflow_id = (int) ( $row['workflow_id'] ?? 0 );
		$workflow_revision = (int) ( $row['workflow_revision'] ?? 0 );
		$principal = (int) ( $row['execution_principal_user_id'] ?? 0 );
		$run_uuid = (string) ( $row['run_uuid'] ?? '' );
		$correlation_id = (string) ( $row['correlation_id'] ?? '' );
		$definition_json = (string) ( $row['definition_json'] ?? '' );
		$definition_hash = (string) ( $row['definition_hash'] ?? '' );
		$status = RunStatus::tryFrom( (string) ( $row['status'] ?? '' ) );
		$cursor = (string) ( $row['cursor'] ?? '' );
		$failure_code = self::normalize_error_code( (string) ( $row['failure_code'] ?? '' ) );

		if (
			$id < 1
			|| $event_receipt_id < 1
			|| $workflow_id < 1
			|| $workflow_revision < 1
			|| $principal < 1
			|| ! self::is_uuid( $run_uuid )
			|| ! self::is_uuid( $correlation_id )
			|| Definition::VERSION !== (int) ( $row['definition_version'] ?? 0 )
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $definition_hash )
			|| ! hash_equals( $definition_hash, hash( 'sha256', $definition_json ) )
			|| null === $status
			|| ! RunCursor::is_valid( $cursor )
		) {
			throw PersistenceFailure::definition();
		}

		try {
			$decoded = json_decode( $definition_json, true, 64, JSON_THROW_ON_ERROR );
			if ( ! is_array( $decoded ) ) {
				throw new \UnexpectedValueException( 'Run definition root is not an object.' );
			}
			$definition = DefinitionCodec::decode( $decoded );
		} catch ( \Throwable ) {
			throw PersistenceFailure::definition();
		}

		return new RunRecord(
			$id,
			$run_uuid,
			$correlation_id,
			$event_receipt_id,
			$workflow_id,
			$workflow_revision,
			$principal,
			$definition,
			$definition_hash,
			$status,
			$cursor,
			$failure_code
		);
	}

	private static function normalize_error_code( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_.:-]{0,190}$/D', $value ) ) {
			throw PersistenceFailure::definition();
		}
		return $value;
	}

	private static function is_uuid( string $value ): bool {
		return 1 === preg_match(
			'/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di',
			$value
		);
	}
}
