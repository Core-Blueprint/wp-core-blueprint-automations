<?php
declare(strict_types=1);

namespace CB\Automations\Persistence;

use CB\Automations\Runtime\NodeType;
use CB\Automations\Runtime\RunStatus;
use CB\Automations\Runtime\StepStatus;

defined( 'ABSPATH' ) || exit;

final class RunHistoryRepository {
	/** @return array<int,array<string,mixed>> */
	public static function list( int $limit = 50, int $offset = 0, int $workflow_id = 0 ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$workflow_id = max( 0, $workflow_id );
		$table = Schema::runs_table();
		$fields = 'id, run_uuid, correlation_id, workflow_id, workflow_revision, execution_principal_user_id, status, run_cursor, failure_code, created_at, started_at, finished_at, updated_at';
		if ( $workflow_id > 0 ) {
			$sql = $wpdb->prepare(
				"SELECT {$fields} FROM {$table} WHERE workflow_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
				$workflow_id,
				$limit,
				$offset
			);
		} else {
			$sql = $wpdb->prepare( "SELECT {$fields} FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset );
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- query is fully prepared above.
		if ( ! is_array( $rows ) ) {
			throw PersistenceFailure::database( 'list automation run history' );
		}
		return array_map( [ self::class, 'hydrate_run' ], $rows );
	}

	public static function count( int $workflow_id = 0 ): int {
		global $wpdb;
		$workflow_id = max( 0, $workflow_id );
		$table = Schema::runs_table();
		$value = $workflow_id > 0
			? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE workflow_id = %d", $workflow_id ) )
			: $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- plugin-owned table identifier, no user input.
		if ( null === $value && '' !== (string) $wpdb->last_error ) {
			throw PersistenceFailure::database( 'count automation run history' );
		}
		return max( 0, (int) $value );
	}

	/** @return array<string,mixed>|null */
	public static function detail( int $run_id ): ?array {
		global $wpdb;
		if ( $run_id < 1 ) {
			throw new \InvalidArgumentException( 'Run history id must be positive.' );
		}
		$table = Schema::runs_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, run_uuid, correlation_id, workflow_id, workflow_revision, execution_principal_user_id, status, run_cursor, failure_code, created_at, started_at, finished_at, updated_at
				 FROM {$table} WHERE id = %d LIMIT 1",
				$run_id
			),
			ARRAY_A
		);
		if ( null === $row ) {
			if ( '' !== (string) $wpdb->last_error ) {
				throw PersistenceFailure::database( 'load automation run history' );
			}
			return null;
		}
		if ( ! is_array( $row ) ) {
			throw PersistenceFailure::definition();
		}
		return self::hydrate_run( $row );
	}

	/** @return array<int,array<string,mixed>> */
	public static function steps( int $run_id ): array {
		global $wpdb;
		if ( $run_id < 1 ) {
			throw new \InvalidArgumentException( 'Run step history id must be positive.' );
		}
		$table = Schema::run_steps_table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, node_id, node_type, provider, capability_id, schema_version, attempt, status, error_code, provider_error_code, started_at, finished_at
				 FROM {$table} WHERE run_id = %d ORDER BY id ASC",
				$run_id
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			throw PersistenceFailure::database( 'load automation step history' );
		}
		return array_map( [ self::class, 'hydrate_step' ], $rows );
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private static function hydrate_run( array $row ): array {
		$status = RunStatus::tryFrom( (string) ( $row['status'] ?? '' ) );
		$id = (int) ( $row['id'] ?? 0 );
		$workflow_id = (int) ( $row['workflow_id'] ?? 0 );
		$revision = (int) ( $row['workflow_revision'] ?? 0 );
		$principal = (int) ( $row['execution_principal_user_id'] ?? 0 );
		if ( $id < 1 || $workflow_id < 1 || $revision < 1 || $principal < 1 || null === $status ) {
			throw PersistenceFailure::definition();
		}
		return [
			'id' => $id,
			'run_uuid' => (string) ( $row['run_uuid'] ?? '' ),
			'correlation_id' => (string) ( $row['correlation_id'] ?? '' ),
			'workflow_id' => $workflow_id,
			'workflow_revision' => $revision,
			'execution_principal_user_id' => $principal,
			'status' => $status,
			'cursor' => (string) ( $row['run_cursor'] ?? '' ),
			'failure_code' => self::safe_code( (string) ( $row['failure_code'] ?? '' ) ),
			'created_at' => self::safe_time( $row['created_at'] ?? null ),
			'started_at' => self::safe_time( $row['started_at'] ?? null ),
			'finished_at' => self::safe_time( $row['finished_at'] ?? null ),
			'updated_at' => self::safe_time( $row['updated_at'] ?? null ),
		];
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private static function hydrate_step( array $row ): array {
		$status = StepStatus::tryFrom( (string) ( $row['status'] ?? '' ) );
		$type = NodeType::tryFrom( (string) ( $row['node_type'] ?? '' ) );
		$id = (int) ( $row['id'] ?? 0 );
		$attempt = (int) ( $row['attempt'] ?? 0 );
		if ( $id < 1 || $attempt < 1 || null === $status || null === $type ) {
			throw PersistenceFailure::definition();
		}
		return [
			'id' => $id,
			'node_id' => (string) ( $row['node_id'] ?? '' ),
			'node_type' => $type,
			'provider' => (string) ( $row['provider'] ?? '' ),
			'capability_id' => (string) ( $row['capability_id'] ?? '' ),
			'schema_version' => (string) ( $row['schema_version'] ?? '' ),
			'attempt' => $attempt,
			'status' => $status,
			'error_code' => self::safe_code( (string) ( $row['error_code'] ?? '' ) ),
			'provider_error_code' => self::safe_code( (string) ( $row['provider_error_code'] ?? '' ) ),
			'started_at' => self::safe_time( $row['started_at'] ?? null ),
			'finished_at' => self::safe_time( $row['finished_at'] ?? null ),
		];
	}

	private static function safe_code( string $value ): string {
		$value = trim( $value );
		return '' === $value || 1 === preg_match( '/^[a-z][a-z0-9_.:-]{0,190}$/D', $value ) ? $value : '';
	}

	private static function safe_time( mixed $value ): ?string {
		return is_string( $value ) && '' !== $value ? $value : null;
	}
}
