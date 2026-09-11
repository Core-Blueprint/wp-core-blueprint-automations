<?php
declare(strict_types=1);

namespace CB\Automations\Persistence;

use CB\Automations\Workflow\ActivationState;
use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\DefinitionCodec;
use JsonException;

defined( 'ABSPATH' ) || exit;

final class WorkflowRepository {
	public static function create( string $name, Definition $definition, int $user_id ): int {
		global $wpdb;

		$name = self::normalize_name( $name );
		if ( $user_id < 0 ) {
			throw new \InvalidArgumentException( 'Workflow user id cannot be negative.' );
		}

		$table = Schema::table();
		$now   = current_time( 'mysql', true );
		$json  = self::encode_definition( $definition );

		$result = $wpdb->insert(
			$table,
			[
				'name'               => $name,
				'activation_state'   => ActivationState::Disabled->value,
				'definition_version' => $definition->definition_version(),
				'definition_json'    => $json,
				'created_by'         => $user_id,
				'updated_by'         => $user_id,
				'created_at'         => $now,
				'updated_at'         => $now,
			],
			[ '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%s' ]
		);

		if ( false === $result || (int) $wpdb->insert_id < 1 ) {
			throw PersistenceFailure::database( 'create workflow' );
		}

		return (int) $wpdb->insert_id;
	}

	public static function find( int $id ): ?WorkflowRecord {
		global $wpdb;
		if ( $id < 1 ) {
			throw new \InvalidArgumentException( 'Workflow id must be positive.' );
		}

		$table = Schema::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, activation_state, definition_version, definition_json, revision, created_by, updated_by, created_at, updated_at FROM {$table} WHERE id = %d LIMIT 1",
				$id
			),
			ARRAY_A
		);

		if ( null === $row ) {
			if ( '' !== (string) $wpdb->last_error ) {
				throw PersistenceFailure::database( 'load workflow' );
			}
			return null;
		}

		return self::hydrate( $row );
	}

	/** @return WorkflowRecord[] */
	public static function list( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$table  = Schema::table();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, activation_state, definition_version, definition_json, revision, created_by, updated_by, created_at, updated_at
				 FROM {$table}
				 ORDER BY updated_at DESC, id DESC
				 LIMIT %d OFFSET %d",
				$limit,
				$offset
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			throw PersistenceFailure::database( 'list workflows' );
		}
		if ( [] === $rows && '' !== (string) $wpdb->last_error ) {
			throw PersistenceFailure::database( 'list workflows' );
		}

		$records = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw PersistenceFailure::definition();
			}
			$records[] = self::hydrate( $row );
		}
		return $records;
	}

	public static function count(): int {
		global $wpdb;

		$table = Schema::table();
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is a trusted plugin-owned identifier.
		if ( null === $count && '' !== (string) $wpdb->last_error ) {
			throw PersistenceFailure::database( 'count workflows' );
		}
		return max( 0, (int) $count );
	}

	/**
	 * Atomically replace editable workflow state when the caller still owns the
	 * expected revision. False means stale editor state or a missing workflow.
	 */
	public static function update(
		int $id,
		int $expected_revision,
		string $name,
		ActivationState $activation_state,
		Definition $definition,
		int $user_id
	): bool {
		global $wpdb;

		if ( $id < 1 || $expected_revision < 1 || $user_id < 0 ) {
			throw new \InvalidArgumentException( 'Invalid workflow update cursor.' );
		}

		$name  = self::normalize_name( $name );
		$table = Schema::table();
		$json  = self::encode_definition( $definition );
		$now   = current_time( 'mysql', true );

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET name = %s,
				     activation_state = %s,
				     definition_version = %d,
				     definition_json = %s,
				     revision = revision + 1,
				     updated_by = %d,
				     updated_at = %s
				 WHERE id = %d AND revision = %d",
				$name,
				$activation_state->value,
				$definition->definition_version(),
				$json,
				$user_id,
				$now,
				$id,
				$expected_revision
			)
		);

		if ( false === $result ) {
			throw PersistenceFailure::database( 'update workflow' );
		}

		return 1 === $result;
	}

	/** @param array<string,mixed> $row */
	private static function hydrate( array $row ): WorkflowRecord {
		$id       = (int) ( $row['id'] ?? 0 );
		$revision = (int) ( $row['revision'] ?? 0 );
		$state    = ActivationState::tryFrom( (string) ( $row['activation_state'] ?? '' ) );

		if ( $id < 1 || $revision < 1 || null === $state || Definition::VERSION !== (int) ( $row['definition_version'] ?? 0 ) ) {
			throw PersistenceFailure::definition();
		}

		try {
			$decoded = json_decode( (string) ( $row['definition_json'] ?? '' ), true, 64, JSON_THROW_ON_ERROR );
			if ( ! is_array( $decoded ) ) {
				throw new JsonException( 'Workflow definition root must be an object.' );
			}
			$definition = DefinitionCodec::decode( $decoded );
		} catch ( \Throwable $error ) {
			throw PersistenceFailure::definition();
		}

		$name = self::normalize_name( (string) ( $row['name'] ?? '' ) );

		return new WorkflowRecord(
			$id,
			$name,
			$state,
			$definition,
			$revision,
			max( 0, (int) ( $row['created_by'] ?? 0 ) ),
			max( 0, (int) ( $row['updated_by'] ?? 0 ) ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	private static function encode_definition( Definition $definition ): string {
		$json = wp_json_encode( DefinitionCodec::encode( $definition ) );
		if ( ! is_string( $json ) ) {
			throw PersistenceFailure::definition();
		}
		return $json;
	}

	private static function normalize_name( string $name ): string {
		$name = trim( wp_strip_all_tags( $name ) );
		$length = function_exists( 'mb_strlen' )
			? mb_strlen( $name, 'UTF-8' )
			: preg_match_all( '/./us', $name );

		if ( '' === $name || false === $length || $length > 191 ) {
			throw new \InvalidArgumentException( 'Workflow name must contain between 1 and 191 characters.' );
		}
		return $name;
	}
}
