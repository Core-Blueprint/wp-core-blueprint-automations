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
	 * ADV-03B-R2: preview-only diagnostic inspection of a saved Advanced graph.
	 * This shares transition() and GraphValidator with trace(), and NEVER
	 * invokes providers, saves fixtures, or persists a run. Statuses describe
	 * hypothetical visits, not executed Actions.
	 *
	 * @param array<string,mixed> $outputs
	 * @return array{nodes:list<string>,decisions:array<string,bool>,results:array<string,array{status:string,reason:string,fixture:bool}>,complete:bool,blocked:?string,reason:?string}
	 */
	public function inspect( GraphDefinition $definition, array $outputs ): array {
		$this->require_valid( $definition );
		$nodes = $definition->graph()['nodes'];
		$indexed = [];
		$results = [];
		$start = null;
		foreach ( $nodes as $node ) {
			$id = $node['id'];
			$indexed[ $id ] = $node;
			$results[ $id ] = [ 'status' => 'skipped', 'reason' => 'not_on_route', 'fixture' => isset( $outputs[ $id ] ) ];
			if ( 'trigger' === $node['type'] ) $start = $id;
		}
		if ( null === $start ) throw new \UnexpectedValueException( 'Advanced graph has no trigger.' );
		$path = []; $decisions = []; $seen = []; $current = $start;
		while ( count( $path ) < count( $nodes ) ) {
			if ( isset( $seen[ $current ] ) || ! isset( $indexed[ $current ] ) )
				throw new \UnexpectedValueException( 'Advanced graph route revisited an invalid node.' );
			$seen[ $current ] = true;
			$path[] = $current;
			$node = $indexed[ $current ];
			$results[ $current ]['status'] = 'simulated';
			$results[ $current ]['reason'] = 'route_only';
			if ( 'end' === $node['type'] ) return [
				'nodes' => $path, 'decisions' => $decisions, 'results' => $results,
				'complete' => true, 'blocked' => null, 'reason' => null,
			];
			try {
				$transition = self::transition( $definition, $current, $outputs );
			} catch ( \UnexpectedValueException ) {
				$missing = self::missing_condition_sample( $node, $outputs );
				$reason = $missing ? 'sample_required' : 'condition_invalid';
				$results[ $current ]['status'] = $missing ? 'blocked' : 'failed';
				$results[ $current ]['reason'] = $reason;
				return [
					'nodes' => $path, 'decisions' => $decisions, 'results' => $results,
					'complete' => false, 'blocked' => $current, 'reason' => $reason,
				];
			}
			if ( null !== $transition['decision'] ) $decisions[ $current ] = $transition['decision'];
			$current = $transition['to'];
		}
		throw new \UnexpectedValueException( 'Advanced preview route exceeded node bound.' );
	}

	/** @param array<string,mixed> $node @param array<string,mixed> $outputs */
	private static function missing_condition_sample( array $node, array $outputs ): bool {
		if ( 'if_else' !== $node['type'] ) return false;
		foreach ( [ $node['config']['left'] ?? null, $node['config']['right'] ?? null ] as $binding ) {
			if ( ! is_array( $binding ) || ( $binding['source'] ?? null ) !== 'step_output' ) continue;
			$id = $binding['step_id'] ?? null;
			$field = $binding['field'] ?? null;
			if ( ! is_string( $id ) || ! is_string( $field )
				|| ! isset( $outputs[ $id ] ) || ! is_array( $outputs[ $id ] )
				|| ! array_key_exists( $field, $outputs[ $id ] ) ) return true;
		}
		return false;
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
