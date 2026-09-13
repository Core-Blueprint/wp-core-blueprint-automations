<?php
declare(strict_types=1);

namespace CB\Automations\Persistence;

use CB\Automations\Runtime\NodeType;
use CB\Automations\Runtime\RunStepAttempt;
use CB\Automations\Runtime\StepStatus;

defined( 'ABSPATH' ) || exit;

final class RunStepRepository {
	public static function interrupt_running_attempts( int $run_id, NodeType $type, string $node_id ): void {
		global $wpdb;
		self::assert_node( $run_id, $node_id );
		$table = Schema::run_steps_table();
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, error_code = %s, finished_at = %s
				 WHERE run_id = %d AND node_type = %s AND node_id = %s AND status = %s",
				StepStatus::Interrupted->value,
				'runtime.worker_interrupted',
				current_time( 'mysql', true ),
				$run_id,
				$type->value,
				$node_id,
				StepStatus::Running->value
			)
		);
		if ( false === $result ) {
			throw PersistenceFailure::database( 'close interrupted automation step attempts' );
		}
	}

	public static function find_running_attempt( int $run_id, NodeType $type, string $node_id ): ?RunStepAttempt {
		global $wpdb;
		self::assert_node( $run_id, $node_id );
		$table = Schema::run_steps_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, attempt FROM {$table}
				 WHERE run_id = %d AND node_type = %s AND node_id = %s AND status = %s
				 ORDER BY attempt DESC LIMIT 1",
				$run_id,
				$type->value,
				$node_id,
				StepStatus::Running->value
			),
			ARRAY_A
		);
		if ( null === $row ) {
			if ( '' !== (string) $wpdb->last_error ) {
				throw PersistenceFailure::database( 'find running automation step attempt' );
			}
			return null;
		}
		if ( ! is_array( $row ) || (int) ( $row['id'] ?? 0 ) < 1 || (int) ( $row['attempt'] ?? 0 ) < 1 ) {
			throw PersistenceFailure::definition();
		}
		return new RunStepAttempt( (int) $row['id'], (int) $row['attempt'] );
	}

	public static function begin(
		int $run_id,
		NodeType $type,
		string $node_id,
		string $provider = '',
		string $capability_id = '',
		string $schema_version = ''
	): RunStepAttempt {
		global $wpdb;
		self::assert_node( $run_id, $node_id );
		$table = Schema::run_steps_table();
		$last = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(attempt) FROM {$table} WHERE run_id = %d AND node_type = %s AND node_id = %s",
				$run_id,
				$type->value,
				$node_id
			)
		);
		if ( null === $last && '' !== (string) $wpdb->last_error ) {
			throw PersistenceFailure::database( 'resolve automation step attempt cursor' );
		}
		$attempt = max( 1, (int) $last + 1 );
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
				 (run_id, node_id, node_type, provider, capability_id, schema_version, attempt, status, error_code, provider_error_code, started_at, finished_at)
				 VALUES (%d, %s, %s, %s, %s, %s, %d, %s, '', '', %s, NULL)",
				$run_id,
				$node_id,
				$type->value,
				self::normalize_meta( $provider ),
				self::normalize_meta( $capability_id ),
				self::normalize_meta( $schema_version ),
				$attempt,
				StepStatus::Running->value,
				current_time( 'mysql', true )
			)
		);
		if ( false === $result || (int) $wpdb->insert_id < 1 ) {
			throw PersistenceFailure::database( 'begin automation step attempt' );
		}
		return new RunStepAttempt( (int) $wpdb->insert_id, $attempt );
	}

	public static function succeed( RunStepAttempt $attempt ): void { self::finish( $attempt, StepStatus::Succeeded, '', '' ); }
	public static function skip( RunStepAttempt $attempt ): void { self::finish( $attempt, StepStatus::Skipped, '', '' ); }
	public static function fail( RunStepAttempt $attempt, string $error_code, string $provider_error_code = '' ): void { self::finish( $attempt, StepStatus::Failed, $error_code, $provider_error_code ); }
	public static function block( RunStepAttempt $attempt, string $error_code, string $provider_error_code = '' ): void { self::finish( $attempt, StepStatus::Blocked, $error_code, $provider_error_code ); }
	public static function indeterminate( RunStepAttempt $attempt, string $error_code, string $provider_error_code = '' ): void { self::finish( $attempt, StepStatus::Indeterminate, $error_code, $provider_error_code ); }

	private static function finish( RunStepAttempt $attempt, StepStatus $status, string $error_code, string $provider_error_code ): void {
		global $wpdb;
		if ( ! in_array( $status, [ StepStatus::Succeeded, StepStatus::Skipped, StepStatus::Failed, StepStatus::Blocked, StepStatus::Indeterminate ], true ) ) {
			throw new \InvalidArgumentException( 'Invalid automation step completion status.' );
		}
		$table = Schema::run_steps_table();
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, error_code = %s, provider_error_code = %s, finished_at = %s
				 WHERE id = %d AND status = %s",
				$status->value,
				self::normalize_error_code( $error_code ),
				self::normalize_error_code( $provider_error_code ),
				current_time( 'mysql', true ),
				$attempt->id(),
				StepStatus::Running->value
			)
		);
		if ( false === $result ) {
			throw PersistenceFailure::database( 'finish automation step attempt' );
		}
		if ( 1 !== $result ) {
			throw PersistenceFailure::definition();
		}
	}

	private static function assert_node( int $run_id, string $node_id ): void {
		if ( $run_id < 1 || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $node_id ) ) {
			throw new \InvalidArgumentException( 'Invalid automation run node identity.' );
		}
	}

	private static function normalize_meta( string $value ): string {
		$value = trim( $value );
		if ( strlen( $value ) > 191 ) {
			throw new \InvalidArgumentException( 'Automation step metadata is too long.' );
		}
		return $value;
	}

	private static function normalize_error_code( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_.:-]{0,190}$/D', $value ) ) {
			throw new \InvalidArgumentException( 'Invalid automation step error code.' );
		}
		return $value;
	}
}
