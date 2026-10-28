<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
function wp_salt( string $scheme = 'auth' ): string {
	return 'core-blueprint-adv02c-c3-sodium-test-' . $scheme;
}
function current_time( string $type, bool $gmt = false ): string {
	return '2026-10-28 12:00:00';
}
require_once __DIR__ . '/adv02c-checkpoint-journal.php';

use CB\Automations\Capability\CapabilityReference;
use CB\Automations\Runtime\GraphActionStartFence;
use CB\Automations\Runtime\GraphCheckpointJournal;
use CB\Automations\Runtime\GraphCheckpointPlanner;
use CB\Automations\Runtime\GraphProviderOutputGuard;
use CB\Automations\Runtime\JobRecord;
use CB\Automations\Runtime\JobStatus;
use CB\Automations\Runtime\JobSubjectType;
use CB\Automations\Runtime\RunExecutionLease;
use CB\Automations\Runtime\RunStepAttempt;
use CB\Automations\Runtime\Vault;
use CB\Automations\Workflow\GraphDefinitionCodec;

function c3_expect( bool $pass, string $why ): void {
	if ( ! $pass ) throw new RuntimeException( $why );
}
function c3_reject( callable $call, string $why ): void {
	try {
		$call();
	} catch ( Throwable ) {
		return;
	}
	throw new RuntimeException( $why );
}

/**
 * Fake transactional SQL adapter for the real Action preflight fence, existing
 * lease, run status and step repositories. No WordPress or real DB required.
 */
final class Adv02cC3Wpdb {
	public string $prefix = 'wp_';
	public string $last_error = '';
	public int $insert_id = 0;
	public array $run = [];
	public array $job = [];
	public array $steps = [];
	public ?string $context = null;
	public bool $lease_active = true;
	public bool $fail_step = false;
	public bool $fail_run = false;
	public bool $fail_commit = false;
	public int $commits = 0;
	public int $rollbacks = 0;
	private array $queries = [];
	private int $counter = 0;
	private ?array $snapshot = null;

