<?php
declare(strict_types=1);

// Reuse the real B3/C1 graph, codec and provider fixtures in isolation.
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
function wp_salt( string $scheme = 'auth' ): string {
	return 'automations-adv02c-test-' . $scheme . '-ef0a4e6b5c';
}
function current_time( string $type, bool $gmt = false ): string {
	return '2026-10-28 12:00:00';
}
require_once __DIR__ . '/adv02c-checkpoint-journal.php';

use CB\Automations\Persistence\GraphCheckpointStore;
use CB\Automations\Runtime\GraphCheckpointJournal;
use CB\Automations\Runtime\JobRecord;
use CB\Automations\Runtime\JobStatus;
use CB\Automations\Runtime\JobSubjectType;
use CB\Automations\Runtime\RunExecutionLease;
use CB\Automations\Runtime\RunStepAttempt;
use CB\Automations\Runtime\Vault;

function c2_assert( bool $ok, string $label ): void {
	if ( ! $ok ) throw new RuntimeException( $label );
}
function c2_reject( callable $callback, string $label ): void {
	try {
		$callback();
	} catch ( Throwable ) {
		return;
	}
	throw new RuntimeException( $label );
}

/**
 * Deterministic fake SQL surface with full transaction snapshot/rollback.
 * Exercises actual C2 store, existing lease repository, RunStepRepository,
 * RunContextRepository, and authenticated Sodium Vault; no WordPress network.
 */
