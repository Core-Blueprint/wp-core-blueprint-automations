<?php
declare(strict_types=1);

namespace CB\Automations\Persistence;

use CB\Automations\Runtime\JobRecord;
use CB\Automations\Runtime\JobStatus;
use CB\Automations\Runtime\JobSubjectType;

defined( 'ABSPATH' ) || exit;

final class JobRepository {
	private const CLAIM_ATTEMPTS = 5;
	private const MIN_LEASE_SECONDS = 15;
	private const MAX_LEASE_SECONDS = 900;

	public static function enqueue( JobSubjectType $subject_type, int $subject_id, ?string $available_at = null ): int {
		global $wpdb;
		if ( $subject_id < 1 ) {
			throw new \InvalidArgumentException( 'Job subject id must be positive.' );
		}

		$table = Schema::jobs_table();
		$now = self::now();
		$available = null === $available_at ? $now : self::normalize_datetime( $available_at );
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
				 (subject_type, subject_id, status, available_at, lease_token, lease_expires_at, worker_attempts, last_error_code, created_at, updated_at)
				 VALUES (%s, %d, %s, %s, '', NULL, 0, '', %s, %s)
				 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
				$subject_type->value,
				$subject_id,
				JobStatus::Queued->value,
				$available,
				$now,
				$now
			)
		);
		if ( false === $result ) {
			throw PersistenceFailure::database( 'enqueue automation job' );
		}

		$id = (int) $wpdb->insert_id;
		if ( $id < 1 ) {
			$existing = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE subject_type = %s AND subject_id = %d LIMIT 1",
					$subject_type->value,
					$subject_id
				)
			);
			$id = (int) $existing;
		}
		if ( $id < 1 ) {
			throw PersistenceFailure::database( 'resolve enqueued automation job' );
		}
		return $id;
	}

	public static function claim( int $lease_seconds = 60 ): ?JobRecord {
		return self::claim_internal( null, $lease_seconds );
	}

	public static function claim_for( JobSubjectType $subject_type, int $lease_seconds = 60 ): ?JobRecord {
		return self::claim_internal( $subject_type, $lease_seconds );
	}

	public static function next_available_at( JobSubjectType $subject_type ): ?string {
		global $wpdb;
		$table = Schema::jobs_table();
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(CASE
				   WHEN status = %s THEN available_at
				   WHEN status = %s AND lease_expires_at IS NOT NULL THEN lease_expires_at
				   ELSE NULL
				 END)
				 FROM {$table}
				 WHERE subject_type = %s AND status IN (%s, %s)",
				JobStatus::Queued->value,
				JobStatus::Leased->value,
				$subject_type->value,
				JobStatus::Queued->value,
				JobStatus::Leased->value
			)
		);
		if ( null === $value ) {
			if ( '' !== (string) $wpdb->last_error ) {
				throw PersistenceFailure::database( 'find next automation job wake-up' );
			}
			return null;
		}
		if ( ! is_string( $value ) || '' === $value ) {
			throw PersistenceFailure::definition();
		}
		return self::normalize_datetime( $value );
	}

	public static function complete( int $id, string $lease_token ): bool {
		return self::finish_lease( $id, $lease_token, JobStatus::Complete, '' );
	}

	public static function fail( int $id, string $lease_token, string $error_code ): bool {
		return self::finish_lease( $id, $lease_token, JobStatus::Failed, self::normalize_error_code( $error_code ) );
	}

	public static function release_for_retry( int $id, string $lease_token, string $available_at, string $error_code = '' ): bool {
		global $wpdb;
		self::assert_lease_cursor( $id, $lease_token );
		$table = Schema::jobs_table();
		$now = self::now();
		$available = self::normalize_datetime( $available_at );
		$error = self::normalize_error_code( $error_code );
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s,
				     available_at = %s,
				     lease_token = '',
				     lease_expires_at = NULL,
				     last_error_code = %s,
				     updated_at = %s
				 WHERE id = %d AND status = %s AND lease_token = %s",
				JobStatus::Queued->value,
				$available,
				$error,
				$now,
				$id,
				JobStatus::Leased->value,
				$lease_token
			)
		);
		if ( false === $result ) {
			throw PersistenceFailure::database( 'release automation job for retry' );
		}
		return 1 === $result;
	}

	public static function find( int $id ): ?JobRecord {
		global $wpdb;
		if ( $id < 1 ) {
			throw new \InvalidArgumentException( 'Job id must be positive.' );
		}
		$table = Schema::jobs_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, subject_type, subject_id, status, available_at, lease_token, lease_expires_at, worker_attempts, last_error_code, created_at, updated_at
				 FROM {$table} WHERE id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		);
		if ( null === $row ) {
			if ( '' !== (string) $wpdb->last_error ) {
				throw PersistenceFailure::database( 'load automation job' );
			}
			return null;
		}
		if ( ! is_array( $row ) ) {
			throw PersistenceFailure::definition();
		}
		return self::hydrate( $row );
	}

	private static function claim_internal( ?JobSubjectType $subject_type, int $lease_seconds ): ?JobRecord {
		global $wpdb;
		$lease_seconds = max( self::MIN_LEASE_SECONDS, min( self::MAX_LEASE_SECONDS, $lease_seconds ) );
		$table = Schema::jobs_table();

		for ( $attempt = 0; $attempt < self::CLAIM_ATTEMPTS; $attempt++ ) {
			$now = self::now();
			if ( null === $subject_type ) {
				$candidate = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$table}
						 WHERE available_at <= %s
						   AND (status = %s OR (status = %s AND lease_expires_at IS NOT NULL AND lease_expires_at <= %s))
						 ORDER BY available_at ASC, id ASC LIMIT 1",
						$now,
						JobStatus::Queued->value,
						JobStatus::Leased->value,
						$now
					)
				);
			} else {
				$candidate = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM {$table}
						 WHERE subject_type = %s AND available_at <= %s
						   AND (status = %s OR (status = %s AND lease_expires_at IS NOT NULL AND lease_expires_at <= %s))
						 ORDER BY available_at ASC, id ASC LIMIT 1",
						$subject_type->value,
						$now,
						JobStatus::Queued->value,
						JobStatus::Leased->value,
						$now
					)
				);
			}

			if ( null === $candidate ) {
				if ( '' !== (string) $wpdb->last_error ) {
					throw PersistenceFailure::database( 'find claimable automation job' );
				}
				return null;
			}

			$id = (int) $candidate;
			if ( $id < 1 ) {
				throw PersistenceFailure::definition();
			}
			try {
				$token = bin2hex( random_bytes( 32 ) );
			} catch ( \Throwable ) {
				throw new \RuntimeException( 'Could not generate an automation job lease token.' );
			}
			$expires = gmdate( 'Y-m-d H:i:s', time() + $lease_seconds );

			if ( null === $subject_type ) {
				$result = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table}
						 SET status = %s, lease_token = %s, lease_expires_at = %s, worker_attempts = worker_attempts + 1, updated_at = %s
						 WHERE id = %d AND available_at <= %s
						   AND (status = %s OR (status = %s AND lease_expires_at IS NOT NULL AND lease_expires_at <= %s))",
						JobStatus::Leased->value,
						$token,
						$expires,
						$now,
						$id,
						$now,
						JobStatus::Queued->value,
						JobStatus::Leased->value,
						$now
					)
				);
			} else {
				$result = $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$table}
						 SET status = %s, lease_token = %s, lease_expires_at = %s, worker_attempts = worker_attempts + 1, updated_at = %s
						 WHERE id = %d AND subject_type = %s AND available_at <= %s
						   AND (status = %s OR (status = %s AND lease_expires_at IS NOT NULL AND lease_expires_at <= %s))",
						JobStatus::Leased->value,
						$token,
						$expires,
						$now,
						$id,
						$subject_type->value,
						$now,
						JobStatus::Queued->value,
						JobStatus::Leased->value,
						$now
					)
				);
			}

			if ( false === $result ) {
				throw PersistenceFailure::database( 'claim automation job' );
			}
			if ( 1 !== $result ) {
				continue;
			}

			$record = self::find( $id );
			if ( null === $record || JobStatus::Leased !== $record->status() || ! hash_equals( $token, $record->lease_token() ) ) {
				throw PersistenceFailure::definition();
			}
			return $record;
		}

		return null;
	}

	private static function finish_lease( int $id, string $lease_token, JobStatus $target, string $error_code ): bool {
		global $wpdb;
		self::assert_lease_cursor( $id, $lease_token );
		if ( ! in_array( $target, [ JobStatus::Complete, JobStatus::Failed ], true ) ) {
			throw new \InvalidArgumentException( 'Invalid terminal job status.' );
		}
		$table = Schema::jobs_table();
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, lease_token = '', lease_expires_at = NULL, last_error_code = %s, updated_at = %s
				 WHERE id = %d AND status = %s AND lease_token = %s",
				$target->value,
				$error_code,
				self::now(),
				$id,
				JobStatus::Leased->value,
				$lease_token
			)
		);
		if ( false === $result ) {
			throw PersistenceFailure::database( 'finish automation job lease' );
		}
		return 1 === $result;
	}

	/** @param array<string,mixed> $row */
	private static function hydrate( array $row ): JobRecord {
		$id = (int) ( $row['id'] ?? 0 );
		$subject_id = (int) ( $row['subject_id'] ?? 0 );
		$attempts = (int) ( $row['worker_attempts'] ?? -1 );
		$subject = JobSubjectType::tryFrom( (string) ( $row['subject_type'] ?? '' ) );
		$status = JobStatus::tryFrom( (string) ( $row['status'] ?? '' ) );
		$lease_token = (string) ( $row['lease_token'] ?? '' );
		$lease_expires = $row['lease_expires_at'] ?? null;
		if (
			$id < 1 || $subject_id < 1 || $attempts < 0 || null === $subject || null === $status
			|| ( '' !== $lease_token && 1 !== preg_match( '/^[a-f0-9]{64}$/D', $lease_token ) )
			|| ( null !== $lease_expires && ! is_string( $lease_expires ) )
		) {
			throw PersistenceFailure::definition();
		}
		if ( JobStatus::Leased === $status && ( '' === $lease_token || ! is_string( $lease_expires ) || '' === $lease_expires ) ) {
			throw PersistenceFailure::definition();
		}
		if ( JobStatus::Leased !== $status && ( '' !== $lease_token || null !== $lease_expires ) ) {
			throw PersistenceFailure::definition();
		}

		return new JobRecord(
			$id,
			$subject,
			$subject_id,
			$status,
			self::normalize_datetime( (string) ( $row['available_at'] ?? '' ) ),
			$lease_token,
			null === $lease_expires ? null : self::normalize_datetime( $lease_expires ),
			$attempts,
			self::normalize_error_code( (string) ( $row['last_error_code'] ?? '' ) ),
			self::normalize_datetime( (string) ( $row['created_at'] ?? '' ) ),
			self::normalize_datetime( (string) ( $row['updated_at'] ?? '' ) )
		);
	}

	private static function assert_lease_cursor( int $id, string $lease_token ): void {
		if ( $id < 1 || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $lease_token ) ) {
			throw new \InvalidArgumentException( 'Invalid job lease cursor.' );
		}
	}

	private static function normalize_error_code( string $code ): string {
		$code = trim( $code );
		if ( '' === $code ) {
			return '';
		}
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_.:-]{0,190}$/D', $code ) ) {
			throw new \InvalidArgumentException( 'Invalid automation job error code.' );
		}
		return $code;
	}

	private static function normalize_datetime( string $value ): string {
		$value = trim( $value );
		$formats = [ 'Y-m-d H:i:s.u', 'Y-m-d H:i:s' ];

		foreach ( $formats as $format ) {
			$parsed = \DateTimeImmutable::createFromFormat( '!' . $format, $value, new \DateTimeZone( 'UTC' ) );
			$errors = \DateTimeImmutable::getLastErrors();
			if (
				false !== $parsed
				&& ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) )
				&& $parsed->format( $format ) === $value
			) {
				return $parsed->format( 'Y-m-d H:i:s' );
			}
		}

		throw new \InvalidArgumentException( 'Invalid automation job UTC timestamp.' );
	}

	private static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
