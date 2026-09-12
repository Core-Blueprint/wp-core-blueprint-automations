<?php
declare(strict_types=1);

namespace CB\Automations\Persistence;

use CB\Automations\Runtime\TriggerKey;
defined( 'ABSPATH' ) || exit;

final class Schema {
	private const OPTION_DB_VERSION = 'cb_automations_db_version';
	private const WORKFLOWS_SUFFIX = 'cb_automations_workflows';
	private const EVENT_RECEIPTS_SUFFIX = 'cb_automations_event_receipts';
	private const RUNS_SUFFIX = 'cb_automations_runs';
	private const RUN_STEPS_SUFFIX = 'cb_automations_run_steps';
	private const JOBS_SUFFIX = 'cb_automations_jobs';
	private const RUN_CONTEXT_SUFFIX = 'cb_automations_run_context';
	private const RUN_RECOVERIES_SUFFIX = 'cb_automations_run_recoveries';

	public static function table(): string {
		return self::workflows_table();
	}

	public static function workflows_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::WORKFLOWS_SUFFIX;
	}

	public static function event_receipts_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::EVENT_RECEIPTS_SUFFIX;
	}

	public static function runs_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::RUNS_SUFFIX;
	}

	public static function run_steps_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::RUN_STEPS_SUFFIX;
	}

	public static function jobs_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::JOBS_SUFFIX;
	}

	public static function run_context_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::RUN_CONTEXT_SUFFIX;
	}

	public static function run_recoveries_table(): string {
		global $wpdb;
		return $wpdb->prefix . self::RUN_RECOVERIES_SUFFIX;
	}

	public static function activate(): void {
		self::upgrade_from( (string) get_option( self::OPTION_DB_VERSION, '' ) );
	}

	public static function maybe_upgrade(): void {
		$current = (string) get_option( self::OPTION_DB_VERSION, '' );
		if ( CB_AUTOMATIONS_DB_VERSION === $current ) {
			return;
		}

		self::upgrade_from( $current );
	}

	public static function ready(): bool {
		return self::table_ready(
			self::workflows_table(),
			[
				'id', 'name', 'activation_state', 'definition_version', 'definition_json', 'revision',
				'execution_principal_user_id', 'trigger_key', 'created_by', 'updated_by', 'created_at', 'updated_at',
			],
			[
				'activation_state' => 'disabled',
				'definition_version' => '1',
				'revision' => '1',
				'execution_principal_user_id' => '0',
				'trigger_key' => '',
				'created_by' => '0',
				'updated_by' => '0',
			]
		)
			&& self::table_ready(
				self::event_receipts_table(),
				[
					'id', 'event_fingerprint', 'event_id', 'provider', 'trigger_id', 'schema_version',
					'occurred_at', 'payload_envelope', 'status', 'created_at', 'materialized_at', 'purged_at',
				]
			)
			&& self::table_ready(
				self::runs_table(),
				[
					'id', 'run_uuid', 'correlation_id', 'event_receipt_id', 'workflow_id', 'workflow_revision',
					'execution_principal_user_id', 'definition_version', 'definition_json', 'definition_hash',
					'status', 'run_cursor', 'failure_code', 'created_at', 'started_at', 'finished_at', 'updated_at',
				]
			)
			&& self::table_ready(
				self::run_steps_table(),
				[
					'id', 'run_id', 'node_id', 'node_type', 'provider', 'capability_id', 'schema_version',
					'attempt', 'status', 'error_code', 'provider_error_code', 'started_at', 'finished_at',
				]
			)
			&& self::table_ready(
				self::jobs_table(),
				[
					'id', 'subject_type', 'subject_id', 'status', 'available_at', 'lease_token', 'lease_expires_at',
					'worker_attempts', 'last_error_code', 'created_at', 'updated_at',
				]
			)
			&& self::table_ready(
				self::run_context_table(),
				[ 'run_id', 'context_envelope', 'created_at', 'updated_at' ]
			)
			&& self::table_ready(
				self::run_recoveries_table(),
				[
					'id', 'run_id', 'node_id', 'attempt', 'decision', 'operator_user_id', 'previous_status',
					'resulting_status', 'previous_cursor', 'resulting_cursor', 'created_at',
				]
			);
	}

	private static function upgrade_from( string $current ): void {
		self::create_schema();
		if ( '2' === CB_AUTOMATIONS_DB_VERSION && '2' !== $current ) {
			self::migrate_pre_v2_to_v2();
		}
		self::assert_ready();
		update_option( self::OPTION_DB_VERSION, CB_AUTOMATIONS_DB_VERSION, false );
	}

	private static function migrate_pre_v2_to_v2(): void {
		global $wpdb;
		$table = self::workflows_table();
		$rows = $wpdb->get_results( "SELECT id, definition_json FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted plugin-owned table.
		if ( ! is_array( $rows ) ) {
			throw PersistenceFailure::database( 'load workflows for AU2 migration' );
		}

		foreach ( $rows as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id < 1 ) {
				throw PersistenceFailure::definition();
			}
			$trigger_key = TriggerKey::from_encoded_json( (string) ( $row['definition_json'] ?? '' ) );
			$result = $wpdb->update(
				$table,
				[
					'activation_state' => 'disabled',
					'execution_principal_user_id' => 0,
					'trigger_key' => $trigger_key,
				],
				[ 'id' => $id ],
				[ '%s', '%d', '%s' ],
				[ '%d' ]
			);
			if ( false === $result ) {
				throw PersistenceFailure::database( 'migrate workflow execution authority' );
			}
		}
	}

	private static function assert_ready(): void {
		if ( ! self::ready() ) {
			throw PersistenceFailure::schema();
		}
	}

	/**
	 * @param string[] $required_columns
	 * @param array<string,string> $expected_defaults
	 */
	private static function table_ready( string $table, array $required_columns, array $expected_defaults = [] ): bool {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $table !== $found ) {
			return false;
		}

		$status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
		if ( ! is_array( $status ) || 'innodb' !== strtolower( (string) ( $status['Engine'] ?? '' ) ) ) {
			return false;
		}

		$rows = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- trusted plugin-owned table.
		if ( ! is_array( $rows ) ) {
			return false;
		}

		$columns = [];
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['Field'] ) ) {
				$columns[ (string) $row['Field'] ] = $row;
			}
		}

		if ( [] !== array_diff( $required_columns, array_keys( $columns ) ) ) {
			return false;
		}
		if ( isset( $columns['id'] ) && ! str_contains( strtolower( (string) ( $columns['id']['Extra'] ?? '' ) ), 'auto_increment' ) ) {
			return false;
		}

		foreach ( $expected_defaults as $field => $expected ) {
			if ( (string) ( $columns[ $field ]['Default'] ?? '' ) !== $expected ) {
				return false;
			}
		}

		return true;
	}

	private static function create_schema(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$workflows = self::workflows_table();
		$receipts = self::event_receipts_table();
		$runs = self::runs_table();
		$steps = self::run_steps_table();
		$jobs = self::jobs_table();
		$context = self::run_context_table();
		$recoveries = self::run_recoveries_table();

		dbDelta(
			"CREATE TABLE {$workflows} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL,
				activation_state varchar(16) NOT NULL DEFAULT 'disabled',
				definition_version int unsigned NOT NULL DEFAULT 1,
				definition_json longtext NOT NULL,
				revision bigint(20) unsigned NOT NULL DEFAULT 1,
				execution_principal_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				trigger_key char(64) NOT NULL DEFAULT '',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime(6) NOT NULL,
				updated_at datetime(6) NOT NULL,
				PRIMARY KEY  (id),
				KEY activation_state (activation_state),
				KEY trigger_lookup (activation_state, trigger_key),
				KEY updated_at (updated_at)
			) ENGINE=InnoDB {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$receipts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				event_fingerprint char(64) NOT NULL,
				event_id varchar(128) NOT NULL,
				provider varchar(191) NOT NULL,
				trigger_id varchar(191) NOT NULL,
				schema_version varchar(20) NOT NULL,
				occurred_at datetime(6) NOT NULL,
				payload_envelope longtext NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'received',
				created_at datetime(6) NOT NULL,
				materialized_at datetime(6) NULL DEFAULT NULL,
				purged_at datetime(6) NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY event_fingerprint (event_fingerprint),
				KEY status_created (status, created_at)
			) ENGINE=InnoDB {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$runs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_uuid char(36) NOT NULL,
				correlation_id char(36) NOT NULL,
				event_receipt_id bigint(20) unsigned NOT NULL,
				workflow_id bigint(20) unsigned NOT NULL,
				workflow_revision bigint(20) unsigned NOT NULL,
				execution_principal_user_id bigint(20) unsigned NOT NULL,
				definition_version int unsigned NOT NULL,
				definition_json longtext NOT NULL,
				definition_hash char(64) NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'queued',
				run_cursor varchar(128) NOT NULL DEFAULT '',
				failure_code varchar(191) NOT NULL DEFAULT '',
				created_at datetime(6) NOT NULL,
				started_at datetime(6) NULL DEFAULT NULL,
				finished_at datetime(6) NULL DEFAULT NULL,
				updated_at datetime(6) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY run_uuid (run_uuid),
				UNIQUE KEY event_workflow (event_receipt_id, workflow_id),
				KEY workflow_created (workflow_id, created_at),
				KEY status_updated (status, updated_at)
			) ENGINE=InnoDB {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$steps} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id bigint(20) unsigned NOT NULL,
				node_id varchar(64) NOT NULL,
				node_type varchar(16) NOT NULL,
				provider varchar(191) NOT NULL DEFAULT '',
				capability_id varchar(191) NOT NULL DEFAULT '',
				schema_version varchar(20) NOT NULL DEFAULT '',
				attempt int unsigned NOT NULL DEFAULT 1,
				status varchar(24) NOT NULL,
				error_code varchar(191) NOT NULL DEFAULT '',
				provider_error_code varchar(191) NOT NULL DEFAULT '',
				started_at datetime(6) NULL DEFAULT NULL,
				finished_at datetime(6) NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY node_attempt (run_id, node_type, node_id, attempt),
				KEY run_status (run_id, status)
			) ENGINE=InnoDB {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$jobs} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				subject_type varchar(16) NOT NULL,
				subject_id bigint(20) unsigned NOT NULL,
				status varchar(24) NOT NULL DEFAULT 'queued',
				available_at datetime(6) NOT NULL,
				lease_token char(64) NOT NULL DEFAULT '',
				lease_expires_at datetime(6) NULL DEFAULT NULL,
				worker_attempts int unsigned NOT NULL DEFAULT 0,
				last_error_code varchar(191) NOT NULL DEFAULT '',
				created_at datetime(6) NOT NULL,
				updated_at datetime(6) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY subject (subject_type, subject_id),
				KEY claimable (status, available_at),
				KEY lease_expires_at (lease_expires_at)
			) ENGINE=InnoDB {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$context} (
				run_id bigint(20) unsigned NOT NULL,
				context_envelope longtext NOT NULL,
				created_at datetime(6) NOT NULL,
				updated_at datetime(6) NOT NULL,
				PRIMARY KEY  (run_id)
			) ENGINE=InnoDB {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$recoveries} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id bigint(20) unsigned NOT NULL,
				node_id varchar(64) NOT NULL,
				attempt int unsigned NOT NULL,
				decision varchar(32) NOT NULL,
				operator_user_id bigint(20) unsigned NOT NULL,
				previous_status varchar(24) NOT NULL,
				resulting_status varchar(24) NOT NULL,
				previous_cursor varchar(128) NOT NULL,
				resulting_cursor varchar(128) NOT NULL,
				created_at datetime(6) NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY run_node_attempt (run_id, node_id, attempt),
				KEY run_created (run_id, created_at),
				KEY operator_created (operator_user_id, created_at)
			) ENGINE=InnoDB {$charset};"
		);
	}
}