	public function prepare( string $sql, mixed ...$args ): string {
		$key = ++$this->counter;
		$this->queries[ $key ] = [ $sql, $args ];
		return 'C3_SQL_' . $key;
	}
	private function unpack( string $sql ): array {
		if ( preg_match( '/^C3_SQL_([0-9]+)$/D', $sql, $match ) ) {
			return $this->queries[ (int) $match[1] ];
		}
		return [ $sql, [] ];
	}
	public function get_row( string $sql, string $mode = ARRAY_A ): ?array {
		[ $query, $args ] = $this->unpack( $sql );
		if ( str_contains( $query, 'FROM ' . $this->prefix . 'cb_automations_runs' )
			&& str_contains( $query, 'FOR UPDATE' ) ) {
			return (int) $args[0] === (int) $this->run['id'] ? $this->run : null;
		}
		if ( str_contains( $query, 'FROM ' . $this->prefix . 'cb_automations_run_steps' )
			&& str_contains( $query, 'ORDER BY attempt DESC LIMIT 1 FOR UPDATE' ) ) {
			$matches = array_values( array_filter( $this->steps, static fn ( array $step ): bool =>
				$step['run_id'] === (int) $args[0]
				&& $step['node_type'] === $args[1]
				&& $step['node_id'] === $args[2]
			) );
			usort( $matches, static fn ( array $a, array $b ): int => $b['attempt'] <=> $a['attempt'] );
			return $matches[0] ?? null;
		}
		throw new RuntimeException( 'Unsupported Action fence row query.' );
	}
	public function get_var( string $sql ): mixed {
		[ $query, $args ] = $this->unpack( $sql );
		if ( str_contains( $query, 'FROM ' . $this->prefix . 'cb_automations_jobs' )
			&& str_contains( $query, 'FOR UPDATE' ) ) {
			return $this->lease_active && $this->job['id'] === (int) $args[0]
				&& $this->job['run_id'] === (int) $args[2]
				&& $this->job['token'] === $args[4] ? $this->job['id'] : null;
		}
		if ( str_contains( $query, 'FROM ' . $this->prefix . 'cb_automations_run_context' ) ) {
			return (int) $args[0] === (int) $this->run['id'] ? $this->context : null;
		}
		if ( str_contains( $query, 'SELECT MAX(attempt)' ) ) {
			$last = null;
			foreach ( $this->steps as $step ) {
				if ( $step['run_id'] === (int) $args[0] && $step['node_type'] === $args[1]
					&& $step['node_id'] === $args[2] ) {
					$last = max( $last ?? 0, $step['attempt'] );
				}
			}
			return $last;
		}
		throw new RuntimeException( 'Unsupported Action fence scalar query.' );
	}
	public function query( string $sql ): int|false {
		if ( 'START TRANSACTION' === $sql ) {
			if ( null !== $this->snapshot ) throw new RuntimeException( 'Nested C3 transaction.' );
			$this->snapshot = [ $this->run, $this->steps, $this->insert_id ];
			return 1;
		}
		if ( 'ROLLBACK' === $sql ) {
			if ( null === $this->snapshot ) throw new RuntimeException( 'C3 rollback without begin.' );
			[ $this->run, $this->steps, $this->insert_id ] = $this->snapshot;
			$this->snapshot = null;
			++$this->rollbacks;
			return 1;
		}
		if ( 'COMMIT' === $sql ) {
			if ( $this->fail_commit ) return false;
			$this->snapshot = null;
			++$this->commits;
			return 1;
		}
		if ( null === $this->snapshot ) throw new RuntimeException( 'C3 mutation outside lease transaction.' );
		[ $query, $args ] = $this->unpack( $sql );
		if ( str_contains( $query, 'INSERT INTO ' . $this->prefix . 'cb_automations_run_steps' ) ) {
			$this->insert_id = max( 100, $this->insert_id + 1 );
			$this->steps[ $this->insert_id ] = [
				'id' => $this->insert_id, 'run_id' => (int) $args[0],
				'node_id' => $args[1], 'node_type' => $args[2],
				'provider' => $args[3], 'capability_id' => $args[4],
				'schema_version' => $args[5], 'attempt' => (int) $args[6],
				'status' => $args[7],
			];
			return 1;
		}
		if ( str_contains( $query, 'UPDATE ' . $this->prefix . 'cb_automations_run_steps' ) ) {
			if ( $this->fail_step ) return false;
			$id = (int) $args[4];
			if ( ! isset( $this->steps[ $id ] ) || $this->steps[ $id ]['status'] !== $args[5] ) {
				return 0;
			}
			$this->steps[ $id ]['status'] = $args[0];
			return 1;
		}
		if ( str_contains( $query, 'UPDATE ' . $this->prefix . 'cb_automations_runs' ) ) {
			if ( $this->fail_run ) return false;
			$id = (int) $args[3];
			if ( $id !== $this->run['id'] || $this->run['status'] !== $args[4] ) {
				return 0;
			}
			$this->run['status'] = $args[0];
			$this->run['failure_code'] = $args[1];
			return 1;
		}
		throw new RuntimeException( 'Unsupported Action fence mutating query.' );
	}
}

$catalog = new Adv02bCheckpointSource();
$ref_state = CapabilityReference::from_values( 'state', 'core-blueprint-demo', 'data.lookup', '1' );
$ref_action = CapabilityReference::from_values( 'action', 'core-blueprint-demo', 'record.create', '1' );
c3_expect( null !== $ref_state && null !== $ref_action, 'C3 reference fixture malformed.' );