final class Adv02cCheckpointWpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $insert_id = 0;
	public array $run = [];
	public array $job = [];
	public array $steps = [];
	public ?string $context = null;
	public bool $fail_context = false;
	public bool $fail_step = false;
	public bool $fail_cursor = false;
	public bool $fail_commit = false;
	public bool $lease_active = true;
	public int $rollbacks = 0;
	public int $commits = 0;
	public int $writes = 0;
	private ?array $transaction = null;
	private array $stmts = [];
	private int $serial = 0;

	public function prepare( string $query, mixed ...$args ): string {
		$id = ++$this->serial;
		$this->stmts[ $id ] = [ $query, $args ];
		return 'TEST_SQL_' . $id;
	}
	private function decode( string $query ): array {
		if ( preg_match( '/^TEST_SQL_([0-9]+)$/D', $query, $m ) ) {
			return $this->stmts[ (int) $m[1] ];
		}
		return [ $query, [] ];
	}
	public function get_row( string $query, string $mode = ARRAY_A ): ?array {
		[ $sql, $args ] = $this->decode( $query );
		if ( str_contains( $sql, 'FROM ' . $this->prefix . 'cb_automations_runs' ) && str_contains( $sql, 'FOR UPDATE' ) ) {
			return $this->run['id'] === (int) $args[0] ? $this->run : null;
		}
		if ( str_contains( $sql, 'FROM ' . $this->prefix . 'cb_automations_run_steps' ) && str_contains( $sql, 'FOR UPDATE' ) ) {
			return $this->steps[ (int) $args[0] ] ?? null;
		}
		throw new RuntimeException( 'Unexpected C2 SELECT row: ' . $sql );
	}
	public function get_var( string $query ): mixed {
		[ $sql, $args ] = $this->decode( $query );
		if ( str_contains( $sql, 'FROM ' . $this->prefix . 'cb_automations_jobs' ) && str_contains( $sql, 'FOR UPDATE' ) ) {
			$job = $this->job;
			return $this->lease_active && $job['id'] === (int) $args[0]
				&& $job['run_id'] === (int) $args[2] && $job['token'] === $args[4]
				? $job['id'] : null;
		}
		if ( str_contains( $sql, 'FROM ' . $this->prefix . 'cb_automations_run_context' ) ) {
			return (int) $args[0] === $this->run['id'] ? $this->context : null;
		}
		if ( str_contains( $sql, 'SELECT MAX(attempt)' ) ) {
			$last = null;
			foreach ( $this->steps as $step ) {
				if ( $step['run_id'] === (int) $args[0]
					&& $step['node_type'] === $args[1]
					&& $step['node_id'] === $args[2] ) {
					$last = max( $last ?? 0, $step['attempt'] );
				}
			}
			return $last;
		}
		throw new RuntimeException( 'Unexpected C2 SELECT scalar: ' . $sql );
	}
	public function query( string $query ): int|false {
		if ( 'START TRANSACTION' === $query ) {
			if ( null !== $this->transaction ) throw new RuntimeException( 'Nested C2 transaction.' );
			$this->transaction = [ $this->run, $this->steps, $this->context, $this->insert_id, $this->writes ];
			return 1;
		}
		if ( 'ROLLBACK' === $query ) {
			if ( null === $this->transaction ) throw new RuntimeException( 'Rollback without transaction.' );
			[ $this->run, $this->steps, $this->context, $this->insert_id, $this->writes ] = $this->transaction;
			$this->transaction = null;
			++$this->rollbacks;
			return 1;
		}
		if ( 'COMMIT' === $query ) {
			if ( $this->fail_commit ) return false;
			$this->transaction = null;
			++$this->commits;
			return 1;
		}
		if ( null === $this->transaction ) throw new RuntimeException( 'C2 write outside a lease transaction.' );
		[ $sql, $args ] = $this->decode( $query );
		if ( str_contains( $sql, 'INSERT INTO ' . $this->prefix . 'cb_automations_run_context' ) ) {
			if ( $this->fail_context ) return false;
			c2_assert( (int) $args[0] === $this->run['id'], 'Context AAD run ID changed.' );
			$this->context = (string) $args[1];
			++$this->writes;
			return 1;
		}
		if ( str_contains( $sql, 'INSERT INTO ' . $this->prefix . 'cb_automations_run_steps' ) ) {
			$this->insert_id = max( 100, (int) $this->insert_id + 1 );
			$this->steps[ $this->insert_id ] = [
				'id' => $this->insert_id, 'run_id' => (int) $args[0],
				'node_id' => $args[1], 'node_type' => $args[2],
				'provider' => $args[3], 'capability_id' => $args[4],
				'schema_version' => $args[5], 'attempt' => (int) $args[6],
				'status' => $args[7],
			];
			++$this->writes;
			return 1;
		}
		if ( str_contains( $sql, 'UPDATE ' . $this->prefix . 'cb_automations_run_steps' ) ) {
			if ( $this->fail_step ) return false;
			$id = (int) $args[4];
			if ( ! isset( $this->steps[ $id ] ) || 'running' !== $this->steps[ $id ]['status'] ) return 0;
			$this->steps[ $id ]['status'] = $args[0];
			++$this->writes;
			return 1;
		}
		if ( str_contains( $sql, 'UPDATE ' . $this->prefix . 'cb_automations_runs' ) ) {
			if ( $this->fail_cursor ) return false;
			if ( (int) $args[2] !== $this->run['id']
				|| $args[3] !== $this->run['run_cursor']
				|| (int) $args[4] !== $this->run['definition_version']
				|| $args[5] !== $this->run['definition_hash']
				|| $args[6] !== $this->run['status'] ) return 0;
			$this->run['run_cursor'] = $args[0];
			++$this->writes;
			return 1;
		}
		throw new RuntimeException( 'Unexpected C2 write SQL: ' . $sql );
	}
}

