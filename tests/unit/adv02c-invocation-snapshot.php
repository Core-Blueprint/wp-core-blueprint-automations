<?php
declare(strict_types=1);

// C4 immutable-run/lease preflight with isolated SQL, journal and Vault doubles.
namespace CB\Automations\Capability {
	final class CapabilityReference {
		public function key(): string { return 'state:demo-provider:data.lookup:1'; }
		public function provider(): string { return 'demo-provider'; }
		public function id(): string { return 'data.lookup'; }
		public function schema_version(): string { return '1'; }
	}
}
namespace CB\Automations\Discovery {
	use CB\Automations\Capability\CapabilityReference;
	interface CapabilitySource { public function current( CapabilityReference $ref ): ?CapabilityDefinition; }
	final class CapabilityDefinition {
		public function reference(): CapabilityReference { return new CapabilityReference(); }
		public function required_capability(): ?string { return 'cb_run_demo'; }
	}
	final class Catalog implements CapabilitySource {
		public function current( CapabilityReference $ref ): ?CapabilityDefinition { return new CapabilityDefinition(); }
	}
}
namespace CB\Automations\Workflow {
	final class GraphDefinition { public const VERSION = 2; }
	final class GraphDefinitionCodec {
		public static function decode( array $document ): GraphDefinition {
			if ( ( $document['definition_version'] ?? null ) !== 2 ) throw new \RuntimeException( 'Invalid v2 definition.' );
			return new GraphDefinition();
		}
	}
}
namespace CB\Automations\Runtime {
	use CB\Automations\Discovery\CapabilitySource;
	use CB\Automations\Capability\CapabilityReference;
	final class RunExecutionLease {
		public bool $in_transaction = false;
		public function transaction( callable $cb ): mixed {
			if ( $this->in_transaction ) throw new \RuntimeException( 'Nested SQL transaction.' );
			$this->in_transaction = true;
			try { return $cb(); } finally { $this->in_transaction = false; }
		}
	}
	final class RunStepAttempt {}
	enum RunStatus: string { case Running = 'running'; }
	enum NodeType: string { case State = 'state'; }
	final class GraphCheckpointPlanner { public function __construct( CapabilitySource $source ) {} }
	final class GraphCheckpointJournal {
		public static string $branch = 'none';
		public static function from_array( array $journal ): self { return new self(); }
		public function to_array(): array { return [ 'entries' => [] ]; }
		public function replay( mixed $definition, mixed $planner, array $trigger ): array {
			return [
				'pending_branch' => self::$branch === 'none' ? null : [ 'node_id' => 'condition', 'decision' => true ],
				'status' => 'pending',
				'intent' => [ 'kind' => 'state', 'node_id' => 'state',
					'capability' => new CapabilityReference(), 'input' => [] ],
			];
		}
	}
}
namespace CB\Automations\Persistence {
	use CB\Automations\Runtime\NodeType;
	use CB\Automations\Runtime\RunStepAttempt;
	final class PersistenceFailure extends \RuntimeException {
		public static function definition(): self { return new self( 'Run definition fails closed.' ); }
	}
	final class Schema { public static function runs_table(): string { return 'wp_runs'; } }
	final class RunContextRepository {
		public static mixed $context = null;
		public static function get( int $run ): ?array { return self::$context; }
	}
	final class RunStepRepository {
		public static int $begins = 0;
		public static int $interrupts = 0;
		public static function interrupt_running_attempts( int $run, NodeType $type, string $node ): void { ++self::$interrupts; }
		public static function begin( int $run, NodeType $type, string $node, string $provider, string $id, string $version ): RunStepAttempt {
			++self::$begins;
			return new RunStepAttempt();
		}
	}
}
namespace {
	defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
	defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
	final class WP_User {}
	function get_userdata( int $user ): WP_User|false { return $user === 9 ? new WP_User() : false; }
	function user_can( int $user, string $capability ): bool { return $user === 9 && $capability === 'cb_run_demo'; }
	final class C4FakeSql {
		public string $last_error = '';
		public array $run = [];
		public ?\CB\Automations\Runtime\RunExecutionLease $lease = null;
		public function prepare( string $sql, mixed ...$args ): string { return $sql; }
		public function get_row( string $sql, string $mode ): ?array {
			if ( $this->lease?->in_transaction !== true || ! str_contains( $sql, 'FOR UPDATE' ) ) {
				throw new RuntimeException( 'Run row was read outside lease-fenced transaction.' );
			}
			return $this->run;
		}
	}
	require_once dirname( __DIR__, 2 ) . '/src/Runtime/GraphInvocationSnapshot.php';
	use CB\Automations\Discovery\Catalog;
	use CB\Automations\Persistence\RunContextRepository as Context;
	use CB\Automations\Persistence\RunStepRepository as Steps;
	use CB\Automations\Runtime\GraphCheckpointJournal as Journal;
	use CB\Automations\Runtime\GraphInvocationSnapshot as Snapshot;
	use CB\Automations\Runtime\RunExecutionLease;

