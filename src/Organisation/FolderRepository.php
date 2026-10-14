<?php
declare(strict_types=1);
namespace CB\Automations\Organisation;
use CB\Automations\Persistence\Schema;
defined( 'ABSPATH' ) || exit;

/** Additive admin-only organisation. Execution engine state is never mutated. */
final class FolderRepository {
	private const VERSION = '1';
	private const OPTION = 'cb_automations_folders_schema_version';
	private static bool $ready = false;
	public static function folders_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'cb_automations_folders';
	}
	public static function assignments_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'cb_automations_folder_assignments';
	}
	public static function ensure_ready(): void {
		if ( self::$ready ) { return; }
		global $wpdb;
		$folders = self::folders_table();
		$assignments = self::assignments_table();
		$version_current = self::VERSION === (string) get_option( self::OPTION, '' );
		if ( ! $version_current ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			$charset = $wpdb->get_charset_collate();
			dbDelta( "CREATE TABLE {$folders} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				created_at datetime(6) NOT NULL,
				updated_at datetime(6) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY name (name)
			) ENGINE=InnoDB {$charset};" );
			dbDelta( "CREATE TABLE {$assignments} (
				workflow_id bigint(20) unsigned NOT NULL,
				folder_id bigint(20) unsigned NOT NULL,
				PRIMARY KEY  (workflow_id),
				KEY folder_id (folder_id)
			) ENGINE=InnoDB {$charset};" );
		}
		foreach ( [ $folders, $assignments ] as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
			$state = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
			if ( $table !== $found || ! is_array( $state ) || 'innodb' !== strtolower( (string) ( $state['Engine'] ?? '' ) ) ) {
				throw new \RuntimeException( 'Workflow folders database schema is unavailable.' );
			}
		}
		if ( ! $version_current ) { update_option( self::OPTION, self::VERSION, false ); }
		self::$ready = true;
	}
	/** @return list<array{id:int,name:string}> */
	public static function all(): array {
		global $wpdb;
		$table = self::folders_table();
		$rows = $wpdb->get_results( "SELECT id, name FROM {$table} ORDER BY name ASC, id ASC LIMIT 1000", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted identifier.
		if ( ! is_array( $rows ) ) { throw new \RuntimeException( 'Could not list folders.' ); }
		return array_map( static fn( array $row ): array => [ 'id' => (int) $row['id'], 'name' => (string) $row['name'] ], $rows );
	}
	public static function normalize_name( string $submitted ): string {
		$name = trim( wp_strip_all_tags( $submitted ) );
		if ( '' === $name || strlen( $name ) > 191 ) { throw new \InvalidArgumentException( 'Folder name must be between 1 and 191 bytes.' ); }
		return $name;
	}
	public static function create( string $name ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$result = $wpdb->insert( self::folders_table(), [
			'name' => self::normalize_name( $name ), 'created_at' => $now, 'updated_at' => $now,
		], [ '%s', '%s', '%s' ] );
		if ( 1 !== $result || (int) $wpdb->insert_id < 1 ) { throw new \RuntimeException( 'Folder could not be created.' ); }
		return (int) $wpdb->insert_id;
	}
	public static function rename( int $id, string $name ): bool {
		global $wpdb;
		if ( $id < 1 ) { return false; }
		$result = $wpdb->update( self::folders_table(),
			[ 'name' => self::normalize_name( $name ), 'updated_at' => current_time( 'mysql', true ) ],
			[ 'id' => $id ], [ '%s', '%s' ], [ '%d' ] );
		if ( false === $result ) { throw new \RuntimeException( 'Folder rename failed.' ); }
		return self::exists( $id );
	}
	public static function exists( int $id ): bool {
		global $wpdb;
		if ( $id < 1 ) { return false; }
		$table = self::folders_table();
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE id = %d", $id ) );
	}
	/** Atomic folder deletion unfiles workflows, never deletes them. */
	public static function delete( int $id ): bool {
		global $wpdb;
		if ( $id < 1 ) { return false; }
		$folders = self::folders_table(); $assignments = self::assignments_table();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new \RuntimeException( 'Transaction unavailable.' ); }
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$folders} WHERE id = %d FOR UPDATE", $id ) );
			if ( null === $locked ) { $wpdb->query( 'ROLLBACK' ); return false; }
			if ( false === $wpdb->delete( $assignments, [ 'folder_id' => $id ], [ '%d' ] )
				|| 1 !== $wpdb->delete( $folders, [ 'id' => $id ], [ '%d' ] ) ) {
				throw new \RuntimeException( 'Folder deletion failed.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'Folder commit failed.' ); }
			return true;
		} catch ( \Throwable $error ) { $wpdb->query( 'ROLLBACK' ); throw $error; }
	}
	/** Folder id 0 removes an assignment. Both operations preserve workflow revisions. */
	public static function assign( int $workflow_id, int $folder_id ): bool {
		global $wpdb;
		if ( $workflow_id < 1 || $folder_id < 0 ) { return false; }
		$workflows = Schema::workflows_table();
		$folders = self::folders_table(); $assignments = self::assignments_table();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { throw new \RuntimeException( 'Transaction unavailable.' ); }
		try {
			$workflow = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$workflows} WHERE id = %d LIMIT 1", $workflow_id ) );
			if ( null === $workflow ) { $wpdb->query( 'ROLLBACK' ); return false; }
			if ( 0 === $folder_id ) {
				$result = $wpdb->delete( $assignments, [ 'workflow_id' => $workflow_id ], [ '%d' ] );
			} else {
				$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$folders} WHERE id = %d FOR UPDATE", $folder_id ) );
				if ( null === $locked ) { $wpdb->query( 'ROLLBACK' ); return false; }
				$result = $wpdb->query( $wpdb->prepare(
					"INSERT INTO {$assignments} (workflow_id, folder_id) VALUES (%d, %d)
					ON DUPLICATE KEY UPDATE folder_id = VALUES(folder_id)",
					$workflow_id, $folder_id
				) );
			}
			if ( false === $result ) { throw new \RuntimeException( 'Folder assignment failed.' ); }
			if ( false === $wpdb->query( 'COMMIT' ) ) { throw new \RuntimeException( 'Assignment commit failed.' ); }
			return true;
		} catch ( \Throwable $error ) { $wpdb->query( 'ROLLBACK' ); throw $error; }
	}
	/** @param list<int> $ids @return array<int,int> */
	public static function assignments( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) ) );
		if ( [] === $ids ) { return []; }
		$table = self::assignments_table();
		$tokens = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT workflow_id, folder_id FROM {$table} WHERE workflow_id IN ({$tokens})", ...$ids
		), ARRAY_A );
		if ( ! is_array( $rows ) ) { throw new \RuntimeException( 'Could not load folder assignments.' ); }
		$out = [];
		foreach ( $rows as $row ) { $out[ (int) $row['workflow_id'] ] = (int) $row['folder_id']; }
		return $out;
	}
}
