<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Persistence\JobRepository;
use CB\Automations\Support\Requirements;

defined( 'ABSPATH' ) || exit;

final class WorkerWakeup {
	public const HOOK = 'cb_automations_runtime_worker';

	public static function request_if_pending_events(): bool {
		return self::request_for_subjects( [ JobSubjectType::Event ] );
	}

	public static function request_if_pending(): bool {
		return self::request_for_subjects( [ JobSubjectType::Event, JobSubjectType::Run ] );
	}

	/** @param JobSubjectType[] $subjects */
	private static function request_for_subjects( array $subjects ): bool {
		if ( ! Requirements::execution_ready() ) {
			return false;
		}

		$next = null;
		foreach ( $subjects as $subject ) {
			$candidate = JobRepository::next_available_at( $subject );
			if ( null !== $candidate && ( null === $next || $candidate < $next ) ) {
				$next = $candidate;
			}
		}
		if ( null === $next ) {
			return true;
		}

		$timestamp = strtotime( $next . ' UTC' );
		if ( false === $timestamp ) {
			throw new \RuntimeException( 'Automation worker wake-up timestamp is invalid.' );
		}
		return self::request_at( max( time() + 1, $timestamp ) );
	}

	public static function request_at( int $timestamp ): bool {
		$timestamp = max( time() + 1, $timestamp );
		$scheduled = wp_next_scheduled( self::HOOK );
		if ( is_int( $scheduled ) && $scheduled <= $timestamp + 5 ) {
			return true;
		}

		$result = wp_schedule_single_event( $timestamp, self::HOOK, [], true );
		return ! is_wp_error( $result ) && true === $result;
	}
}
