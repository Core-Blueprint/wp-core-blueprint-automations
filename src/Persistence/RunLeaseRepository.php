<?php
declare(strict_types=1);

namespace CB\Automations\Persistence;

use CB\Automations\Runtime\JobRecord;
use CB\Automations\Runtime\JobStatus;
use CB\Automations\Runtime\JobSubjectType;

defined( 'ABSPATH' ) || exit;

final class RunLeaseRepository {
	private const MIN_SECONDS = 60;
	private const MAX_SECONDS = 900;

	public static function renew( JobRecord $job, int $seconds = 300 ): bool {
		global $wpdb;
		self::assert_run_job( $job );
		$seconds = max( self::MIN_SECONDS, min( self::MAX_SECONDS, $seconds ) );
		$now = gmdate( 'Y-m-d H:i:s' );
		$expires = gmdate( 'Y-m-d H:i:s', time() + $seconds );
		$table = Schema::jobs_table();
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET lease_expires_at = %s, updated_at = %s
				 WHERE id = %d AND subject_type = %s AND subject_id = %d
				   AND status = %s AND lease_token = %s
				   AND lease_expires_at IS NOT NULL AND lease_expires_at > %s",
				$expires,
				$now,
				$job->id(),
				JobSubjectType::Run->value,
				$job->subject_id(),
				JobStatus::Leased->value,
				$job->lease_token(),
				$now
			)
		);
		if ( false === $result ) {
			throw PersistenceFailure::database( 'renew automation run execution lease' );
		}
		return 1 === $result || self::is_active( $job );
	}

	public static function is_active( JobRecord $job ): bool {
		global $wpdb;
		self::assert_run_job( $job );
		$table = Schema::jobs_table();
		$now = gmdate( 'Y-m-d H:i:s' );
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE id = %d AND subject_type = %s AND subject_id = %d
				   AND status = %s AND lease_token = %s
				   AND lease_expires_at IS NOT NULL AND lease_expires_at > %s
				 LIMIT 1",
				$job->id(),
				JobSubjectType::Run->value,
				$job->subject_id(),
				JobStatus::Leased->value,
				$job->lease_token(),
				$now
			)
		);
		if ( null === $id ) {
			if ( '' !== (string) $wpdb->last_error ) {
				throw PersistenceFailure::database( 'verify automation run execution lease' );
			}
			return false;
		}
		return (int) $id === $job->id();
	}

	public static function lock_active( JobRecord $job ): void {
		global $wpdb;
		self::assert_run_job( $job );
		$table = Schema::jobs_table();
		$now = gmdate( 'Y-m-d H:i:s' );
		$id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table}
				 WHERE id = %d AND subject_type = %s AND subject_id = %d
				   AND status = %s AND lease_token = %s
				   AND lease_expires_at IS NOT NULL AND lease_expires_at > %s
				 FOR UPDATE",
				$job->id(),
				JobSubjectType::Run->value,
				$job->subject_id(),
				JobStatus::Leased->value,
				$job->lease_token(),
				$now
			)
		);
		if ( null === $id ) {
			if ( '' !== (string) $wpdb->last_error ) {
				throw PersistenceFailure::database( 'lock automation run execution lease' );
			}
			throw new \RuntimeException( 'Automation run execution lease is no longer active.' );
		}
		if ( (int) $id !== $job->id() ) {
			throw PersistenceFailure::definition();
		}
	}

	private static function assert_run_job( JobRecord $job ): void {
		if (
			JobSubjectType::Run !== $job->subject_type()
			|| $job->id() < 1
			|| $job->subject_id() < 1
			|| JobStatus::Leased !== $job->status()
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $job->lease_token() )
		) {
			throw new \InvalidArgumentException( 'Invalid automation run execution lease.' );
		}
	}
}
