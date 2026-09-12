<?php
declare(strict_types=1);

namespace CB\Automations\Persistence;

use CB\Automations\Runtime\Vault;

defined( 'ABSPATH' ) || exit;

final class RunContextRepository {
	/** @param array<string,mixed> $context */
	public static function put( int $run_id, array $context ): void {
		global $wpdb;
		if ( $run_id < 1 ) {
			throw new \InvalidArgumentException( 'Run context requires a positive run id.' );
		}

		$table = Schema::run_context_table();
		$now = current_time( 'mysql', true );
		$envelope = Vault::seal( $context, self::aad( $run_id ) );
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (run_id, context_envelope, created_at, updated_at)
				 VALUES (%d, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE context_envelope = VALUES(context_envelope), updated_at = VALUES(updated_at)",
				$run_id,
				$envelope,
				$now,
				$now
			)
		);
		if ( false === $result ) {
			throw PersistenceFailure::database( 'persist encrypted run context' );
		}
	}

	/** @return array<string,mixed>|null */
	public static function get( int $run_id ): ?array {
		global $wpdb;
		if ( $run_id < 1 ) {
			throw new \InvalidArgumentException( 'Run context requires a positive run id.' );
		}

		$table = Schema::run_context_table();
		$envelope = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT context_envelope FROM {$table} WHERE run_id = %d LIMIT 1",
				$run_id
			)
		);
		if ( null === $envelope ) {
			if ( '' !== (string) $wpdb->last_error ) {
				throw PersistenceFailure::database( 'load encrypted run context' );
			}
			return null;
		}
		if ( ! is_string( $envelope ) || '' === $envelope ) {
			throw PersistenceFailure::definition();
		}

		return Vault::open( $envelope, self::aad( $run_id ) );
	}

	public static function purge( int $run_id ): void {
		global $wpdb;
		if ( $run_id < 1 ) {
			throw new \InvalidArgumentException( 'Run context requires a positive run id.' );
		}

		$table = Schema::run_context_table();
		$result = $wpdb->delete( $table, [ 'run_id' => $run_id ], [ '%d' ] );
		if ( false === $result ) {
			throw PersistenceFailure::database( 'purge encrypted run context' );
		}
	}

	private static function aad( int $run_id ): string {
		return 'run-context:' . $run_id;
	}
}
