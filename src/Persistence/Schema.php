<?php
declare(strict_types=1);

namespace CB\Automations\Persistence;
defined( 'ABSPATH' ) || exit;

final class Schema {
	private const OPTION_DB_VERSION = 'cb_automations_db_version';
	private const TABLE_SUFFIX      = 'cb_automations_workflows';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	public static function activate(): void {
		self::create_table();
		self::assert_ready();
		update_option( self::OPTION_DB_VERSION, CB_AUTOMATIONS_DB_VERSION, false );
	}

	public static function maybe_upgrade(): void {
		$current = (string) get_option( self::OPTION_DB_VERSION, '' );
		if ( CB_AUTOMATIONS_DB_VERSION === $current ) {
			return;
		}

		self::create_table();
		self::assert_ready();
		update_option( self::OPTION_DB_VERSION, CB_AUTOMATIONS_DB_VERSION, false );
	}

	public static function ready(): bool {
		global $wpdb;
		$table = self::table();

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $table !== $found ) {
			return false;
		}

		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
		if ( ! is_array( $status ) || 'innodb' !== strtolower( (string) ( $status['Engine'] ?? '' ) ) ) {
			return false;
		}

		$rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is plugin-owned.
		if ( ! is_array( $rows ) ) {
			return false;
		}

		$columns = [];
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['Field'] ) ) {
				continue;
			}
			$columns[ (string) $row['Field'] ] = $row;
		}

		$required = [
			'id',
			'name',
			'activation_state',
			'definition_version',
			'definition_json',
			'revision',
			'created_by',
			'updated_by',
			'created_at',
			'updated_at',
		];
		if ( [] !== array_diff( $required, array_keys( $columns ) ) ) {
			return false;
		}

		if ( ! str_contains( strtolower( (string) ( $columns['id']['Extra'] ?? '' ) ), 'auto_increment' ) ) {
			return false;
		}

		$expected_defaults = [
			'activation_state'   => 'disabled',
			'definition_version' => '1',
			'revision'           => '1',
			'created_by'         => '0',
			'updated_by'         => '0',
		];
		foreach ( $expected_defaults as $field => $expected ) {
			if ( (string) ( $columns[ $field ]['Default'] ?? '' ) !== $expected ) {
				return false;
			}
		}

		return true;
	}

	private static function assert_ready(): void {
		if ( ! self::ready() ) {
			throw PersistenceFailure::schema();
		}
	}

	private static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				activation_state varchar(16) NOT NULL DEFAULT 'disabled',
				definition_version int unsigned NOT NULL DEFAULT 1,
				definition_json longtext NOT NULL,
				revision bigint(20) unsigned NOT NULL DEFAULT 1,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime(6) NOT NULL,
				updated_at datetime(6) NOT NULL,
				PRIMARY KEY  (id),
				KEY activation_state (activation_state),
				KEY updated_at (updated_at)
			) ENGINE=InnoDB {$charset};"
		);
	}
}
