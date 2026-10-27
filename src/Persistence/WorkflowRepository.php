<?php
declare(strict_types=1);

namespace CB\Automations\Persistence;

use CB\Automations\Runtime\TriggerKey;
use CB\Automations\Organisation\FolderRepository;
use CB\Automations\Workflow\ActivationState;
use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\DefinitionCodec;
use CB\Automations\Workflow\GraphDefinition;
use CB\Automations\Workflow\DefinitionDocumentCodec;
use CB\Automations\Workflow\PersistencePolicy;
use JsonException;

defined( 'ABSPATH' ) || exit;

final class WorkflowRepository {
	public static function create( string $name, Definition|GraphDefinition $definition, int $user_id ): int {
		if ( ! Schema::authoring_ready() ) { throw PersistenceFailure::schema(); }
		global $wpdb;

		$name = self::normalize_name( $name );
		if ( $user_id < 0 ) {
			throw new \InvalidArgumentException( 'Workflow user id cannot be negative.' );
		}

		$table = Schema::table();
		$now   = current_time( 'mysql', true );
		$json  = self::encode_definition( $definition );
		$trigger_key = $definition instanceof Definition ? TriggerKey::for_definition( $definition ) : '';

		$result = $wpdb->insert(
			$table,
			[
				'name'                        => $name,
				'activation_state'            => ActivationState::Disabled->value,
				'definition_version'          => $definition->definition_version(),
				'definition_json'             => $json,
				'execution_principal_user_id' => 0,
				'trigger_key'                 => $trigger_key,
				'created_by'                  => $user_id,
				'updated_by'                  => $user_id,
				'created_at'                  => $now,
				'updated_at'                  => $now,
			],
			[ '%s', '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%s', '%s' ]
		);

		if ( false === $result || (int) $wpdb->insert_id < 1 ) {
			error_log( '[Core Blueprint Automations] Workflow create database failure.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- fixed diagnostic; no raw SQL error text or input values.
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
				"SELECT id, name, activation_state, definition_version, definition_json, revision, execution_principal_user_id, trigger_key, created_by, updated_by, created_at, updated_at FROM {$table} WHERE id = %d LIMIT 1",
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

	/**
	 * Designer context names only. Corrupt JSON in another workflow must never
	 * block selecting a healthy workflow, and no definitions are hydrated here.
	 *
	 * @return list<array{id:int,name:string}>
	 */
	public static function list_context_metadata( int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$table = Schema::table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name FROM {$table}
				 WHERE definition_version = %d
				 ORDER BY updated_at DESC, id DESC
				 LIMIT %d OFFSET %d",
				Definition::VERSION,
				$limit,
				$offset
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || ( [] === $rows && '' !== (string) $wpdb->last_error ) ) {
			throw PersistenceFailure::database( 'list Designer context metadata' );
		}
		$items = [];
		foreach ( $rows as $row ) {
			$id = is_array( $row ) ? (int) ( $row['id'] ?? 0 ) : 0;
			$name = is_array( $row ) ? (string) ( $row['name'] ?? '' ) : '';
			if ( $id < 1 || '' === trim( $name ) ) {
				throw PersistenceFailure::definition();
			}
			$items[] = [ 'id' => $id, 'name' => $name ];
		}
		return $items;
	}

	/** @return WorkflowRecord[] */
	public static function list( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;

		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$table  = Schema::table();
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, activation_state, definition_version, definition_json, revision, execution_principal_user_id, trigger_key, created_by, updated_by, created_at, updated_at
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

		return self::hydrate_rows( $rows );
	}


	/**
	 * Safe database-backed search, filtered before pagination. Only allowlisted
	 * statuses and ORDER BY clauses can enter SQL; input never becomes SQL.
	 *
	 * @return WorkflowRecord[]
	 */
	public static function query_index( int $limit, int $offset, string $search = '', string $status = 'all', string $sort = 'updated_desc', ?int $folder_id = null ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$args = [];
		$where = self::index_where( $search, $status, $args, $folder_id );
		$order = self::index_order( $sort );
		$table = Schema::table();
		$args[] = $limit;
		$args[] = $offset;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, activation_state, definition_version, definition_json, revision, execution_principal_user_id, trigger_key, created_by, updated_by, created_at, updated_at
				 FROM {$table} {$where} ORDER BY {$order} LIMIT %d OFFSET %d",
				...$args
			),
			ARRAY_A
		);
		if ( ! is_array( $rows ) || ( [] === $rows && '' !== (string) $wpdb->last_error ) ) {
			throw PersistenceFailure::database( 'query workflow workspace' );
		}
		return self::hydrate_rows( $rows );
	}

