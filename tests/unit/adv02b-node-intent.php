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
use CB\Automations\Runtime\GraphNodeIntentPlanner;
use CB\Automations\Workflow\GraphDefinitionCodec;

function intent_check( bool $condition, string $message ): void {
	if ( ! $condition ) throw new RuntimeException( $message );
}
function intent_reject( callable $run, string $message ): void {
	try {
		$run();
	} catch ( UnexpectedValueException ) {
		return;
	}
	throw new RuntimeException( $message );
}

final class Adv02bIntentSource implements CapabilitySource {
	public bool $active = true;
	/** @var array<string,CapabilityDefinition> */
	private array $definitions = [];

	public function __construct() {
		$specs = [
			[
				'trigger', 'event.received', [],
				[ 'id' => [ 'type' => 'integer', 'required' => true, 'sensitive' => false ] ],
			],
			[
				'state', 'data.lookup', [],
				[
					'decision' => [ 'type' => 'boolean', 'required' => true, 'sensitive' => false ],
					'label' => [ 'type' => 'string', 'required' => true, 'sensitive' => false ],
				],
			],
			[
				'action', 'record.create',
				[
					'name' => [ 'type' => 'string', 'required' => true, 'sensitive' => false ],
					'quantity' => [ 'type' => 'integer', 'required' => false, 'sensitive' => false ],
					'secret' => [ 'type' => 'string', 'required' => false, 'sensitive' => true ],
				], [],
			],
		];
		foreach ( $specs as [ $kind, $id, $input, $output ] ) {
			$entry = [
				'provider' => 'core-blueprint-demo',
				'id' => $id, 'schema_version' => '1',
				'label' => $id, 'description' => '',
			];
			if ( 'trigger' === $kind ) {
				$entry['payload_schema'] = $output;
			} else {
				$entry['input_schema'] = $input;
				$entry['output_schema'] = $output;
				$entry['required_capability'] = null;
			}
			$definition = CapabilityDefinition::from_base_definition( $kind, $entry );
			intent_check( null !== $definition, 'Invalid fixture capability.' );
			$this->definitions[ $kind . ':' . $id ] = $definition;
		}
	}

	public function current( CapabilityReference $reference ): ?CapabilityDefinition {
		return $this->definitions[ $reference->kind() . ':' . $reference->id() ] ?? null;
	}
	public function provider_status( string $provider ): ProviderStatus {
		return ProviderStatus::from_inventory( $provider, [
			'name' => 'Demo', 'installed' => true, 'active' => $this->active,
			'registered' => true, 'compatible' => true,
		] );
	}
}

function intent_cap( string $kind, string $id, array $bindings = [] ): array {
	return [
		'capability' => [
			'kind' => $kind, 'provider' => 'core-blueprint-demo',
			'id' => $id, 'schema_version' => '1',
		],
		'bindings' => $bindings,
	];
}
function intent_edge( string $id, string $from, string $from_port, string $to, string $to_port ): array {
	return compact( 'id', 'from', 'from_port', 'to', 'to_port' );
}
function intent_fixture(): array {
	$bindings = [
		'name' => [ 'source' => 'step_output', 'step_id' => 'state', 'field' => 'label' ],
		'quantity' => [ 'source' => 'literal', 'value' => 2 ],
	];
	return [
		'definition_version' => 2,
		'mode' => 'advanced',
		'graph' => [
			'nodes' => [
				[ 'id' => 'trigger', 'type' => 'trigger', 'config' => intent_cap( 'trigger', 'event.received' ) ],
				[ 'id' => 'state', 'type' => 'state', 'config' => intent_cap( 'state', 'data.lookup' ) ],
				[ 'id' => 'choice', 'type' => 'if_else', 'config' => [
					'left' => [ 'source' => 'step_output', 'step_id' => 'state', 'field' => 'decision' ],
					'operator' => 'equals',
					'right' => [ 'source' => 'literal', 'value' => true ],
					'merge_id' => 'merge',
				] ],
				[ 'id' => 'action_a', 'type' => 'action', 'config' => intent_cap( 'action', 'record.create', $bindings ) ],
				[ 'id' => 'action_b', 'type' => 'action', 'config' => intent_cap( 'action', 'record.create', $bindings ) ],
				[ 'id' => 'merge', 'type' => 'exclusive_merge', 'config' => [] ],
				[ 'id' => 'end', 'type' => 'end', 'config' => [] ],
			],
			'edges' => [
				intent_edge( 'e1', 'trigger', 'next', 'state', 'in' ),
				intent_edge( 'e2', 'state', 'next', 'choice', 'in' ),
				intent_edge( 'e3', 'choice', 'true', 'action_a', 'in' ),
				intent_edge( 'e4', 'choice', 'false', 'action_b', 'in' ),
				intent_edge( 'e5', 'action_a', 'next', 'merge', 'true' ),
				intent_edge( 'e6', 'action_b', 'next', 'merge', 'false' ),
				intent_edge( 'e7', 'merge', 'next', 'end', 'in' ),
			],
		],
		'layout' => [ 'nodes' => [] ],
	];
}
$source = new Adv02bIntentSource();
$planner = new GraphNodeIntentPlanner( $source );
$raw = intent_fixture();
$graph = GraphDefinitionCodec::decode( $raw );

