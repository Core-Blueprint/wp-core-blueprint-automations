<?php
declare(strict_types=1);

// C4 behavioral boundary test: controlled doubles for the existing C2/C3
// persistence seam, real C4 dispatcher and exact Base invoker signatures.
namespace CoreBlueprint\Core\Automation {
	final class InvocationContext {
		public function __construct(
			public int $principal_user_id,
			public string $source,
			public string $correlation_id,
			public string $run_id,
			public string $step_id,
			public int $attempt,
			public ?string $workflow_id = null,
			public ?string $workflow_revision = null
		) {}
	}
	final class StateInvoker {
		public static int $calls = 0;
		public static mixed $result = [];
		public static ?array $arguments = null;
		public static function resolve(
			string $provider, string $id, string $version, array $input, InvocationContext $context
		): array|\WP_Error {
			++self::$calls;
			self::$arguments = [ $provider, $id, $version, $input, $context ];
			if ( self::$result instanceof \Throwable ) throw self::$result;
			return self::$result;
		}
	}
	final class ActionInvoker {
		public static int $calls = 0;
		public static mixed $result = [];
		public static ?array $arguments = null;
		public static function invoke(
			string $provider, string $id, string $version, array $input, InvocationContext $context
		): array|\WP_Error {
			++self::$calls;
			self::$arguments = [ $provider, $id, $version, $input, $context ];
			if ( self::$result instanceof \Throwable ) throw self::$result;
			return self::$result;
		}
	}
}
namespace CB\Automations\Capability {
	final class CapabilityReference {
		public function provider(): string { return 'core-blueprint-demo'; }
		public function id(): string { return 'record.create'; }
		public function schema_version(): string { return '1'; }
	}
}
namespace CB\Automations\Discovery {
	interface CapabilitySource {}
	final class FixtureCatalog implements CapabilitySource {}
}
namespace CB\Automations\Runtime {
	use CB\Automations\Capability\CapabilityReference;
	use CB\Automations\Discovery\CapabilitySource;
	final class RunStepAttempt {
		public function __construct(private int $attempt) {}
		public function attempt(): int { return $this->attempt; }
	}
	enum RunStatus: string {
		case Running = 'running';
		case Failed = 'failed';
		case Blocked = 'blocked';
		case Indeterminate = 'indeterminate';
	}
	final class RunExecutionLease {
		public int $renewals = 0;
		public int $transactions = 0;
		public function renew(): void { ++$this->renewals; }
		public function transaction( callable $cb ): mixed { ++$this->transactions; return $cb(); }
	}
	final class GraphInvocationSnapshot {
		public static string $kind = 'state';
		public static function prepare( int $id, string $cursor, RunExecutionLease $lease, CapabilitySource $catalog ): array {
			if ( 21 !== $id || 'graph:0' !== $cursor ) throw new \RuntimeException( 'Invalid C4 test identity.' );
			return [
				'workflow_id' => 3, 'workflow_revision' => 7, 'principal' => 9,
				'run_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
				'correlation_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
				'cursor' => $cursor, 'causality' => null,
				'intent' => [
					'kind' => self::$kind, 'node_id' => self::$kind === 'state' ? 'state' : 'action_true',
					'capability' => new CapabilityReference(), 'input' => [ 'name' => 'Approved' ],
				],
				'attempt' => self::$kind === 'state' ? new RunStepAttempt( 1 ) : null,
			];
		}
	}
	final class GraphProviderOutputGuard {
		public static bool $valid = true;
		public static function require_valid( mixed $catalog, mixed $reference, array $output ): void {
			if ( ! self::$valid ) throw new \UnexpectedValueException( 'Schema mismatch.' );
		}
	}
	final class GraphActionStartFence {
		public static int $calls = 0;
		public static ?RunStepAttempt $result = null;
		public static function begin(
			int $run_id, string $node_id, string $cursor, RunExecutionLease $lease, CapabilitySource $catalog
		): ?RunStepAttempt {
			++self::$calls;
			if ( 21 !== $run_id || 'action_true' !== $node_id || 'graph:0' !== $cursor ) {
				throw new \RuntimeException( 'C4 Action fence identity mismatch.' );
			}
			return self::$result;
		}
	}
	final class ActionCausality {
		public static int $calls = 0;
		public static function for_run( int $workflow, string $correlation, mixed $parent ): array {
			return [ 'correlation_id' => $correlation, 'workflow_path' => [ $workflow ] ];
		}
		public static function during_action( array $origin, callable $callback ): mixed {
			++self::$calls;
			return $callback();
		}
	}
}
namespace CB\Automations\Persistence {
	use CB\Automations\Runtime\RunStatus;
	use CB\Automations\Runtime\RunStepAttempt;
	final class GraphCheckpointStore {
		public static int $calls = 0;
		public static ?array $output = null;
		public static bool $commit_uncertain = false;
		public static function commit_completion(
			int $run_id, mixed $lease, mixed $catalog, string $cursor,
			RunStepAttempt $attempt, string $node_id, string $kind, array $output
		): string {
			++self::$calls;
			self::$output = $output;
			if ( self::$commit_uncertain ) throw new \RuntimeException( 'Uncertain SQL COMMIT.' );
			return 'graph:1';
		}
	}
	final class RunStepRepository {
		public static ?string $status = null;
		public static function block( RunStepAttempt $a, string $error, string $provider ): void { self::$status = 'blocked'; }
		public static function fail( RunStepAttempt $a, string $error, string $provider ): void { self::$status = 'failed'; }
		public static function indeterminate( RunStepAttempt $a, string $error, string $provider ): void { self::$status = 'indeterminate'; }
	}
	final class RunRepository {
		public static ?RunStatus $status = null;
		public static function transition_status( int $run, RunStatus $from, RunStatus $to, string $code = '' ): bool {
			self::$status = $to;
			return $run === 21 && $from === RunStatus::Running;
		}
	}
}
namespace {
	defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
	final class WP_Error {
		public function __construct(private string $code, private mixed $data = []) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_data(): mixed { return $this->data; }
	}
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ); }
	require_once dirname( __DIR__, 2 ) . '/src/Runtime/GraphProviderExecutor.php';

	use CB\Automations\Discovery\FixtureCatalog;
	use CB\Automations\Persistence\GraphCheckpointStore as Store;
	use CB\Automations\Persistence\RunRepository as Runs;
	use CB\Automations\Persistence\RunStepRepository as Steps;
	use CB\Automations\Runtime\ActionCausality;
	use CB\Automations\Runtime\GraphActionStartFence as Fence;
	use CB\Automations\Runtime\GraphInvocationSnapshot as Snapshot;
	use CB\Automations\Runtime\GraphProviderOutputGuard as Guard;
	use CB\Automations\Runtime\GraphProviderExecutor as Executor;
	use CB\Automations\Runtime\RunExecutionLease;
	use CoreBlueprint\Core\Automation\ActionInvoker;
	use CoreBlueprint\Core\Automation\StateInvoker;

	function c4_check( bool $yes, string $why ): void { if ( ! $yes ) throw new RuntimeException( $why ); }
	function c4_reset(): void {
		StateInvoker::$calls = ActionInvoker::$calls = 0;
		StateInvoker::$result = ActionInvoker::$result = [];
		Fence::$calls = ActionCausality::$calls = Store::$calls = 0;
		Fence::$result = new \CB\Automations\Runtime\RunStepAttempt( 2 );
		Store::$output = null;
		Store::$commit_uncertain = false;
		Guard::$valid = true;
		Steps::$status = Runs::$status = null;
	}
	$catalog = new FixtureCatalog();
	$lease = new RunExecutionLease();
	c4_reset();
	Snapshot::$kind = 'state';
	StateInvoker::$result = [ 'decision' => true ];
	$state = Executor::execute( 21, 'graph:0', $lease, $catalog );
	c4_check( $state === [ 'status' => 'checkpointed', 'cursor' => 'graph:1' ], 'State was not acknowledged.' );
	c4_check( StateInvoker::$calls === 1 && ActionInvoker::$calls === 0 && Fence::$calls === 0, 'State dispatch was not isolated.' );
	c4_check( Store::$output === [ 'decision' => true ], 'State output was not passed through unchanged.' );
	$ctx = StateInvoker::$arguments[4];
	c4_check( $ctx->principal_user_id === 9 && $ctx->source === 'automations' && $ctx->step_id === 'state'
		&& $ctx->attempt === 1 && $ctx->workflow_id === '3' && $ctx->workflow_revision === '7', 'State Base context differs from run identity.' );
	c4_check( StateInvoker::$arguments[0] === 'core-blueprint-demo' && StateInvoker::$arguments[2] === '1', 'State provider version altered.' );

	c4_reset();
	Snapshot::$kind = 'action';
	ActionInvoker::$result = [ 'created' => true ];
	$action = Executor::execute( 21, 'graph:0', $lease, $catalog );
	c4_check( $action['status'] === 'checkpointed' && Store::$output === [ 'created' => true ], 'Action result did not reach checkpoint.' );
	c4_check( Fence::$calls === 1 && ActionCausality::$calls === 1 && ActionInvoker::$calls === 1
		&& StateInvoker::$calls === 0, 'Action bypassed its start fence or causal scope.' );
	c4_check( ActionInvoker::$arguments[4]->step_id === 'action_true' && ActionInvoker::$arguments[4]->attempt === 2,
		'Action Base context lost its durable attempt.' );

	c4_reset();
	Snapshot::$kind = 'action';
	Fence::$result = null;
	$stopped = Executor::execute( 21, 'graph:0', $lease, $catalog );
	c4_check( $stopped['status'] === 'indeterminate' && ActionInvoker::$calls === 0 && Store::$calls === 0,
		'Quarantined Action was invoked again.' );

	c4_reset();
	Snapshot::$kind = 'action';
	ActionInvoker::$result = new WP_Error( 'cb_core_automation_execution_failed', [ 'provider_error_code' => 'provider_failed' ] );
	$unknown = Executor::execute( 21, 'graph:0', $lease, $catalog );
	c4_check( $unknown['status'] === 'indeterminate' && Steps::$status === 'indeterminate'
		&& Runs::$status?->value === 'indeterminate', 'Uncertain external mutation was not quarantined.' );

	c4_reset();
	Snapshot::$kind = 'state';
	StateInvoker::$result = new WP_Error( 'cb_core_automation_permission_denied' );
	$blocked = Executor::execute( 21, 'graph:0', $lease, $catalog );
	c4_check( $blocked['status'] === 'blocked' && Steps::$status === 'blocked', 'State principal denial did not block.' );

	c4_reset();
	Snapshot::$kind = 'state';
	StateInvoker::$result = new WP_Error( 'cb_core_automation_execution_failed' );
	$failed = Executor::execute( 21, 'graph:0', $lease, $catalog );
	c4_check( $failed['status'] === 'failed' && Steps::$status === 'failed', 'State execution failure was not settled.' );

	c4_reset();
	Snapshot::$kind = 'action';
	ActionInvoker::$result = [ 'invalid' => true ];
	Guard::$valid = false;
	$malformed = Executor::execute( 21, 'graph:0', $lease, $catalog );
	c4_check( $malformed['status'] === 'indeterminate' && Steps::$status === 'indeterminate'
		&& Store::$calls === 0, 'Invalid Action output was acknowledged or retried.' );

	c4_reset();
	Snapshot::$kind = 'action';
	ActionInvoker::$result = new RuntimeException( 'private provider error' );
	$thrown = Executor::execute( 21, 'graph:0', $lease, $catalog );
	c4_check( $thrown['status'] === 'indeterminate' && Steps::$status === 'indeterminate', 'Action exception was not quarantined.' );

	c4_reset();
	Snapshot::$kind = 'action';
	ActionInvoker::$result = [];
	Store::$commit_uncertain = true;
	try {
		Executor::execute( 21, 'graph:0', $lease, $catalog );
		throw new RuntimeException( 'Uncertain COMMIT was treated as success.' );
	} catch ( RuntimeException $e ) {
		c4_check( $e->getMessage() === 'Uncertain SQL COMMIT.' && Runs::$status === null,
			'Commit uncertainty caused an invented terminal state.' );
	}

	$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Runtime/RunPipeline.php' );
	c4_check( is_string( $source ) && ! str_contains( $source, 'GraphProviderExecutor' ),
		'C4 accidentally admitted Advanced execution in the Standard worker.' );
	echo "ADV-02C C4 authoritative Base invocation and no-replay boundary: PASS\n";
}