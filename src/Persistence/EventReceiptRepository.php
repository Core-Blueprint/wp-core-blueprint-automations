<?php
declare(strict_types=1);

namespace CB\Automations\Persistence;

use CoreBlueprint\Core\Automation\TriggerEvent;
use CB\Automations\Runtime\JobSubjectType;
use CB\Automations\Runtime\Vault;

defined( 'ABSPATH' ) || exit;

final class EventReceiptRepository {
	private const STATUS_RECEIVED = 'received';
	private const STATUS_MATERIALIZED = 'materialized';
	private const STATUS_IGNORED = 'ignored';
	private const STATUS_FAILED = 'failed';

	public static function receive( TriggerEvent $event ): int {
		global $wpdb;
		$fingerprint = self::fingerprint( $event->provider(), $event->trigger_id(), $event->event_id() );
		$envelope = Vault::seal( [ 'payload' => $event->payload() ], self::aad( $fingerprint ) );
		$table = Schema::event_receipts_table();
		$occurred = self::normalize_occurred_at( $event->occurred_at() );
		$now = current_time( 'mysql', true );

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table}
				 (event_fingerprint, event_id, provider, trigger_id, schema_version, occurred_at, payload_envelope, status, created_at, materialized_at, purged_at)
				 VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, NULL, NULL)
				 ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
				$fingerprint,
				$event->event_id(),
				$event->provider(),
				$event->trigger_id(),
				$event->schema_version(),
				$occurred,
				$envelope,
				self::STATUS_RECEIVED,
				$now
			)
		);
		if ( false === $result ) {
			throw PersistenceFailure::database( 'persist automation event receipt' );
		}

		$id = (int) $wpdb->insert_id;
		if ( $id < 1 ) {
			$id = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$table} WHERE event_fingerprint = %s LIMIT 1", $fingerprint )
			);
		}
		if ( $id < 1 ) {
			throw PersistenceFailure::database( 'resolve automation event receipt' );
		}
		return $id;
	}

	/** @return array<string,mixed>|null */
	public static function lock( int $receipt_id ): ?array {
		global $wpdb;
		if ( $receipt_id < 1 ) {
			throw new \InvalidArgumentException( 'Event receipt id must be positive.' );
		}
		$table = Schema::event_receipts_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, event_fingerprint, event_id, provider, trigger_id, schema_version, occurred_at, payload_envelope, status
				 FROM {$table} WHERE id = %d LIMIT 1 FOR UPDATE",
				$receipt_id
			),
			ARRAY_A
		);
		if ( null === $row ) {
			if ( '' !== (string) $wpdb->last_error ) {
				throw PersistenceFailure::database( 'lock automation event receipt' );
			}
			return null;
		}
		if ( ! is_array( $row ) ) {
			throw PersistenceFailure::definition();
		}
		self::assert_row( $row );
		return $row;
	}

	/** @param array<string,mixed> $receipt @return array<string,mixed> */
	public static function open_payload( array $receipt ): array {
		self::assert_row( $receipt );
		$envelope = (string) ( $receipt['payload_envelope'] ?? '' );
		if ( '' === $envelope ) {
			throw PersistenceFailure::definition();
		}
		$decoded = Vault::open( $envelope, self::aad( (string) $receipt['event_fingerprint'] ) );
		if ( [ 'payload' ] !== array_keys( $decoded ) || ! is_array( $decoded['payload'] ) ) {
			throw PersistenceFailure::definition();
		}
		return $decoded['payload'];
	}

	public static function finalize( int $receipt_id, bool $matched ): void {
		global $wpdb;
		if ( $receipt_id < 1 ) {
			throw new \InvalidArgumentException( 'Event receipt id must be positive.' );
		}
		$table = Schema::event_receipts_table();
		$now = current_time( 'mysql', true );
		$status = $matched ? self::STATUS_MATERIALIZED : self::STATUS_IGNORED;
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET status = %s, payload_envelope = '', materialized_at = %s, purged_at = %s
				 WHERE id = %d AND status = %s",
				$status,
				$now,
				$now,
				$receipt_id,
				self::STATUS_RECEIVED
			)
		);
		if ( false === $result ) {
			throw PersistenceFailure::database( 'finalize automation event receipt' );
		}
		if ( 1 !== $result ) {
			throw PersistenceFailure::definition();
		}
	}

	public static function mark_failed( int $receipt_id ): void {
		global $wpdb;
		if ( $receipt_id < 1 ) {
			throw new \InvalidArgumentException( 'Event receipt id must be positive.' );
		}
		$table = Schema::event_receipts_table();
		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = %s WHERE id = %d AND status = %s",
				self::STATUS_FAILED,
				$receipt_id,
				self::STATUS_RECEIVED
			)
		);
		if ( false === $result ) {
			throw PersistenceFailure::database( 'mark automation event receipt failed' );
		}
	}

	/** @return int[] */
	public static function missing_event_job_ids( int $limit = 100 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$receipts = Schema::event_receipts_table();
		$jobs = Schema::jobs_table();
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT r.id
				 FROM {$receipts} r
				 LEFT JOIN {$jobs} j ON j.subject_type = %s AND j.subject_id = r.id
				 WHERE r.status = %s AND j.id IS NULL
				 ORDER BY r.id ASC
				 LIMIT %d",
				JobSubjectType::Event->value,
				self::STATUS_RECEIVED,
				$limit
			)
		);
		if ( ! is_array( $rows ) ) {
			throw PersistenceFailure::database( 'find automation receipts missing jobs' );
		}
		$ids = array_map( 'intval', $rows );
		foreach ( $ids as $id ) {
			if ( $id < 1 ) {
				throw PersistenceFailure::definition();
			}
		}
		return $ids;
	}

	public static function is_terminal_status( string $status ): bool {
		return in_array( $status, [ self::STATUS_MATERIALIZED, self::STATUS_IGNORED, self::STATUS_FAILED ], true );
	}

	public static function fingerprint( string $provider, string $trigger_id, string $event_id ): string {
		if ( '' === $provider || '' === $trigger_id || '' === $event_id ) {
			throw new \InvalidArgumentException( 'Automation event identity cannot be empty.' );
		}
		return hash( 'sha256', implode( "\0", [ $provider, $trigger_id, $event_id ] ) );
	}

	/** @param array<string,mixed> $row */
	private static function assert_row( array $row ): void {
		$id = (int) ( $row['id'] ?? 0 );
		$fingerprint = (string) ( $row['event_fingerprint'] ?? '' );
		$status = (string) ( $row['status'] ?? '' );
		if (
			$id < 1
			|| 1 !== preg_match( '/^[a-f0-9]{64}$/D', $fingerprint )
			|| '' === (string) ( $row['event_id'] ?? '' )
			|| '' === (string) ( $row['provider'] ?? '' )
			|| '' === (string) ( $row['trigger_id'] ?? '' )
			|| '' === (string) ( $row['schema_version'] ?? '' )
			|| '' === (string) ( $row['occurred_at'] ?? '' )
			|| ! in_array( $status, [ self::STATUS_RECEIVED, self::STATUS_MATERIALIZED, self::STATUS_IGNORED, self::STATUS_FAILED ], true )
		) {
			throw PersistenceFailure::definition();
		}
	}

	private static function normalize_occurred_at( string $value ): string {
		try {
			$date = new \DateTimeImmutable( $value );
		} catch ( \Throwable ) {
			throw PersistenceFailure::definition();
		}
		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	private static function aad( string $fingerprint ): string {
		return 'event-receipt:' . $fingerprint;
	}
}