// State planning cannot cause a provider call; empty input is valid here.
$state = $planner->plan( $graph, 'state', [] );
intent_check(
	'state' === $state['kind'] && 'state' === $state['node_id']
	&& 'data.lookup' === $state['capability']->id() && [] === $state['input'],
	'State intent does not match the canonical provider contract.'
);

$action = $planner->plan( $graph, 'action_a', [ 'state' => [ 'decision' => true, 'label' => 'Ready' ] ] );
intent_check(
	'action' === $action['kind'] && 'record.create' === $action['capability']->id()
	&& [ 'name' => 'Ready', 'quantity' => 2 ] === $action['input'],
	'Advanced Action input bindings were not resolved canonically.'
);

intent_reject( static fn () => $planner->plan( $graph, 'action_a', [] ), 'Missing output was silently accepted.' );
intent_reject(
	static fn () => $planner->plan( $graph, 'action_a', [ 'state' => [ 'label' => 17 ] ] ),
	'Incorrect dynamic binding type was silently coerced.'
);
intent_reject( static fn () => $planner->plan( $graph, 'choice', [] ), 'Condition entered State/Action intent.' );
intent_reject( static fn () => $planner->plan( $graph, 'trigger', [] ), 'Trigger entered State/Action intent.' );
intent_reject( static fn () => $planner->plan( $graph, 'unknown', [] ), 'Unknown node entered State/Action intent.' );

$no_required = intent_fixture();
unset( $no_required['graph']['nodes'][3]['config']['bindings']['name'] );
intent_reject(
	static fn () => $planner->plan( GraphDefinitionCodec::decode( $no_required ), 'action_a', [ 'state' => [ 'label' => 'Ready' ] ] ),
	'Missing required capability binding entered intent.'
);

$private = intent_fixture();
$private['graph']['nodes'][3]['config']['bindings']['secret'] = [ 'source' => 'literal', 'value' => 'private' ];
intent_reject(
	static fn () => $planner->plan( GraphDefinitionCodec::decode( $private ), 'action_a', [ 'state' => [ 'label' => 'Ready' ] ] ),
	'Sensitive literal was passed through intent.'
);

$wrong_schema = intent_fixture();
$wrong_schema['graph']['nodes'][3]['config']['bindings']['quantity'] = [
	'source' => 'step_output', 'step_id' => 'state', 'field' => 'label',
];
intent_reject(
	static fn () => $planner->plan( GraphDefinitionCodec::decode( $wrong_schema ), 'action_a', [ 'state' => [ 'label' => 'Ready' ] ] ),
	'Cross-capability schema incompatibility was accepted.'
);

// A provider may disappear between graph authoring and an invocation request.
$source->active = false;
intent_reject(
	static fn () => $planner->plan( $graph, 'action_a', [ 'state' => [ 'label' => 'Ready' ] ] ),
	'Inactive provider entered intent.'
);
$source->active = true;

// The planner must remain isolated from real Base providers, jobs, Vault and
// the Standard production worker until a separate durable runtime gate.
$body = file_get_contents( dirname( __DIR__, 2 ) . '/src/Runtime/GraphNodeIntentPlanner.php' );
intent_check(
	is_string( $body )
	&& ! str_contains( $body, 'ActionInvoker' )
	&& ! str_contains( $body, 'StateInvoker' )
	&& ! str_contains( $body, 'RunContextRepository' )
	&& ! str_contains( $body, 'JobRepository' ),
	'ADV-02B intent planner was connected to side-effectful runtime.'
);
foreach ( [ 'RunPipeline.php', 'RuntimeWorker.php', 'TriggerIntake.php' ] as $runtime ) {
	$body = file_get_contents( dirname( __DIR__, 2 ) . '/src/Runtime/' . $runtime );
	intent_check( is_string( $body ) && ! str_contains( $body, 'GraphNodeIntentPlanner' ), 'Standard runtime invoked Advanced node intent.' );
}

echo "ADV-02B typed node intent and provider-boundary isolation: PASS\n";
