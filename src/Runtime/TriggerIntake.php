<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Persistence\EventReceiptRepository;
use CB\Automations\Persistence\JobRepository;
use CB\Automations\Support\Requirements;
use CoreBlueprint\Core\Automation\TriggerEvent;

defined( 'ABSPATH' ) || exit;

final class TriggerIntake {
	private const REPAIR_OPTION = 'cb_automations_event_job_repair_hint';
	private const REPAIR_LIMIT = 100;
	private const REPAIR_GRACE_SECONDS = 5;
	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;
		add_action( 'core_blueprint_automation_trigger_emitted', [ self::class, 'accept' ], 10, 1 );

		if ( ! Requirements::execution_ready() ) {
			return;
		}

		try {
			self::repair_missing_event_jobs();
			WorkerWakeup::request_if_pending_events();
		} catch ( \Throwable ) {
			/* Runtime recovery remains durable and can retry on a later request. */
		}
	}

	public static function accept( TriggerEvent $event ): void {
		if ( ! Requirements::execution_ready() ) {
			return;
		}

		self::mark_repair_hint();
		WorkerWakeup::request_at( time() + 2 );

		try {
			$receipt_id = EventReceiptRepository::receive( $event );
			JobRepository::enqueue( JobSubjectType::Event, $receipt_id );
			WorkerWakeup::request_if_pending_events();
		} catch ( \Throwable ) {
			/* Producer requests must never fail because Automations intake failed. */
		}
	}

	public static function repair_missing_event_jobs(): void {
		if ( ! Requirements::execution_ready() ) {
			return;
		}

		$hint = get_option( self::REPAIR_OPTION, '' );
		if ( ! is_string( $hint ) || '' === $hint ) {
			return;
		}

		try {
			$ids = EventReceiptRepository::missing_event_job_ids( self::REPAIR_LIMIT );
			foreach ( $ids as $receipt_id ) {
				JobRepository::enqueue( JobSubjectType::Event, $receipt_id );
			}
			if ( [] !== $ids ) {
				WorkerWakeup::request_if_pending_events();
			}

			$hint_time = self::hint_time( $hint );
			if ( count( $ids ) < self::REPAIR_LIMIT && $hint_time > 0 && $hint_time <= time() - self::REPAIR_GRACE_SECONDS ) {
				self::delete_hint_if_unchanged( $hint );
			}
		} catch ( \Throwable ) {
			/* Keep the repair hint for a later request. */
		}
	}

	private static function mark_repair_hint(): void {
		try {
			$token = time() . ':' . bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable ) {
			$token = time() . ':' . wp_generate_uuid4();
		}
		update_option( self::REPAIR_OPTION, $token, false );
	}

	private static function hint_time( string $hint ): int {
		$parts = explode( ':', $hint, 2 );
		return isset( $parts[0] ) && ctype_digit( $parts[0] ) ? (int) $parts[0] : 0;
	}

	private static function delete_hint_if_unchanged( string $hint ): void {
		global $wpdb;
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::REPAIR_OPTION,
				$hint
			)
		);
		if ( 1 === $result ) {
			wp_cache_delete( self::REPAIR_OPTION, 'options' );
		}
	}
}
