<?php
declare(strict_types=1);

/**
 * Golden-R3: SQL errors returning [] or null must not become healthy empty
 * results or erase the durable event repair hint. Isolated fake $wpdb.
 */
namespace CB\Automations\Persistence {
	final class Schema {
		public static function runs_table(): string { return 'wp_cb_runs'; }
		public static function run_steps_table(): string { return 'wp_cb_run_steps'; }
		public static function run_recoveries_table(): string { return 'wp_cb_run_recoveries'; }
		public static function event_receipts_table(): string { return 'wp_cb_event_receipts'; }
		public static function jobs_table(): string { return 'wp_cb_jobs'; }
		public static function folders_table(): string { return 'wp_cb_folders'; }
		public static function assignments_table(): string { return 'wp_cb_assignments'; }
		public static function workflows_table(): string { return 'wp_cb_workflows'; }
		public static function folders_ready(): bool { return true; }
	}
}
namespace CB\Automations\Runtime {
	enum JobSubjectType: string { case Event = 'event'; }
	final class JobRepository {
		public static function enqueue( JobSubjectType $type, int $id ): void {
			throw new \RuntimeException( 'Healthy empty repair must not enqueue anything.' );
		}
	}
	final class WorkerWakeup {
		public static function request_if_pending_events(): void {}
	}
}
namespace CB\Automations\Support {
	final class Requirements {
		public static function execution_ready(): bool { return true; }
	}
}
namespace {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );

	final class GoldenR3Wpdb {
		public string $last_error = '';
		public bool $fail_reads = false;
		public array $queries = [];
		public int $writes = 0;
		public string $options = 'wp_options';

		public function prepare( string $sql, mixed ...$args ): string { return $sql; }
		private function read(): void {
			$this->last_error = $this->fail_reads ? 'simulated SQL failure with sensitive value' : '';
		}
		public function get_results( string $sql, string $mode ): array {
			$this->read();
			return [];
		}
		public function get_col( string $sql ): array {
			$this->read();
			return [];
		}
		public function get_var( string $sql ): int|string|null {
			$this->read();
			if ( $this->fail_reads ) { return null; }
			return str_contains( $sql, 'COUNT(*)' ) ? 0 : null;
		}
		public function query( string $sql ): int {
			$this->queries[] = $sql;
			if ( str_starts_with( $sql, 'DELETE FROM wp_options' ) ) {
				$GLOBALS['golden_r3_repair_hint'] = '';
				return 1;
			}
			return 0;
		}
		public function insert( string $table, array $data, array $formats ): int {
			++$this->writes;
			return 1;
		}
		public function delete( string $table, array $where, array $formats ): int {
			++$this->writes;
			return 1;
		}
	}
	$GLOBALS['wpdb'] = new GoldenR3Wpdb();

	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['golden_r3_repair_hint'] ?? $default;
	}
	function wp_cache_delete( string $key, string $group = '' ): bool { return true; }

	require dirname( __DIR__, 2 ) . '/src/Persistence/PersistenceFailure.php';
	require dirname( __DIR__, 2 ) . '/src/Persistence/RunHistoryRepository.php';
	require dirname( __DIR__, 2 ) . '/src/Persistence/OperatorRecoveryRepository.php';
	require dirname( __DIR__, 2 ) . '/src/Persistence/EventReceiptRepository.php';
	require dirname( __DIR__, 2 ) . '/src/Organisation/FolderRepository.php';
	require dirname( __DIR__, 2 ) . '/src/Runtime/TriggerIntake.php';

	$db = $GLOBALS['wpdb'];
	$checks = [
		'Runs list' => static fn() => \CB\Automations\Persistence\RunHistoryRepository::list(),
		'Runs steps' => static fn() => \CB\Automations\Persistence\RunHistoryRepository::steps( 14 ),
		'Recovery history' => static fn() => \CB\Automations\Persistence\OperatorRecoveryRepository::history( 14 ),
		'Missing event jobs' => static fn() => \CB\Automations\Persistence\EventReceiptRepository::missing_event_job_ids(),
		'Folder list' => static fn() => \CB\Automations\Organisation\FolderRepository::all(),
		'Folder assignments' => static fn() => \CB\Automations\Organisation\FolderRepository::assignments( [ 14 ] ),
		'Folder count before create' => static fn() => \CB\Automations\Organisation\FolderRepository::create( 'Test' ),
		'Folder existence' => static fn() => \CB\Automations\Organisation\FolderRepository::exists( 14 ),
		'Folder delete lock' => static fn() => \CB\Automations\Organisation\FolderRepository::delete( 14 ),
		'Workflow assign lock' => static fn() => \CB\Automations\Organisation\FolderRepository::assign( 14, 2 ),
	];
	foreach ( $checks as $label => $operation ) {
		$db->fail_reads = true;
		$db->last_error = '';
		$db->queries = [];
		$db->writes = 0;
		$threw = false;
		try {
			$operation();
		} catch ( \CB\Automations\Persistence\PersistenceFailure | \RuntimeException ) {
			$threw = true;
		}
		if ( ! $threw ) {
			throw new \RuntimeException( $label . ' treated a failed query as a healthy empty result.' );
		}
		if ( 0 !== $db->writes ) {
			throw new \RuntimeException( $label . ' performed a mutation after database read failure.' );
		}
		if ( str_contains( $label, 'lock' ) && ! in_array( 'ROLLBACK', $db->queries, true ) ) {
			throw new \RuntimeException( $label . ' did not roll back after lock read failure.' );
		}
	}

	$db->fail_reads = false;
	foreach ( [
		'Runs list' => $checks['Runs list'],
		'Runs steps' => $checks['Runs steps'],
		'Recovery history' => $checks['Recovery history'],
		'Missing event jobs' => $checks['Missing event jobs'],
		'Folder list' => $checks['Folder list'],
		'Folder assignments' => $checks['Folder assignments'],
	] as $label => $operation ) {
		if ( [] !== $operation() ) {
			throw new \RuntimeException( $label . ' broke the valid empty-result path.' );
		}
	}
	if ( \CB\Automations\Organisation\FolderRepository::exists( 14 ) ) {
		throw new \RuntimeException( 'Healthy absent folder must remain absent.' );
	}
	if ( \CB\Automations\Organisation\FolderRepository::delete( 14 ) ) {
		throw new \RuntimeException( 'Healthy missing folder must not be reported deleted.' );
	}
	if ( \CB\Automations\Organisation\FolderRepository::assign( 14, 2 ) ) {
		throw new \RuntimeException( 'Healthy missing workflow must not be assigned.' );
	}

	$GLOBALS['golden_r3_repair_hint'] = ( time() - 30 ) . ':existing-repair';
	$db->fail_reads = true;
	$db->queries = [];
	\CB\Automations\Runtime\TriggerIntake::repair_missing_event_jobs();
	if ( '' === $GLOBALS['golden_r3_repair_hint']
		|| array_filter( $db->queries, static fn( string $sql ): bool => str_contains( $sql, 'DELETE FROM wp_options' ) ) ) {
		throw new \RuntimeException( 'A failed event-job query cleared the durable repair hint.' );
	}

	$db->fail_reads = false;
	$db->queries = [];
	\CB\Automations\Runtime\TriggerIntake::repair_missing_event_jobs();
	if ( '' !== $GLOBALS['golden_r3_repair_hint'] ) {
		throw new \RuntimeException( 'Successful empty event-job repair must clear the aged hint.' );
	}

	fwrite( STDOUT, "Golden-R3 SQL errors, transactions and event-repair hint: PASS\n" );
}
