<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Persistence\PersistenceFailure;
use CB\Automations\Persistence\RunLeaseRepository;

defined( 'ABSPATH' ) || exit;

final readonly class RunExecutionLease {
	private function __construct( private JobRecord $job ) {}

	public static function from_job( JobRecord $job, int $run_id ): self {
		if ( JobSubjectType::Run !== $job->subject_type() || $job->subject_id() !== $run_id || $run_id < 1 ) {
			throw new \InvalidArgumentException( 'Run execution lease does not match the durable run job.' );
		}
		return new self( $job );
	}

	public function renew( int $seconds = 300 ): void {
		if ( ! RunLeaseRepository::renew( $this->job, $seconds ) ) {
			throw new \RuntimeException( 'Automation run execution lease was lost.' );
		}
	}

	public function transaction( callable $callback ): mixed {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- worker-owned lease-fenced acknowledgement transaction.
			throw PersistenceFailure::database( 'start lease-fenced run transaction' );
		}
		try {
			RunLeaseRepository::lock_active( $this->job );
			$result = $callback();
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- worker-owned lease-fenced acknowledgement transaction.
				throw PersistenceFailure::database( 'commit lease-fenced run transaction' );
			}
			return $result;
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- worker-owned lease-fenced acknowledgement transaction.
			throw $error;
		}
	}
}
