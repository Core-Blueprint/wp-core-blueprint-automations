<?php
declare(strict_types=1);

// C5 isolated worker-orchestration behavior using deterministic SQL/lease,
// encrypted-context and C2/C4 contract doubles. C6 tests real MySQL races.
namespace CB\Automations\Discovery {
	final class CapabilityCatalog {}
}
namespace CB\Automations\Workflow {
	final class GraphDefinition { public const VERSION = 2; }
	final class GraphDefinitionCodec {
		public static function decode( array $document ): GraphDefinition {
			if ( ( $document['definition_version'] ?? null ) !== 2 ) throw new \UnexpectedValueException( 'Bad snapshot.' );
			return new GraphDefinition();
		}
	}
}
namespace CB\Automations\Runtime {
	final class GraphRuntimeGate { public static bool $enabled = true; public static function enabled(): bool { return self::$enabled; } }
	final class GraphActionRecoveryFence { public static function before_vault( int $run, RunExecutionLease $lease ): ?RunStatus { $GLOBALS['c6_preflight'] = ( $GLOBALS['c6_preflight'] ?? 0 ) + 1; return null; } }
	final class JobRecord {
		public function subject_id(): int { return 21; }
		public function id(): int { return 90; }
		public function lease_token(): string { return str_repeat( 'a', 64 ); }
		public function worker_attempts(): int { return 2; }
	}
	final class RunExecutionLease {
		private bool $active = false;
		public static function from_job( JobRecord $job, int $run ): self {
			if ( $run !== $job->subject_id() ) throw new \RuntimeException( 'Invalid lease.' );
			return new self();
		}
		public function renew(): void { $GLOBALS['c5_renewals'] = ( $GLOBALS['c5_renewals'] ?? 0 ) + 1; }
		public function transaction( callable $fn ): mixed {
			if ( $this->active ) throw new \RuntimeException( 'Nested lease transaction.' );
			$this->active = true;
			try { return $fn(); } finally { $this->active = false; }
		}
	}
	enum RunStatus: string {
		case Queued = 'queued'; case Running = 'running'; case Succeeded = 'succeeded';
		case Blocked = 'blocked'; case Failed = 'failed'; case Indeterminate = 'indeterminate';
		public function halts_automatic_execution(): bool {
			return in_array( $this, [ self::Succeeded, self::Blocked, self::Failed, self::Indeterminate ], true );
		}
	}
	enum NodeType: string { case Action = 'action'; }
	enum StepStatus: string { case Running = 'running'; }
	final class RunStepAttempt { public function __construct( public int $id, public int $attempt ) {} }
	final class GraphCheckpointPlanner { public function __construct( mixed $catalog ) {} }
	final class GraphCheckpointJournal {
		public static function from_array( array $journal ): self { return new self(); }
		public function to_array(): array {
			return [ 'entries' => array_fill( 0, $GLOBALS['c5_phase'], [] ) ];
		}
		public function replay( mixed $definition, mixed $planner, array $output ): array {
			return match ( $GLOBALS['c5_phase'] ) {
				0 => [ 'status' => 'pending', 'pending_branch' => [ 'node_id' => 'branch', 'decision' => true ], 'intent' => null ],
				1 => [ 'status' => 'pending', 'pending_branch' => null, 'intent' => [ 'kind' => 'action', 'node_id' => 'action' ] ],
				default => [ 'status' => 'complete', 'pending_branch' => null, 'intent' => null ],
			};
		}
	}
	final class GraphProviderExecutor {
		public static int $calls = 0;
		public static function execute( int $run, string $cursor, mixed $lease, mixed $catalog ): array {
			++self::$calls;
			if ( $run !== 21 || $cursor !== 'graph:1' ) throw new \RuntimeException( 'Wrong Action position.' );
			$GLOBALS['wpdb']->run['run_cursor'] = 'graph:2';
			$GLOBALS['c5_phase'] = 2;
			return [ 'status' => 'checkpointed', 'cursor' => 'graph:2' ];
		}
	}
}
namespace CB\Automations\Persistence {
	use CB\Automations\Runtime\RunStatus;
	final class Schema {
		public static function runs_table(): string { return 'wp_runs'; }
		public static function run_steps_table(): string { return 'wp_steps'; }
	}
	final class PersistenceFailure extends \RuntimeException {
		public static function definition(): self { return new self( 'Invalid stored snapshot.' ); }
		public static function database( string $operation ): self { return new self( 'Database failure.' ); }
	}
	final class RunContextRepository {
		public static string $admission = 'cli_sandbox_v1';
		public static int $reads = 0;
		public static function get( int $run ): array {
			++self::$reads;
			return [ 'advanced_admission' => self::$admission, 'graph_checkpoint' => [
				'journal' => [ 'entries' => [] ], 'trigger_outputs' => [ 'trigger' => [ 'id' => 3 ] ],
			] ];
		}
	}
	final class RunRepository {
		public static function transition_status( int $run, RunStatus $from, RunStatus $to, string $error = '' ): bool {
			if ( $GLOBALS['wpdb']->run['status'] !== $from->value ) return false;
			$GLOBALS['wpdb']->run['status'] = $to->value;
			return true;
		}
	}
	final class GraphCheckpointStore {
		public static int $branches = 0;
		public static function commit_branch( int $run, mixed $lease, mixed $catalog, string $cursor ): string {
			if ( $run !== 21 || $cursor !== 'graph:0' ) throw new \RuntimeException( 'Incorrect branch checkpoint.' );
			++self::$branches;
			$GLOBALS['wpdb']->run['run_cursor'] = 'graph:1';
			$GLOBALS['c5_phase'] = 1;
			return 'graph:1';
		}
	}
	final class RunStepRepository {
		public static int $unknown = 0;
		public static function indeterminate( mixed $attempt, string $error ): void { ++self::$unknown; }
	}
	final class JobRepository {
		public static int $completed = 0, $failed = 0, $retry = 0;
		public static function complete( int $id, string $token ): bool { ++self::$completed; return true; }
		public static function fail( int $id, string $token, string $error ): bool { ++self::$failed; return true; }
		public static function release_for_retry( int $id, string $token, string $time, string $error ): bool {
			++self::$retry; return true;
		}
	}
}
namespace {
	defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
	defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
	final class C5Sql {
		public string $last_error = '';
		public ?array $action = null;
		public array $run;
		public function __construct() { $this->reset(); }
		public function reset(): void {
			$raw = json_encode( [ 'definition_version' => 2 ], JSON_THROW_ON_ERROR );
			$this->run = [ 'id' => 21, 'definition_version' => 2,
				'definition_json' => $raw, 'definition_hash' => hash( 'sha256', $raw ),
				'status' => 'queued', 'run_cursor' => 'graph:0', 'workflow_id' => 12,
				'workflow_revision' => 3, 'execution_principal_user_id' => 9 ];
			$this->action = null;
		}
		public function prepare( string $sql, mixed ...$args ): string { return $sql; }
		public function get_var( string $sql ): mixed { return $this->run['definition_version']; }
		public function get_row( string $sql, string $mode ): ?array {
			return str_contains( $sql, 'wp_steps' ) ? $this->action : $this->run;
		}
	}
	$wpdb = new C5Sql();
	require_once dirname( __DIR__, 2 ) . '/src/Runtime/GraphRunPipeline.php';
	use CB\Automations\Runtime\GraphRunPipeline as Pipeline;
	use CB\Automations\Runtime\GraphRuntimeGate as Gate;
	use CB\Automations\Runtime\GraphProviderExecutor as Provider;
	use CB\Automations\Runtime\RunStatus;
	use CB\Automations\Runtime\JobRecord;
	use CB\Automations\Persistence\RunContextRepository as Context;
	use CB\Automations\Persistence\GraphCheckpointStore as Store;
	use CB\Automations\Persistence\JobRepository as Jobs;
	use CB\Automations\Persistence\RunStepRepository as Steps;
	function c5_assert( bool $ok, string $why ): void { if ( ! $ok ) throw new RuntimeException( $why ); }
	function c5_reject( callable $f, string $why ): void {
		try { $f(); } catch ( \Throwable ) { return; }
		throw new RuntimeException( $why );
	}
	$job = new JobRecord();
	c5_assert( Pipeline::is_advanced_run( 21 ), 'v2 was classified as Standard.' );
	$wpdb->run['definition_version'] = 1;
	c5_assert( ! Pipeline::is_advanced_run( 21 ), 'Standard v1 was routed to Advanced.' );
	$wpdb->run['definition_version'] = 2;
	$GLOBALS['c5_phase'] = 0;
	c5_assert( Pipeline::execute( 21, $job ) === RunStatus::Succeeded,
		'Valid authenticated branch, Action and End did not complete.' );
	c5_assert( Store::$branches === 1 && Provider::$calls === 1 && $wpdb->run['run_cursor'] === 'graph:2',
		'Advanced route skipped checkpoint ordering or re-invoked provider.' );
	c5_assert( Pipeline::execute( 21, $job ) === RunStatus::Succeeded && Provider::$calls === 1,
		'Already-terminal run executed its Action a second time.' );

