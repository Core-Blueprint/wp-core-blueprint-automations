<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Binding\Binding;
use CB\Automations\Condition\OperatorCatalog;
use CB\Automations\Discovery\CapabilitySource;
use CB\Automations\Validation\GraphValidator;
use CB\Automations\Workflow\GraphDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * ADV-02B: deterministic, read-only Advanced graph routing kernel.
 *
 * This resolves transitions and IF/ELSE decisions using the same bindings and
 * operators as Standard. It NEVER invokes a provider, persists a run, grants
 * execution authority or admits a v2 workflow to the Standard runtime.
 * ADV-02C must add durable, lease-fenced acknowledgements before v2 activation.
 */
final class GraphRoutePlanner {
	private GraphValidator $validator;

	public function __construct( CapabilitySource $catalog ) {
		$this->validator = new GraphValidator( $catalog );
	}

	/**
	 * Resolve exactly one outgoing transition from a completed graph node.
	 *
	 * @param array<string,mixed> $outputs Read-only outputs of already completed steps.
	 * @return array{from:string,to:string,port:string,decision:?bool}
	 */
	public function next( GraphDefinition $definition, string $node_id, array $outputs ): array {
		$this->require_valid( $definition );
		return self::transition( $definition, $node_id, $outputs );
	}

	/**
	 * Read-only route simulation using supplied completed-step outputs.
	 * No callback, provider invocation, mutation, or runtime side effects.
	 *
	 * @param array<string,mixed> $outputs
	 * @return array{nodes:list<string>,decisions:array<string,bool>}
	 */
	public function trace( GraphDefinition $definition, array $outputs ): array {
		$this->require_valid( $definition );
		$nodes = $definition->graph()['nodes'];
		$start = null;
		foreach ( $nodes as $node ) {
			if ( 'trigger' === $node['type'] ) {
				$start = $node['id'];
				break;
			}
		}
		if ( null === $start ) {
			throw new \UnexpectedValueException( 'Advanced graph has no trigger.' );
		}

		$path = [];
		$decisions = [];
		$visited = [];
		$current = $start;
		$max = count( $nodes );
		while ( count( $path ) < $max ) {
			if ( isset( $visited[ $current ] ) ) {
				throw new \UnexpectedValueException( 'Advanced route revisited a node.' );
			}
			$visited[ $current ] = true;
			$path[] = $current;
			$node = self::node( $definition, $current );
			if ( 'end' === $node['type'] ) {
				return [ 'nodes' => $path, 'decisions' => $decisions ];
			}
			$step = self::transition( $definition, $current, $outputs );
			if ( null !== $step['decision'] ) {
				$decisions[ $current ] = $step['decision'];
			}
			$current = $step['to'];
		}
		throw new \UnexpectedValueException( 'Advanced graph route exceeded the node bound.' );
	}

	/**
	 * @param array<string,mixed> $outputs
	 * @return array{from:string,to:string,port:string,decision:?bool}
	 */
	private static function transition( GraphDefinition $definition, string $node_id, array $outputs ): array {
		$node = self::node( $definition, $node_id );
		if ( 'end' === $node['type'] ) {
			throw new \UnexpectedValueException( 'End node has no outgoing transition.' );
		}

		$decision = null;
		$port = 'next';
		if ( 'if_else' === $node['type'] ) {
			$config = $node['config'];
			$left = Binding::from_array( $config['left'] );
			$right = null === $config['right'] ? null : Binding::from_array( $config['right'] );
			if ( null === $left || ( null !== $config['right'] && null === $right ) ) {
				throw new \UnexpectedValueException( 'Advanced condition binding is malformed.' );
			}
			$context = [ 'outputs' => $outputs ];
			$decision = OperatorCatalog::evaluate(
				$config['operator'],
				BindingResolver::resolve( $left, $context ),
				null === $right ? null : BindingResolver::resolve( $right, $context )
			);
			$port = $decision ? 'true' : 'false';
		}

		$target = null;
		foreach ( $definition->graph()['edges'] as $edge ) {
			if ( $edge['from'] !== $node_id || $edge['from_port'] !== $port ) {
				continue;
			}
			if ( null !== $target ) {
				throw new \UnexpectedValueException( 'Advanced route has ambiguous outgoing edges.' );
			}
			$target = $edge['to'];
		}
		if ( null === $target ) {
			throw new \UnexpectedValueException( 'Advanced route is missing a required edge.' );
		}

		return [ 'from' => $node_id, 'to' => $target, 'port' => $port, 'decision' => $decision ];
	}

	/** @return array{id:string,type:string,config:array<string,mixed>} */
	private static function node( GraphDefinition $definition, string $node_id ): array {
		foreach ( $definition->graph()['nodes'] as $node ) {
			if ( $node['id'] === $node_id ) {
				return $node;
			}
		}
		throw new \UnexpectedValueException( 'Advanced route node is unknown.' );
	}

	private function require_valid( GraphDefinition $definition ): void {
		if ( ! $this->validator->validate( $definition )->is_valid() ) {
			throw new \UnexpectedValueException( 'Advanced graph has not passed semantic validation.' );
		}
	}
}