	public static function count_index( string $search = '', string $status = 'all', ?int $folder_id = null ): int {
		global $wpdb;
		$args = [];
		$where = self::index_where( $search, $status, $args, $folder_id );
		$table = Schema::table();
		$sql = "SELECT COUNT(*) FROM {$table} {$where}";
		$count = [] === $args ? $wpdb->get_var( $sql ) : $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );
		if ( null === $count && '' !== (string) $wpdb->last_error ) {
			throw PersistenceFailure::database( 'count filtered workflows' );
		}
		return max( 0, (int) $count );
	}

	/** @param list<string> $args */
	private static function index_where( string $search, string $status, array &$args, ?int $folder_id = null ): string {
		global $wpdb;
		$clauses = [];
		if ( in_array( $status, [ 'enabled', 'disabled' ], true ) ) {
			$clauses[] = 'activation_state = %s';
			$args[] = $status;
		}
		if ( null !== $folder_id ) {
			$links = FolderRepository::assignments_table();
			$workflows = Schema::table();
			if ( 0 === $folder_id ) {
				$clauses[] = "NOT EXISTS (SELECT 1 FROM {$links} f WHERE f.workflow_id = {$workflows}.id)";
			} elseif ( $folder_id > 0 ) {
				$clauses[] = "EXISTS (SELECT 1 FROM {$links} f WHERE f.workflow_id = {$workflows}.id AND f.folder_id = %d)";
				$args[] = $folder_id;
			}
		}
		$search = trim( $search );
		if ( '' !== $search ) {
			$clauses[] = 'name LIKE %s';
			$args[] = '%' . $wpdb->esc_like( substr( $search, 0, 100 ) ) . '%';
		}
		return [] === $clauses ? '' : 'WHERE ' . implode( ' AND ', $clauses );
	}

	private static function index_order( string $sort ): string {
		return match ( $sort ) {
			'updated_asc' => 'updated_at ASC, id ASC',
			'name_asc' => 'name ASC, id ASC',
			'name_desc' => 'name DESC, id DESC',
			default => 'updated_at DESC, id DESC',
		};
	}

	/** @return WorkflowRecord[] */
	public static function list_enabled_for_trigger( string $trigger_key, int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		if ( '' === $trigger_key || ! TriggerKey::is_valid( $trigger_key ) ) {
			throw new \InvalidArgumentException( 'Runtime trigger key must be a canonical SHA-256 key.' );
		}

		$limit = max( 1, min( 500, $limit ) );
		$offset = max( 0, $offset );
		$table = Schema::table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, name, activation_state, definition_version, definition_json, revision, execution_principal_user_id, trigger_key, created_by, updated_by, created_at, updated_at
				 FROM {$table}
				 WHERE activation_state = %s AND trigger_key = %s AND definition_version = %d
				 ORDER BY id ASC
				 LIMIT %d OFFSET %d",
				ActivationState::Enabled->value,
				$trigger_key,
				Definition::VERSION,
				$limit,
				$offset
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			throw PersistenceFailure::database( 'match enabled workflows' );
		}
		if ( [] === $rows && '' !== (string) $wpdb->last_error ) {
			throw PersistenceFailure::database( 'match enabled workflows' );
		}

		return self::hydrate_rows( $rows );
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

	public static function update(
		int $id,
		int $expected_revision,
		string $name,
		ActivationState $activation_state,
		Definition|GraphDefinition $definition,
		int $execution_principal_user_id,
		int $user_id
	): bool {
		if ( ! Schema::authoring_ready() ) { throw PersistenceFailure::schema(); }
		global $wpdb;

		if ( $id < 1 || $expected_revision < 1 || $execution_principal_user_id < 0 || $user_id < 0 ) {
			throw new \InvalidArgumentException( 'Invalid workflow update cursor.' );
		}

		if ( $definition instanceof GraphDefinition && ActivationState::Disabled !== $activation_state ) {
			throw new \InvalidArgumentException( 'Advanced draft cannot be activated.' );
		}
		$name  = self::normalize_name( $name );
		$table = Schema::table();
		$json  = self::encode_definition( $definition );
		$now   = current_time( 'mysql', true );
		$trigger_key = $definition instanceof Definition ? TriggerKey::for_definition( $definition ) : '';

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				 SET name = %s,
				     activation_state = %s,
				     definition_version = %d,
				     definition_json = %s,
				     execution_principal_user_id = %d,
				     trigger_key = %s,
				     revision = revision + 1,
				     updated_by = %d,
				     updated_at = %s
				 WHERE id = %d AND revision = %d AND definition_version = %d",
				$name,
				$activation_state->value,
				$definition->definition_version(),
				$json,
				$execution_principal_user_id,
				$trigger_key,
				$user_id,
				$now,
				$id,
				$expected_revision,
				$definition->definition_version()
			)
		);

		if ( false === $result ) {
			throw PersistenceFailure::database( 'update workflow' );
		}

		return 1 === $result;
	}


	/**
	 * Permanently delete 1–25 disabled workflows atomically.
	 *
	 * All IDs, revisions and disabled states are locked and checked before
	 * the first DELETE. Historical runs retain their immutable snapshots.
	 *
	 * @param array<int,int> $expected Workflow ID => render-time revision.
	 * @return 'deleted'|'not_found'|'conflict'|'enabled'
	 */
	public static function delete_disabled_batch( array $expected ): string {
		if ( ! Schema::authoring_ready() || ! Schema::folders_ready() ) {
			throw PersistenceFailure::schema();
		}
		if ( count( $expected ) < 1 || count( $expected ) > 25 ) {
			throw new \InvalidArgumentException( 'Invalid workflow delete selection.' );
		}
		foreach ( $expected as $id => $revision ) {
			if ( ! is_int( $id ) || $id < 1 || ! is_int( $revision ) || $revision < 1 ) {
				throw new \InvalidArgumentException( 'Invalid workflow delete cursor.' );
			}
		}
		ksort( $expected, SORT_NUMERIC );
		global $wpdb;
		$table = Schema::table();
		$assignments = Schema::assignments_table();
		$ids = array_keys( $expected );
		$tokens = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			throw PersistenceFailure::database( 'begin workflow deletion' );
		}
		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, revision, activation_state FROM {$table} WHERE id IN ({$tokens}) ORDER BY id ASC FOR UPDATE",
					...$ids
				),
				ARRAY_A
			);
			if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
				throw PersistenceFailure::database( 'verify deletion targets' );
			}
			if ( count( $rows ) !== count( $expected ) ) {
				$wpdb->query( 'ROLLBACK' );
				return 'not_found';
			}
			foreach ( $rows as $row ) {
				$id = (int) ( $row['id'] ?? 0 );
				if ( ! isset( $expected[ $id ] ) ) {
					throw PersistenceFailure::database( 'verify deletion identity' );
				}
				if ( ActivationState::Enabled->value === (string) ( $row['activation_state'] ?? '' ) ) {
					$wpdb->query( 'ROLLBACK' );
					return 'enabled';
				}
				if ( ActivationState::Disabled->value !== (string) ( $row['activation_state'] ?? '' )
					|| $expected[ $id ] !== (int) ( $row['revision'] ?? 0 ) ) {
					$wpdb->query( 'ROLLBACK' );
					return 'conflict';
				}
			}
			foreach ( $expected as $id => $revision ) {
				// Assignment cleanup is transactional. No run, receipt or job tables are changed.
				if ( false === $wpdb->delete( $assignments, [ 'workflow_id' => $id ], [ '%d' ] ) ) {
					throw PersistenceFailure::database( 'remove workflow folder assignment' );
				}
				$deleted = $wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$table} WHERE id = %d AND revision = %d AND activation_state = %s",
						$id,
						$revision,
						ActivationState::Disabled->value
					)
				);
				if ( 1 !== $deleted ) {
					throw PersistenceFailure::database( 'delete verified workflow' );
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw PersistenceFailure::database( 'commit workflow deletion' );
			}
			return 'deleted';
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			throw $error;
		}
	}

	/** @param array<int,array<string,mixed>> $rows @return WorkflowRecord[] */
	private static function hydrate_rows( array $rows ): array {
		$records = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw PersistenceFailure::definition();
			}
			$records[] = self::hydrate( $row );
		}
		return $records;
	}

	/** @param array<string,mixed> $row */
	private static function hydrate( array $row ): WorkflowRecord {
		$id       = (int) ( $row['id'] ?? 0 );
		$revision = (int) ( $row['revision'] ?? 0 );
		$principal = (int) ( $row['execution_principal_user_id'] ?? -1 );
		$trigger_key = (string) ( $row['trigger_key'] ?? '' );
		$state    = ActivationState::tryFrom( (string) ( $row['activation_state'] ?? '' ) );

		if (
			$id < 1
			|| $revision < 1
			|| $principal < 0
			|| ! TriggerKey::is_valid( $trigger_key )
			|| null === $state
			|| ! in_array( (int) ( $row['definition_version'] ?? 0 ), [ Definition::VERSION, GraphDefinition::VERSION ], true )
		) {
			throw PersistenceFailure::definition();
		}

		try {
			$encoded = (string) ( $row['definition_json'] ?? '' );
			if ( ! PersistencePolicy::allows_encoded_definition( $encoded ) ) {
				throw new JsonException( 'Stored workflow definition exceeds the supported size.' );
			}
			$decoded = json_decode( $encoded, true, 64, JSON_THROW_ON_ERROR );
			if ( ! is_array( $decoded ) ) {
				throw new JsonException( 'Workflow definition root must be an object.' );
			}
			$definition = DefinitionDocumentCodec::decode( $decoded );
			if ( $definition->definition_version() !== (int) $row['definition_version'] ) {
				throw PersistenceFailure::definition();
			}
		} catch ( \Throwable ) {
			throw PersistenceFailure::definition();
		}

		if ( $definition instanceof GraphDefinition
			? ( ActivationState::Disabled !== $state || '' !== $trigger_key )
			: TriggerKey::for_definition( $definition ) !== $trigger_key ) {
			throw PersistenceFailure::definition();
		}

		$name = self::normalize_name( (string) ( $row['name'] ?? '' ) );

		return new WorkflowRecord(
			$id,
			$name,
			$state,
			$definition,
			$revision,
			$principal,
			$trigger_key,
			max( 0, (int) ( $row['created_by'] ?? 0 ) ),
			max( 0, (int) ( $row['updated_by'] ?? 0 ) ),
			(string) ( $row['created_at'] ?? '' ),
			(string) ( $row['updated_at'] ?? '' )
		);
	}

	private static function encode_definition( Definition|GraphDefinition $definition ): string {
		$json = wp_json_encode( DefinitionDocumentCodec::encode( $definition ) );
		if ( ! is_string( $json ) || ! PersistencePolicy::allows_encoded_definition( $json ) ) {
			throw PersistenceFailure::definition();
		}
		return $json;
	}

	private static function normalize_name( string $name ): string {
		$name = trim( wp_strip_all_tags( $name ) );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'Workflow name must contain between 1 and 191 characters.' );
		}

		$matches = [];
		$length  = preg_match_all( '/./us', $name, $matches );
		if ( false === $length || $length > 191 ) {
			throw new \InvalidArgumentException( 'Workflow name must contain between 1 and 191 characters.' );
		}

		return $name;
	}
}