GraphProviderOutputGuard::require_valid( $catalog, $ref_state, [ 'decision' => true, 'label' => 'Approved' ] );
GraphProviderOutputGuard::require_valid( $catalog, $ref_action, [] );
foreach ( [
	[ 'decision' => 1, 'label' => 'Approved' ],
	[ 'decision' => true ],
	[ 'decision' => true, 'label' => 'Approved', 'private' => 'bad' ],
	[ 'decision' => true, 'label' => INF ],
] as $bad ) {
	c3_reject(
		static fn () => GraphProviderOutputGuard::require_valid( $catalog, $ref_state, $bad ),
		'C3 accepted an untrusted or malformed State output.'
	);
}
c3_reject(
	static fn () => GraphProviderOutputGuard::require_valid( $catalog, $ref_action, [ 'unexpected' => 'data' ] ),
	'C3 accepted an undeclared Action output.'
);
$missing = CapabilityReference::from_values( 'state', 'not-installed', 'data.lookup', '1' );
c3_expect( null !== $missing, 'Missing provider fixture malformed.' );
c3_reject(
	static fn () => GraphProviderOutputGuard::require_valid( $catalog, $missing, [ 'decision' => true, 'label' => 'Approved' ] ),
	'C3 accepted a mismatched capability provider.'
);

$definition = GraphDefinitionCodec::decode( checkpoint_fixture() );
$planner = new GraphCheckpointPlanner( $catalog );
$journal = GraphCheckpointJournal::empty()
	->record_completion( $definition, $planner, 'state', 'state', [ 'decision' => true, 'label' => 'Approved' ] )
	->record_next_branch( $definition, $planner );
