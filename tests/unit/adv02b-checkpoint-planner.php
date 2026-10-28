<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\Automations\\';
	if ( ! str_starts_with( $class, $prefix ) ) return;
	$file = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( is_file( $file ) ) require_once $file;
} );

use CB\Automations\Capability\CapabilityReference;
use CB\Automations\Discovery\CapabilityDefinition;
use CB\Automations\Discovery\CapabilitySource;
use CB\Automations\Discovery\ProviderStatus;
use CB\Automations\Runtime\GraphCheckpointPlanner;
use CB\Automations\Workflow\GraphDefinitionCodec;

function checkpoint_check( bool $ok, string $description ): void {
	if ( ! $ok ) throw new RuntimeException( $description );
}
function checkpoint_reject( callable $callback, string $description ): void {
	try {
		$callback();
	} catch ( UnexpectedValueException ) {
		return;
	}
	throw new RuntimeException( $description );
}

final class Adv02bCheckpointSource implements CapabilitySource {
	/** @var array<string,CapabilityDefinition> */
	private array $definitions = [];
	public function __construct() {
		$specs = [
			[ 'trigger', 'event.received', [], [ 'id' => [ 'type' => 'integer', 'required' => true, 'sensitive' => false ] ] ],
			[ 'state', 'data.lookup', [], [
				'decision' => [ 'type' => 'boolean', 'required' => true, 'sensitive' => false ],
				'label' => [ 'type' => 'string', 'required' => true, 'sensitive' => false ],
			] ],
			[ 'action', 'record.create',
				[ 'name' => [ 'type' => 'string', 'required' => true, 'sensitive' => false ] ], []
			],
		];
		foreach ( $specs as [ $kind, $id, $input, $output ] ) {
			$raw = [
				'provider' => 'core-blueprint-demo', 'id' => $id,
				'schema_version' => '1', 'label' => $id, 'description' => '',
			];
			if ( 'trigger' === $kind ) {
				$raw['payload_schema'] = $output;
			} else {
				$raw['input_schema'] = $input;
				$raw['output_schema'] = $output;
				$raw['required_capability'] = null;
			}
			$cap = CapabilityDefinition::from_base_definition( $kind, $raw );
			checkpoint_check( null !== $cap, 'Bad checkpoint catalog fixture.' );
			$this->definitions[ $kind . ':' . $id ] = $cap;
		}
	}
	public function current( CapabilityReference $ref ): ?CapabilityDefinition {
		return $this->definitions[ $ref->kind() . ':' . $ref->id() ] ?? null;
	}
	public function provider_status( string $provider ): ProviderStatus {
		return ProviderStatus::from_inventory( $provider, [
			'name' => 'Demo', 'installed' => true, 'active' => true,
			'registered' => true, 'compatible' => true,
		] );
	}
}

function checkpoint_cap( string $kind, string $id, array $bindings = [] ): array {
	return [
		'capability' => [ 'kind' => $kind, 'provider' => 'core-blueprint-demo',
			'id' => $id, 'schema_version' => '1' ],
		'bindings' => $bindings,
	];
}
function checkpoint_edge( string $id, string $from, string $from_port, string $to, string $to_port ): array {
	return compact( 'id', 'from', 'from_port', 'to', 'to_port' );
}
function checkpoint_fixture(): array {
	$bindings = [ 'name' => [ 'source' => 'step_output', 'step_id' => 'state', 'field' => 'label' ] ];
	return [
		'definition_version' => 2,
		'mode' => 'advanced',
		'graph' => [
			'nodes' => [
				[ 'id' => 'trigger', 'type' => 'trigger', 'config' => checkpoint_cap( 'trigger', 'event.received' ) ],
				[ 'id' => 'state', 'type' => 'state', 'config' => checkpoint_cap( 'state', 'data.lookup' ) ],
				[ 'id' => 'condition', 'type' => 'if_else', 'config' => [
					'left' => [ 'source' => 'step_output', 'step_id' => 'state', 'field' => 'decision' ],
					'operator' => 'equals',
					'right' => [ 'source' => 'literal', 'value' => true ],
					'merge_id' => 'merge',
				] ],
				[ 'id' => 'action_true', 'type' => 'action', 'config' => checkpoint_cap( 'action', 'record.create', $bindings ) ],
				[ 'id' => 'action_false', 'type' => 'action', 'config' => checkpoint_cap( 'action', 'record.create', $bindings ) ],
				[ 'id' => 'merge', 'type' => 'exclusive_merge', 'config' => [] ],
				[ 'id' => 'end', 'type' => 'end', 'config' => [] ],
			],
			'edges' => [
				checkpoint_edge( 'e1', 'trigger', 'next', 'state', 'in' ),
				checkpoint_edge( 'e2', 'state', 'next', 'condition', 'in' ),
				checkpoint_edge( 'e3', 'condition', 'true', 'action_true', 'in' ),
				checkpoint_edge( 'e4', 'condition', 'false', 'action_false', 'in' ),
				checkpoint_edge( 'e5', 'action_true', 'next', 'merge', 'true' ),
				checkpoint_edge( 'e6', 'action_false', 'next', 'merge', 'false' ),
				checkpoint_edge( 'e7', 'merge', 'next', 'end', 'in' ),
			],
		],
		'layout' => [ 'nodes' => [] ],
	];
}

$planner = new GraphCheckpointPlanner( new Adv02bCheckpointSource() );
$definition = GraphDefinitionCodec::decode( checkpoint_fixture() );

