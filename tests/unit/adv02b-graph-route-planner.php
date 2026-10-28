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
use CB\Automations\Runtime\GraphRoutePlanner;
use CB\Automations\Workflow\GraphDefinitionCodec;

function route_check( bool $condition, string $message ): void {
	if ( ! $condition ) throw new RuntimeException( $message );
}

final class Adv02bRouteSource implements CapabilitySource {
	/** @var array<string,CapabilityDefinition> */
	private array $definitions = [];

	public function __construct() {
		foreach ( [
			[ 'trigger', 'event.received', [], [ 'id' => [ 'type' => 'integer', 'required' => true, 'sensitive' => false ] ] ],
			[ 'state', 'data.lookup', [], [ 'decision' => [ 'type' => 'boolean', 'required' => true, 'sensitive' => false ] ] ],
			[ 'action', 'record.create', [], [] ],
		] as [ $kind, $id, $input, $output ] ) {
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
			$entry = CapabilityDefinition::from_base_definition( $kind, $raw );
			route_check( null !== $entry, 'Route provider fixture invalid.' );
			$this->definitions[ $kind . ':' . $id ] = $entry;
		}
	}

	public function current( CapabilityReference $reference ): ?CapabilityDefinition {
		return $this->definitions[ $reference->kind() . ':' . $reference->id() ] ?? null;
	}

	public function provider_status( string $provider ): ProviderStatus {
		return ProviderStatus::from_inventory( $provider, [
			'name' => 'Demo', 'installed' => true, 'active' => true,
			'registered' => true, 'compatible' => true,
		] );
	}
}

function route_cap( string $kind, string $id ): array {
	return [
		'capability' => [
			'kind' => $kind, 'provider' => 'core-blueprint-demo',
			'id' => $id, 'schema_version' => '1',
		],
		'bindings' => [],
	];
}

function route_edge( string $id, string $from, string $from_port, string $to, string $to_port ): array {
	return compact( 'id', 'from', 'from_port', 'to', 'to_port' );
}

function route_fixture(): array {
	return [
		'definition_version' => 2,
		'mode' => 'advanced',
		'graph' => [
			'nodes' => [
				[ 'id' => 'trigger', 'type' => 'trigger', 'config' => route_cap( 'trigger', 'event.received' ) ],
				[ 'id' => 'state', 'type' => 'state', 'config' => route_cap( 'state', 'data.lookup' ) ],
				[ 'id' => 'choice', 'type' => 'if_else', 'config' => [
					'left' => [ 'source' => 'step_output', 'step_id' => 'state', 'field' => 'decision' ],
					'operator' => 'equals',
					'right' => [ 'source' => 'literal', 'value' => true ],
					'merge_id' => 'merge',
				] ],
				[ 'id' => 'true_action', 'type' => 'action', 'config' => route_cap( 'action', 'record.create' ) ],
				[ 'id' => 'false_action', 'type' => 'action', 'config' => route_cap( 'action', 'record.create' ) ],
				[ 'id' => 'merge', 'type' => 'exclusive_merge', 'config' => [] ],
				[ 'id' => 'end', 'type' => 'end', 'config' => [] ],
			],
			'edges' => [
				route_edge( 'e1', 'trigger', 'next', 'state', 'in' ),
				route_edge( 'e2', 'state', 'next', 'choice', 'in' ),
				route_edge( 'e3', 'choice', 'true', 'true_action', 'in' ),
				route_edge( 'e4', 'choice', 'false', 'false_action', 'in' ),
				route_edge( 'e5', 'true_action', 'next', 'merge', 'true' ),
				route_edge( 'e6', 'false_action', 'next', 'merge', 'false' ),
				route_edge( 'e7', 'merge', 'next', 'end', 'in' ),
			],
		],
		'layout' => [ 'nodes' => [] ],
	];
}

function route_reject( callable $call, string $message ): void {
	try {
		$call();
	} catch ( UnexpectedValueException ) {
		return;
	}
	throw new RuntimeException( $message );
}

$catalog = new Adv02bRouteSource();
$planner = new GraphRoutePlanner( $catalog );
$graph = GraphDefinitionCodec::decode( route_fixture() );

$yes = $planner->trace( $graph, [ 'state' => [ 'decision' => true ] ] );
route_check(
	[ 'trigger', 'state', 'choice', 'true_action', 'merge', 'end' ] === $yes['nodes']
	&& [ 'choice' => true ] === $yes['decisions'],
	'True branch did not execute exclusively once and rejoin the merge.'
);
route_check( ! in_array( 'false_action', $yes['nodes'], true ), 'Unselected branch leaked into route.' );

$no = $planner->trace( $graph, [ 'state' => [ 'decision' => false ] ] );
route_check(
	[ 'trigger', 'state', 'choice', 'false_action', 'merge', 'end' ] === $no['nodes']
	&& [ 'choice' => false ] === $no['decisions'],
	'False branch did not execute exclusively once and rejoin the merge.'
);
route_check( ! in_array( 'true_action', $no['nodes'], true ), 'Unselected branch leaked into route.' );

$transition = $planner->next( $graph, 'choice', [ 'state' => [ 'decision' => false ] ] );
route_check(
	[ 'from' => 'choice', 'to' => 'false_action', 'port' => 'false', 'decision' => false ] === $transition,
	'Condition transition proof mismatched.'
);

route_reject(
	static fn () => $planner->trace( $graph, [] ),
	'Missing prior state output was silently interpreted as false.'
);
route_reject(
	static fn () => $planner->trace( $graph, [ 'state' => [ 'decision' => 'true' ] ] ),
	'Invalid Boolean output was coerced.'
);
route_reject(
	static fn () => $planner->next( $graph, 'unknown', [] ),
	'Unknown route node was accepted.'
);
route_reject(
	static fn () => $planner->next( $graph, 'end', [] ),
	'End node emitted an outgoing transition.'
);

$bad = route_fixture();
$bad['graph']['nodes'][2]['config']['merge_id'] = 'end';
route_reject(
	static fn () => $planner->trace( GraphDefinitionCodec::decode( $bad ), [ 'state' => [ 'decision' => true ] ] ),
	'Nonexclusive merge structure entered route planning.'
);

$bad = route_fixture();
$bad['graph']['edges'][3]['from_port'] = 'true';
route_reject(
	static fn () => $planner->trace( GraphDefinitionCodec::decode( $bad ), [ 'state' => [ 'decision' => false ] ] ),
	'Two edges on the same decision port entered route planning.'
);

// This helper must NOT be wired into the Standard pipeline or the worker.
// It is no substitute for ADV-02C lease-fenced durable branch evidence.
foreach ( [ 'src/Runtime/RunPipeline.php', 'src/Runtime/RuntimeWorker.php', 'src/Runtime/TriggerIntake.php' ] as $path ) {
	$source = file_get_contents( dirname( __DIR__, 2 ) . '/' . $path );
	route_check( is_string( $source ) && ! str_contains( $source, 'GraphRoutePlanner' ), 'Advanced routing reached live Standard runtime.' );
}

echo "ADV-02B deterministic graph route planning and exclusive merge: PASS\n";
