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
use CB\Automations\Validation\GraphValidator;
use CB\Automations\Workflow\GraphDefinitionCodec;
use CB\Automations\Workflow\PersistencePolicy;

function adv_check( bool $success, string $message ): void {
	if ( ! $success ) throw new RuntimeException( $message );
}

final class Adv02aSource implements CapabilitySource {
	/** @var array<string,CapabilityDefinition> */
	private array $definitions = [];
	public function __construct() {
		foreach ( [
			[ 'trigger', 'event.received', [], [ 'id' => [ 'type' => 'integer', 'required' => true, 'sensitive' => false ] ] ],
			[ 'state', 'data.lookup', [], [ 'decision' => [ 'type' => 'boolean', 'required' => true, 'sensitive' => false ] ] ],
			[ 'action', 'record.create', [ 'secret' => [ 'type' => 'string', 'required' => false, 'sensitive' => true ] ], [] ],
		] as [ $kind, $id, $input, $output ] ) {
			$entry = [
				'provider' => 'core-blueprint-demo', 'id' => $id, 'schema_version' => '1',
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
			adv_check( null !== $definition, 'Invalid fixture definition.' );
			$this->definitions[ $kind . ':' . $id ] = $definition;
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

function adv_cap( string $kind, string $id ): array {
	return [
		'capability' => [
			'kind' => $kind, 'provider' => 'core-blueprint-demo',
			'id' => $id, 'schema_version' => '1',
		],
		'bindings' => [],
	];
}
function adv_edge( string $id, string $from, string $from_port, string $to, string $to_port ): array {
	return compact( 'id', 'from', 'from_port', 'to', 'to_port' );
}
function adv_fixture(): array {
	return [
		'definition_version' => 2,
		'mode' => 'advanced',
		'graph' => [
			'nodes' => [
				[ 'id' => 'trigger', 'type' => 'trigger', 'config' => adv_cap( 'trigger', 'event.received' ) ],
				[ 'id' => 'state', 'type' => 'state', 'config' => adv_cap( 'state', 'data.lookup' ) ],
				[ 'id' => 'condition', 'type' => 'if_else', 'config' => [
					'left' => [ 'source' => 'step_output', 'step_id' => 'state', 'field' => 'decision' ],
					'operator' => 'equals',
					'right' => [ 'source' => 'literal', 'value' => true ],
					'merge_id' => 'merge',
				] ],
				[ 'id' => 'action_a', 'type' => 'action', 'config' => adv_cap( 'action', 'record.create' ) ],
				[ 'id' => 'action_b', 'type' => 'action', 'config' => adv_cap( 'action', 'record.create' ) ],
				[ 'id' => 'merge', 'type' => 'exclusive_merge', 'config' => [] ],
				[ 'id' => 'end', 'type' => 'end', 'config' => [] ],
			],
			'edges' => [
				adv_edge( 'edge_1', 'trigger', 'next', 'state', 'in' ),
				adv_edge( 'edge_2', 'state', 'next', 'condition', 'in' ),
				adv_edge( 'edge_3', 'condition', 'true', 'action_a', 'in' ),
				adv_edge( 'edge_4', 'condition', 'false', 'action_b', 'in' ),
				adv_edge( 'edge_5', 'action_a', 'next', 'merge', 'true' ),
				adv_edge( 'edge_6', 'action_b', 'next', 'merge', 'false' ),
				adv_edge( 'edge_7', 'merge', 'next', 'end', 'in' ),
			],
		],
		'layout' => [ 'nodes' => [ 'trigger' => [ 'x' => -300, 'y' => 0 ] ] ],
	];
}

$validator = new GraphValidator( new Adv02aSource() );
$valid = adv_fixture();
$definition = GraphDefinitionCodec::decode( $valid );
adv_check( $validator->validate( $definition )->is_valid(), 'Valid exclusive merge was rejected.' );
adv_check( GraphDefinitionCodec::encode( $definition ) === $valid, 'Valid graph roundtrip differs.' );

$cycle = adv_fixture();
$cycle['graph']['nodes'][] = [ 'id' => 'loop_a', 'type' => 'state', 'config' => [] ];
$cycle['graph']['nodes'][] = [ 'id' => 'loop_b', 'type' => 'state', 'config' => [] ];
$cycle['graph']['edges'][] = adv_edge( 'edge_8', 'loop_a', 'next', 'loop_b', 'in' );
$cycle['graph']['edges'][] = adv_edge( 'edge_9', 'loop_b', 'next', 'loop_a', 'in' );
adv_check( $validator->validate( GraphDefinitionCodec::decode( $cycle ) )->has_code( 'graph.cycle' ), 'Cycle was not rejected.' );

$bad_merge = adv_fixture();
$bad_merge['graph']['nodes'][2]['config']['merge_id'] = 'end';
adv_check( $validator->validate( GraphDefinitionCodec::decode( $bad_merge ) )->has_code( 'graph.merge_invalid' ), 'Wrong merge was not rejected.' );

$private = adv_fixture();
$private['graph']['nodes'][3]['config']['bindings']['secret'] = [ 'source' => 'literal', 'value' => 'private' ];
$result = $validator->validate( GraphDefinitionCodec::decode( $private ) );
adv_check( $result->has_code( 'privacy.sensitive_literal' ), 'Sensitive literal was not detected.' );
adv_check( ! PersistencePolicy::allows( $result ), 'Sensitive literal should block draft persistence.' );

$skipped_source = adv_fixture();
$skipped_source['graph']['nodes'][6]['config'] = [];
$skipped_source['graph']['nodes'][4]['config']['bindings'] = [
	'secret' => [ 'source' => 'step_output', 'step_id' => 'action_a', 'field' => 'secret' ],
];
adv_check( $validator->validate( GraphDefinitionCodec::decode( $skipped_source ) )->has_code( 'binding.source_not_guaranteed' ), 'Cross-branch binding was accepted.' );

echo "ADV-02A graph validator: PASS\n";
