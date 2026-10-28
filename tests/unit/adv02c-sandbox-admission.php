<?php
declare(strict_types=1);

namespace CB\Automations\Capability {
	final class CapabilityReference {
		private function __construct(private string $kind, private string $provider, private string $id, private string $version) {}
		public static function from_values( string $kind, string $provider, string $id, string $version ): ?self {
			return in_array( $kind, [ 'trigger', 'state', 'action' ], true ) ? new self( $kind, $provider, $id, $version ) : null;
		}
		public function key(): string { return "$this->kind:$this->provider:$this->id:$this->version"; }
		public function kind(): string { return $this->kind; }
		public function provider(): string { return $this->provider; }
		public function id(): string { return $this->id; }
		public function schema_version(): string { return $this->version; }
	}
}
namespace CB\Automations\Discovery {
	use CB\Automations\Capability\CapabilityReference;
	final class CapabilityDefinition {
		public function __construct(private CapabilityReference $reference) {}
		public function reference(): CapabilityReference { return $this->reference; }
		public function required_capability(): ?string { return 'trigger' === $this->reference->kind() ? null : 'cb_execute_demo'; }
		public function output_schema(): array { return 'trigger' === $this->reference->kind()
			? [ 'id' => [ 'type' => 'integer', 'required' => true ] ] : []; }
	}
	final class CapabilityCatalog {
		public function current( CapabilityReference $ref ): ?CapabilityDefinition { return new CapabilityDefinition( $ref ); }
	}
}
namespace CB\Automations\Validation {
	final class GraphValidator { public function __construct( mixed $catalog ) {} public function validate( mixed $graph ): object { return new class { public function is_valid(): bool { return true; } }; } }
}
namespace CB\Automations\Workflow {
	final class GraphDefinition {
		public const VERSION = 2;
		public function graph(): array {
			$ref = static fn ( string $kind, string $id ): array => [ 'kind' => $kind, 'provider' => 'core-blueprint', 'id' => $id, 'schema_version' => '1' ];
			return [ 'nodes' => [
				[ 'id' => 'trigger', 'type' => 'trigger', 'config' => [ 'capability' => $ref( 'trigger', 'test.received' ) ] ],
				[ 'id' => 'state', 'type' => 'state', 'config' => [ 'capability' => $ref( 'state', 'test.lookup' ) ] ],
				[ 'id' => 'action', 'type' => 'action', 'config' => [ 'capability' => $ref( 'action', 'test.create' ) ] ],
			] ];
		}
	}
	final class GraphDefinitionCodec {
		public static function decode( array $data ): GraphDefinition {
			if ( ( $data['definition_version'] ?? null ) !== 2 ) throw new \UnexpectedValueException( 'Bad test v2 definition.' );
			return new GraphDefinition();
		}
	}
}
namespace CB\Automations\Runtime {
	final class GraphRuntimeGate {
		public static bool $enabled = true;
		public static function require_cli(): void { if ( ! self::$enabled ) throw new \RuntimeException( 'Not admitted.' ); }
	}
	enum RunStatus: string { case Queued = 'queued'; }
	enum JobSubjectType: string { case Run = 'run'; }
	final class WorkerWakeup { public static int $calls = 0; public static function request_if_pending(): bool { ++self::$calls; return true; } }
	final class GraphCheckpointJournal { public static function empty(): self { return new self(); } public function to_array(): array { return [ 'version' => 1, 'entries' => [] ]; } }
}
namespace CB\Automations\Support {
	final class Requirements { public static function execution_ready(): bool { return true; } }
}
namespace CB\Automations\Persistence {
	final class PersistenceFailure extends \RuntimeException {
		public static function definition(): self { return new self( 'Stored definition invalid.' ); }
		public static function database( string $message ): self { return new self( $message ); }
	}
	final class Schema {
		public static function workflows_table(): string { return 'wp_workflows'; }
		public static function event_receipts_table(): string { return 'wp_receipts'; }
		public static function runs_table(): string { return 'wp_runs'; }
	}
	final class EventReceiptRepository {
		public static function fingerprint( string $provider, string $trigger, string $event ): string { return hash( 'sha256', implode( "\0", [ $provider, $trigger, $event ] ) ); }
	}
	final class RunContextRepository {
		public static ?array $value = null;
		public static function put( int $run_id, array $context ): void {
			if ( ! $GLOBALS['wpdb']->in_transaction ) throw new \RuntimeException( 'Unfenced context insert.' );
			self::$value = $context;
		}
	}
	final class JobRepository {
		public static int $calls = 0;
		public static function enqueue( mixed $type, int $run ): int {
			if ( ! $GLOBALS['wpdb']->in_transaction || $run !== 92 ) throw new \RuntimeException( 'Unfenced job.' );
			++self::$calls;
			return 7;
		}
	}
}
namespace {
	defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
	defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
	final class WP_User {}
	function get_userdata( int $id ): WP_User|false { return $id === 13 ? new WP_User() : false; }
	function user_can( int $id, string $capability ): bool { return $id === 13 && $capability === 'cb_execute_demo'; }
	function current_time( string $format, bool $gmt = false ): string { return '2026-10-28 12:00:00'; }
	function wp_generate_uuid4(): string { static $n = 0; ++$n; return 'aaaaaaaa-aaaa-4aaa-8aaa-' . str_pad( (string) $n, 12, '0', STR_PAD_LEFT ); }
	final class C5AdmissionSql {
		public string $last_error = '';
		public int $insert_id = 0;
		public bool $in_transaction = false;
		public array $receipt = [];
		public ?array $run = null;
		public array $workflow;
		public function __construct() {
			$this->workflow = [
				'id' => 11, 'revision' => 2, 'definition_version' => 2,
				'definition_json' => json_encode( [ 'definition_version' => 2 ] ),
				'activation_state' => 'disabled', 'execution_principal_user_id' => 0,
				'trigger_key' => '',
			];
		}
		public function prepare( string $sql, mixed ...$args ): string { return $sql; }
		public function query( string $sql ): int|false {
			if ( 'START TRANSACTION' === $sql ) { $this->in_transaction = true; return 1; }
			if ( 'COMMIT' === $sql || 'ROLLBACK' === $sql ) { $this->in_transaction = false; return 1; }
			if ( ! $this->in_transaction ) throw new \RuntimeException( 'Unfenced sandbox SQL write.' );
			if ( str_contains( $sql, 'INSERT INTO wp_receipts' ) ) {
				$this->insert_id = 91;
				$this->receipt['attempts'] = ( $this->receipt['attempts'] ?? 0 ) + 1;
				return 1;
			}
			if ( str_contains( $sql, 'INSERT INTO wp_runs' ) ) {
				$this->insert_id = 92;
				$this->receipt['new_runs'] = ( $this->receipt['new_runs'] ?? 0 ) + 1;
				$this->run = [ 'id' => 92,
					'definition_hash' => hash( 'sha256', $this->workflow['definition_json'] ),
					'workflow_revision' => 2, 'execution_principal_user_id' => 13 ];
				return 1;
			}
			throw new \RuntimeException( 'Unexpected sandbox write.' );
		}
		public function get_row( string $query, string $format ): ?array {
			if ( ! $this->in_transaction || ! str_contains( $query, 'FOR UPDATE' ) ) throw new \RuntimeException( 'Missing sandbox row lock.' );
			return str_contains( $query, 'wp_workflows' ) ? $this->workflow : $this->run;
		}
	}
	$wpdb = new C5AdmissionSql();
	require_once dirname( __DIR__, 2 ) . '/src/Runtime/GraphSandboxAdmission.php';
	use CB\Automations\Runtime\GraphSandboxAdmission as Admission;
	use CB\Automations\Runtime\GraphRuntimeGate as Gate;
	use CB\Automations\Persistence\RunContextRepository as Context;
	use CB\Automations\Persistence\JobRepository as Jobs;
	function c5_audit( bool $truth, string $why ): void { if ( ! $truth ) throw new RuntimeException( $why ); }
	function c5_deny( callable $fn, string $why ): void {
		try { $fn(); } catch ( \Throwable ) { return; }
		throw new RuntimeException( $why );
	}
	$uuid = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';
	Gate::$enabled = false;
	c5_deny( static fn () => Admission::enqueue( 11, $uuid, [ 'id' => 4 ], 13 ), 'Sandbox admission ignored explicit gate.' );
	Gate::$enabled = true;
	c5_deny( static fn () => Admission::enqueue( 11, $uuid, [ 'id' => 'string' ], 13 ), 'Malformed typed trigger admitted.' );
	c5_deny( static fn () => Admission::enqueue( 11, $uuid, [ 'id' => 4 ], 12 ), 'Unauthorized principal admitted.' );
	c5_audit( ! $wpdb->in_transaction && ! isset( $wpdb->receipt['attempts'] ), 'Rejected admission mutated SQL.' );
	$id = Admission::enqueue( 11, $uuid, [ 'id' => 4 ], 13 );
	c5_audit( $id === 92 && $wpdb->receipt['new_runs'] === 1 && Jobs::$calls === 1,
		'Valid CLI admission did not write exactly one run and job.' );
	c5_audit( Context::$value['advanced_admission'] === 'cli_sandbox_v1'
		&& Context::$value['graph_checkpoint']['trigger_outputs']['trigger']['id'] === 4,
		'CLI admission did not persist an authenticated trigger checkpoint.' );
	$id2 = Admission::enqueue( 11, $uuid, [ 'id' => 4 ], 13 );
	c5_audit( $id2 === 92 && $wpdb->receipt['new_runs'] === 1 && Jobs::$calls === 1,
		'Repeating the same operator UUID created another Action run.' );
	$wpdb->workflow['execution_principal_user_id'] = 8;
	c5_deny( static fn () => Admission::enqueue( 11, $uuid, [ 'id' => 4 ], 13 ),
		'CLI actor overrode a configured execution principal.' );
	echo "ADV-02C C5 CLI admission, exact trigger and duplicate-run isolation: PASS\n";
}