$json = json_encode( $definition->to_array(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
$wpdb = new Adv02cC3Wpdb();
$wpdb->run = [
	'id' => 21, 'workflow_id' => 3, 'workflow_revision' => 7,
	'execution_principal_user_id' => 9, 'definition_version' => 2,
	'definition_json' => $json, 'definition_hash' => hash( 'sha256', $json ),
	'run_cursor' => 'graph:2', 'status' => 'running',
];
$wpdb->job = [ 'id' => 41, 'run_id' => 21, 'token' => str_repeat( 'b', 64 ) ];
$wpdb->context = Vault::seal( [
	'graph_checkpoint' => [ 'journal' => $journal->to_array(), 'trigger_outputs' => [] ],
], 'run-context:21' );
$job = new JobRecord(
	41, JobSubjectType::Run, 21, JobStatus::Leased, '2026-10-28 11:00:00',
	str_repeat( 'b', 64 ), '2026-10-28 13:00:00', 1, '', '2026-10-28 11:00:00',
	'2026-10-28 11:00:00'
);
$lease = RunExecutionLease::from_job( $job, 21 );

c3_reject(
	static fn () => GraphActionStartFence::begin( 21, 'action_false', 'graph:2', $lease, $catalog ),
	'C3 opened an unselected Advanced Action.'
);
c3_reject(
	static fn () => GraphActionStartFence::begin( 21, 'action_true', 'graph:1', $lease, $catalog ),
	'C3 accepted a stale Advanced cursor.'
);
c3_expect( [] === $wpdb->steps, 'Invalid Action start created a step.' );

$wpdb->lease_active = false;
c3_reject(
	static fn () => GraphActionStartFence::begin( 21, 'action_true', 'graph:2', $lease, $catalog ),
	'C3 opened Action without active lease.'
);
$wpdb->lease_active = true;
c3_expect( [] === $wpdb->steps, 'Lost Action lease wrote step history.' );

$wpdb->run['definition_version'] = 1;
c3_reject(
	static fn () => GraphActionStartFence::begin( 21, 'action_true', 'graph:2', $lease, $catalog ),
	'C3 admitted a Standard v1 run.'
);
$wpdb->run['definition_version'] = 2;
$wpdb->run['definition_hash'] = str_repeat( '0', 64 );
c3_reject(
	static fn () => GraphActionStartFence::begin( 21, 'action_true', 'graph:2', $lease, $catalog ),
	'C3 ignored an immutable v2 hash mismatch.'
);
$wpdb->run['definition_hash'] = hash( 'sha256', $json );

$wpdb->fail_commit = true;
c3_reject(
	static fn () => GraphActionStartFence::begin( 21, 'action_true', 'graph:2', $lease, $catalog ),
	'C3 accepted unconfirmed Action-start commit.'
);
$wpdb->fail_commit = false;
c3_expect( [] === $wpdb->steps && $wpdb->rollbacks > 0, 'Action start survived uncertain transaction.' );

$started = GraphActionStartFence::begin( 21, 'action_true', 'graph:2', $lease, $catalog );
c3_expect( $started instanceof RunStepAttempt, 'C3 did not create the first authorized Action attempt.' );
c3_expect( 1 === count( $wpdb->steps ) && 'running' === $wpdb->steps[ $started->id() ]['status'], 'C3 Action attempt was not durable.' );

// Inject unreadable context *after* Action entry: crash recovery must inspect
// the durable step attempt BEFORE opening Vault. The Action must never repeat.
$old_context = $wpdb->context;
$wpdb->context = '{"damaged":true}';
$wpdb->fail_step = true;
c3_reject(
	static fn () => GraphActionStartFence::begin( 21, 'action_true', 'graph:2', $lease, $catalog ),
	'C3 ignored a failure to quarantine the Action attempt.'
);
$wpdb->fail_step = false;
c3_expect( 'running' === $wpdb->run['status']
	&& 'running' === $wpdb->steps[ $started->id() ]['status'],
	'C3 failed to roll back an incomplete indeterminate transition.'
);

$wpdb->fail_run = true;
c3_reject(
	static fn () => GraphActionStartFence::begin( 21, 'action_true', 'graph:2', $lease, $catalog ),
	'C3 accepted a failed run-indeterminate transition.'
);
$wpdb->fail_run = false;
c3_expect( 'running' === $wpdb->run['status']
	&& 'running' === $wpdb->steps[ $started->id() ]['status'],
	'C3 left an inconsistent step/run after run update failure.'
);

$result = GraphActionStartFence::begin( 21, 'action_true', 'graph:2', $lease, $catalog );
c3_expect( null === $result, 'C3 replayed an Action after a running attempt existed.' );
c3_expect( 'indeterminate' === $wpdb->run['status']
	&& 'indeterminate' === $wpdb->steps[ $started->id() ]['status'],
	'C3 did not durably quarantine the unknown external outcome.'
);
c3_expect( $wpdb->context === '{"damaged":true}', 'C3 recovery read or rewrote unreadable Vault data.' );
c3_reject(
	static fn () => GraphActionStartFence::begin( 21, 'action_true', 'graph:2', $lease, $catalog ),
	'C3 attempted a second Action after quarantine.'
);

$wpdb->run['status'] = 'running';
$wpdb->steps[ $started->id() ]['status'] = 'succeeded';
c3_expect(
	null === GraphActionStartFence::begin( 21, 'action_true', 'graph:2', $lease, $catalog )
	&& 'indeterminate' === $wpdb->run['status'],
	'C3 retried an Action with prior terminal attempt not covered by its journal.'
);
$wpdb->context = $old_context;

// Verify the integration remains non-public; no existing worker depends on C3.
$root = dirname( __DIR__, 2 );
$store = file_get_contents( $root . '/src/Persistence/GraphCheckpointStore.php' );
c3_expect( is_string( $store ) && str_contains( $store, 'GraphProviderOutputGuard::require_valid(' ),
	'C2 was not hardened to verify provider results before acknowledgement.' );
foreach ( [ 'RunPipeline.php', 'RuntimeWorker.php', 'TriggerIntake.php' ] as $file ) {
	$runtime = file_get_contents( $root . '/src/Runtime/' . $file );
	c3_expect( is_string( $runtime )
		&& ! str_contains( $runtime, 'GraphActionStartFence' )
		&& ! str_contains( $runtime, 'GraphProviderOutputGuard' ),
	'C3 prematurely modified the Standard live runtime.'
	);
}
echo "ADV-02C C3 provider result schema and Action no-replay fence: PASS\n";