$definition = GraphDefinitionCodec::decode( checkpoint_fixture() );
$json = json_encode( $definition->to_array(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
$wpdb = new Adv02cCheckpointWpdb();
$wpdb->run = [
	'id' => 21, 'workflow_id' => 3, 'workflow_revision' => 7,
	'execution_principal_user_id' => 9,
	'definition_version' => 2, 'definition_json' => $json,
	'definition_hash' => hash( 'sha256', $json ),
	'status' => 'running', 'run_cursor' => 'graph:0',
];
$wpdb->job = [ 'id' => 41, 'run_id' => 21, 'token' => str_repeat( 'b', 64 ) ];
$wpdb->context = Vault::seal( [
	'graph_checkpoint' => [
		'journal' => GraphCheckpointJournal::empty()->to_array(),
		'trigger_outputs' => [],
	],
], 'run-context:21' );
$job = new JobRecord(
	41, JobSubjectType::Run, 21, JobStatus::Leased, '2026-10-28 11:00:00',
	str_repeat( 'b', 64 ), '2026-10-28 13:00:00', 1, '', '2026-10-28 11:00:00',
	'2026-10-28 11:00:00'
);
$lease = RunExecutionLease::from_job( $job, 21 );
$catalog = new Adv02bCheckpointSource();
$wpdb->steps[50] = [
	'id' => 50, 'run_id' => 21, 'attempt' => 1, 'node_id' => 'state',
	'node_type' => 'state', 'status' => 'running', 'provider' => 'core-blueprint-demo',
	'capability_id' => 'data.lookup', 'schema_version' => '1',
];
$before = $wpdb->writes;
c2_reject(
	static fn () => GraphCheckpointStore::commit_branch( 21, $lease, $catalog, 'graph:0' ),
	'C2 branch could advance before the State.'
);
c2_assert( $before === $wpdb->writes, 'Rejected branch altered durable state.' );

$next = GraphCheckpointStore::commit_completion(
	21, $lease, $catalog, 'graph:0', new RunStepAttempt( 50, 1 ),
	'state', 'state', [ 'decision' => true, 'label' => 'Approved' ]
);
c2_assert( 'graph:1' === $next && 'graph:1' === $wpdb->run['run_cursor'], 'State and cursor were not acknowledged together.' );
c2_assert( 'succeeded' === $wpdb->steps[50]['status'], 'State attempt did not become succeeded.' );
c2_assert( ! str_contains( (string) $wpdb->context, 'Approved' ), 'Sensitive State output leaked outside encrypted Vault.' );
$context = Vault::open( (string) $wpdb->context, 'run-context:21' );
c2_assert( 1 === count( $context['graph_checkpoint']['journal']['entries'] ), 'Encrypted State journal not persisted.' );

$baseline = [ $wpdb->run, $wpdb->steps, $wpdb->context, $wpdb->writes ];
c2_reject(
	static fn () => GraphCheckpointStore::commit_completion(
		21, $lease, $catalog, 'graph:0', new RunStepAttempt( 50, 1 ),
		'state', 'state', [ 'decision' => false ]
	),
	'C2 accepted a stale duplicate State acknowledgement.'
);
c2_assert( $baseline === [ $wpdb->run, $wpdb->steps, $wpdb->context, $wpdb->writes ], 'Stale completion mutated data.' );

$wpdb->lease_active = false;
c2_reject(
	static fn () => GraphCheckpointStore::commit_branch( 21, $lease, $catalog, 'graph:1' ),
	'C2 allowed an expired or missing job lease.'
);
$wpdb->lease_active = true;
c2_assert( 'graph:1' === $wpdb->run['run_cursor'], 'Lost lease advanced the cursor.' );

$wpdb->fail_cursor = true;
c2_reject(
	static fn () => GraphCheckpointStore::commit_branch( 21, $lease, $catalog, 'graph:1' ),
	'C2 accepted an uncertain cursor update.'
);
$wpdb->fail_cursor = false;
c2_assert( $baseline === [ $wpdb->run, $wpdb->steps, $wpdb->context, $wpdb->writes ], 'Failed cursor update did not roll back branch attempt and journal.' );

$wpdb->fail_context = true;
c2_reject(
	static fn () => GraphCheckpointStore::commit_branch( 21, $lease, $catalog, 'graph:1' ),
	'C2 accepted failed encrypted-context storage.'
);
$wpdb->fail_context = false;
c2_assert( $baseline === [ $wpdb->run, $wpdb->steps, $wpdb->context, $wpdb->writes ], 'Failed Vault-context write did not roll back.' );

$wpdb->fail_step = true;
c2_reject(
	static fn () => GraphCheckpointStore::commit_branch( 21, $lease, $catalog, 'graph:1' ),
	'C2 accepted a failed Condition-step acknowledgement.'
);
$wpdb->fail_step = false;
c2_assert( $baseline === [ $wpdb->run, $wpdb->steps, $wpdb->context, $wpdb->writes ], 'Failed Condition-step did not roll back.' );

c2_assert( 'graph:2' === GraphCheckpointStore::commit_branch( 21, $lease, $catalog, 'graph:1' ), 'C2 did not record deterministic true choice.' );
$branch = Vault::open( (string) $wpdb->context, 'run-context:21' )['graph_checkpoint']['journal']['entries'][1];
c2_assert( [ 'kind' => 'if_else', 'node_id' => 'condition', 'decision' => true ] === $branch, 'Committed branch was not derived from State result.' );
c2_assert(
	1 === count( array_filter( $wpdb->steps, static fn ( array $step ): bool => 'condition' === $step['node_id'] && 'succeeded' === $step['status'] ) ),
	'Condition-step history not acknowledged exactly once.'
);

$wpdb->steps[51] = [
	'id' => 51, 'run_id' => 21, 'attempt' => 1, 'node_id' => 'action_true',
	'node_type' => 'action', 'status' => 'running', 'provider' => 'core-blueprint-demo',
	'capability_id' => 'record.create', 'schema_version' => '1',
];
c2_reject(
	static fn () => GraphCheckpointStore::commit_completion(
		21, $lease, $catalog, 'graph:2', new RunStepAttempt( 50, 1 ),
		'action_true', 'action', []
	),
	'C2 allowed an unrelated running step attempt.'
);
c2_reject(
	static fn () => GraphCheckpointStore::commit_completion(
		21, $lease, $catalog, 'graph:2', new RunStepAttempt( 51, 1 ),
		'action_false', 'action', []
	),
	'C2 allowed completion of an unselected branch.'
);
$wpdb->steps[51]['provider'] = 'different-provider';
c2_reject(
	static fn () => GraphCheckpointStore::commit_completion(
		21, $lease, $catalog, 'graph:2', new RunStepAttempt( 51, 1 ),
		'action_true', 'action', []
	),
	'C2 allowed mismatched provider identity.'
);
$wpdb->steps[51]['provider'] = 'core-blueprint-demo';
c2_assert(
	'graph:3' === GraphCheckpointStore::commit_completion(
		21, $lease, $catalog, 'graph:2', new RunStepAttempt( 51, 1 ),
		'action_true', 'action', []
	),
	'C2 Action completion did not advance its exact cursor.'
);
c2_assert( 'succeeded' === $wpdb->steps[51]['status'], 'Action step was not acknowledged.' );
$final = Vault::open( (string) $wpdb->context, 'run-context:21' );
$final_journal = GraphCheckpointJournal::from_array( $final['graph_checkpoint']['journal'] );
c2_assert(
	'complete' === $final_journal->replay(
		$definition, new GraphCheckpointPlanner( $catalog ), $final['graph_checkpoint']['trigger_outputs']
	)['status'],
	'Stored journal did not replay to End.'
);
c2_reject(
	static fn () => GraphCheckpointStore::commit_branch( 21, $lease, $catalog, 'graph:3' ),
	'C2 allowed an extra branch after End.'
);
c2_reject(
	static fn () => GraphCheckpointStore::commit_completion(
		21, $lease, $catalog, 'graph:3', new RunStepAttempt( 51, 1 ),
		'action_true', 'action', []
	),
	'C2 allowed duplicate Action completion.'
);
$wpdb->run['definition_version'] = 1;
c2_reject(
	static fn () => GraphCheckpointStore::commit_branch( 21, $lease, $catalog, 'graph:3' ),
	'C2 accepted Standard v1 run snapshot.'
);
$wpdb->run['definition_version'] = 2;
$wpdb->run['definition_hash'] = str_repeat( '0', 64 );
c2_reject(
	static fn () => GraphCheckpointStore::commit_branch( 21, $lease, $catalog, 'graph:3' ),
	'C2 accepted a mismatched immutable run definition hash.'
);

// The C2 writer has no hooks into Standard runtime or v2 activation.
$root = dirname( __DIR__, 2 );
$store = file_get_contents( $root . '/src/Persistence/GraphCheckpointStore.php' );
c2_assert( is_string( $store )
	&& ! str_contains( $store, 'StateInvoker' )
	&& ! str_contains( $store, 'ActionInvoker' )
	&& ! str_contains( $store, 'RunPipeline::execute' )
	&& ! str_contains( $store, 'WorkflowRepository::create' ),
	'Checkpoint persistence gained provider-invocation or workflow-admission authority.'
);
foreach ( [ 'RunPipeline.php', 'RuntimeWorker.php', 'TriggerIntake.php' ] as $runtime ) {
	$live = file_get_contents( $root . '/src/Runtime/' . $runtime );
	c2_assert( is_string( $live ) && ! str_contains( $live, 'GraphCheckpointStore' ), 'Standard runtime started calling C2.' );
}

echo "ADV-02C C2 durable checkpoint transaction and rollback: PASS\n";