	function snapshot_expect( bool $value, string $why ): void { if ( ! $value ) throw new RuntimeException( $why ); }
	function snapshot_refuse( callable $cb, string $why ): void {
		try { $cb(); } catch ( RuntimeException|UnexpectedValueException $e ) { return; }
		throw new RuntimeException( $why );
	}
	$lease = new RunExecutionLease();
	$catalog = new Catalog();
	$wpdb = new C4FakeSql();
	$wpdb->lease = $lease;
	$definition = json_encode( [ 'definition_version' => 2 ], JSON_THROW_ON_ERROR );
	$wpdb->run = [
		'id' => 21, 'workflow_id' => 3, 'workflow_revision' => 7,
		'execution_principal_user_id' => 9,
		'run_uuid' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
		'correlation_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
		'definition_version' => 2, 'definition_json' => $definition,
		'definition_hash' => hash( 'sha256', $definition ),
		'status' => 'running', 'run_cursor' => 'graph:0',
	];
	Context::$context = [ 'graph_checkpoint' => [ 'journal' => [ 'version' => 1, 'entries' => [] ], 'trigger_outputs' => [] ] ];
	$result = Snapshot::prepare( 21, 'graph:0', $lease, $catalog );
	snapshot_expect( $result['principal'] === 9 && $result['intent']['kind'] === 'state'
		&& Steps::$begins === 1 && Steps::$interrupts === 1 && $result['attempt'] !== null,
		'Valid locked State did not start exactly one attempt.' );
	snapshot_expect( !$lease->in_transaction, 'State lease transaction remained open.' );

	$wpdb->run['definition_hash'] = str_repeat( '0', 64 );
	snapshot_refuse( static fn () => Snapshot::prepare( 21, 'graph:0', $lease, $catalog ), 'Tampered immutable hash was accepted.' );
	$wpdb->run['definition_hash'] = hash( 'sha256', $definition );
	$wpdb->run['run_cursor'] = 'graph:1';
	snapshot_refuse( static fn () => Snapshot::prepare( 21, 'graph:0', $lease, $catalog ), 'Stale cursor was accepted.' );
	$wpdb->run['run_cursor'] = 'graph:0';
	$wpdb->run['execution_principal_user_id'] = 10;
	snapshot_refuse( static fn () => Snapshot::prepare( 21, 'graph:0', $lease, $catalog ), 'Invalid principal was accepted.' );
	$wpdb->run['execution_principal_user_id'] = 9;
	Journal::$branch = 'pending';
	snapshot_refuse( static fn () => Snapshot::prepare( 21, 'graph:0', $lease, $catalog ), 'Unacknowledged branch was skipped.' );
	Journal::$branch = 'none';
	Context::$context = null;
	snapshot_refuse( static fn () => Snapshot::prepare( 21, 'graph:0', $lease, $catalog ), 'Missing authenticated Vault context was accepted.' );
	snapshot_expect( Steps::$begins === 1 && Steps::$interrupts === 1, 'Rejected preflights created an attempt.' );
	echo "ADV-02C C4 immutable run, lease and principal preflight: PASS\n";
}