$first = $planner->project( $definition, [], [] );
checkpoint_check( 'pending' === $first['status'] && [ 'trigger', 'state' ] === $first['route'], 'Initial checkpoint does not stop at first State.' );
checkpoint_check(
	'state' === $first['intent']['node_id'] && 'state' === $first['intent']['kind']
	&& [] === $first['intent']['input'],
	'Initial State intent is not canonical.'
);

$true_outputs = [ 'state' => [ 'decision' => true, 'label' => 'Approved' ] ];
$choose_true = $planner->project( $definition, [ 'state' ], $true_outputs );
checkpoint_check( 'pending' === $choose_true['status'], 'True route did not stop at pending Action.' );
checkpoint_check(
	[ 'trigger', 'state', 'condition', 'action_true' ] === $choose_true['route']
	&& [ 'condition' => true ] === $choose_true['decisions'],
	'True checkpoint route traversed an unselected branch.'
);
checkpoint_check(
	'action_true' === $choose_true['intent']['node_id']
	&& 'action' === $choose_true['intent']['kind']
	&& [ 'name' => 'Approved' ] === $choose_true['intent']['input'],
	'True checkpoint did not resolve its Action input.'
);

$true_complete = $planner->project( $definition, [ 'state', 'action_true' ], $true_outputs );
checkpoint_check( 'complete' === $true_complete['status'] && null === $true_complete['intent'], 'Completed true route returned a new Action.' );
checkpoint_check(
	[ 'trigger', 'state', 'condition', 'action_true', 'merge', 'end' ] === $true_complete['route'],
	'True route did not rejoin Exclusive Merge exactly once.'
);

$false_outputs = [ 'state' => [ 'decision' => false, 'label' => 'Declined' ] ];
$choose_false = $planner->project( $definition, [ 'state' ], $false_outputs );
checkpoint_check(
	'pending' === $choose_false['status']
	&& [ 'trigger', 'state', 'condition', 'action_false' ] === $choose_false['route']
	&& [ 'condition' => false ] === $choose_false['decisions']
	&& [ 'name' => 'Declined' ] === $choose_false['intent']['input'],
	'False checkpoint did not select exactly its Action.'
);
$false_complete = $planner->project( $definition, [ 'state', 'action_false' ], $false_outputs );
checkpoint_check(
	'complete' === $false_complete['status'] && 1 === count( array_filter( $false_complete['route'], static fn ( string $id ): bool => 'merge' === $id ) ),
	'False route failed Exclusive Merge completion.'
);

checkpoint_reject(
	static fn () => $planner->project( $definition, [ 'action_true' ], $true_outputs ),
	'Action acknowledgement bypassed an unacknowledged State.'
);
checkpoint_reject(
	static fn () => $planner->project( $definition, [ 'state', 'action_true' ], $false_outputs ),
	'True-branch acknowledgement bypassed a false decision.'
);
checkpoint_reject(
	static fn () => $planner->project( $definition, [ 'state', 'action_true', 'action_false' ], $true_outputs ),
	'Unselected branch acknowledgement was accepted after End.'
);
checkpoint_reject(
	static fn () => $planner->project( $definition, [ 'state', 'state' ], $true_outputs ),
	'Duplicate State acknowledgement was accepted.'
);
checkpoint_reject(
	static fn () => $planner->project( $definition, [ 'state' ], [ 'state' => [ 'decision' => 'true', 'label' => 'Approved' ] ] ),
	'Invalid condition type silently coerced its decision.'
);
checkpoint_reject(
	static fn () => $planner->project( $definition, [ 'state' ], [ 'state' => [ 'decision' => true ] ] ),
	'Missing Action input was accepted.'
);
checkpoint_reject(
	static fn () => $planner->project( $definition, [], [ 'state' => [ 'decision' => true, 'label' => 'Injected' ] ] ),
	'Unacknowledged output injection reached the runtime projection.'
);
checkpoint_reject(
	static fn () => $planner->project( $definition, [ 'state' ], [ 'action_false' => [ 'name' => 'Injected' ] ] ),
	'Off-branch output injection reached the runtime projection.'
);
checkpoint_reject(
	static fn () => $planner->project( $definition, [ 0 => 'state', 2 => 'action_true' ], $true_outputs ),
	'Non-list acknowledgements passed the strict contract.'
);

// A pure projection must not reach the worker, run store, Vault, or Base providers.
$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Runtime/GraphCheckpointPlanner.php' );
checkpoint_check(
	is_string( $source )
	&& ! str_contains( $source, 'ActionInvoker' )
	&& ! str_contains( $source, 'StateInvoker' )
	&& ! str_contains( $source, 'RunRepository' )
	&& ! str_contains( $source, 'JobRepository' )
	&& ! str_contains( $source, 'RunContextRepository' ),
	'Checkpoint projection introduced runtime side effects.'
);
foreach ( [ 'RunPipeline.php', 'RuntimeWorker.php', 'TriggerIntake.php' ] as $runtime ) {
	$live = file_get_contents( dirname( __DIR__, 2 ) . '/src/Runtime/' . $runtime );
	checkpoint_check(
		is_string( $live ) && ! str_contains( $live, 'GraphCheckpointPlanner' ),
		'Standard v1 runtime calls into disabled Advanced checkpoint planning.'
	);
}

echo "ADV-02B B3 bounded checkpoint projection: PASS\n";