	$wpdb->reset(); $GLOBALS['c5_phase'] = 0;
	Context::$admission = 'forged';
	c5_reject( static fn () => Pipeline::execute( 21, $job ), 'Unknown admission evidence executed a v2 run.' );
	c5_assert( Provider::$calls === 1, 'Forged admission invoked an Action.' );
	Context::$admission = 'cli_sandbox_v1';
	$wpdb->run['definition_hash'] = str_repeat( '0', 64 );
	c5_reject( static fn () => Pipeline::execute( 21, $job ), 'Tampered immutable v2 hash admitted.' );
	c5_assert( Provider::$calls === 1, 'Tampered hash invoked an Action.' );

	$wpdb->reset(); Gate::$enabled = false;
	c5_reject( static fn () => Pipeline::execute( 21, $job ), 'Opt-out sandbox executed a v2 run.' );
	Gate::$enabled = true;
	$wpdb->run['status'] = 'running';
	$wpdb->action = [ 'id' => 101, 'attempt' => 1 ];
	$before = Context::$reads;
	Pipeline::handle_failure( $job, true );
	c5_assert( $wpdb->run['status'] === 'indeterminate' && Steps::$unknown === 1 && Jobs::$completed === 1,
		'Unknown Action was retried or not quarantined.' );
	c5_assert( Context::$reads === $before, 'Vault was opened before unknown Action quarantine.' );

	$wpdb->reset(); $wpdb->run['status'] = 'running';
	Pipeline::handle_failure( $job, false );
	c5_assert( Jobs::$retry === 1 && $wpdb->run['status'] === 'running', 'Safe worker failure was not backoff-recoverable.' );
	$wpdb->reset(); $wpdb->run['status'] = 'running';
	Pipeline::handle_failure( $job, true );
	c5_assert( Jobs::$failed === 1 && $wpdb->run['status'] === 'blocked', 'Missing Vault did not fail closed.' );
	echo "ADV-02C C5 deterministic v2 worker routing, End, retry and no-replay: PASS\n";
}