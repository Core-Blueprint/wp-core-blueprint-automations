<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Discovery\CapabilitySource;
use CB\Automations\Workflow\GraphDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * ADV-02B B3: project the next Advanced State/Action from a verified prefix.
 *
 * The acknowledged node IDs and their outputs MUST eventually originate from
 * a durable, immutable, lease-fenced run ledger. This projector does NOT verify
 * that provenance, acknowledge work, execute a provider, or grant admission.
 * Never use caller-supplied IDs to authorize an action in a production worker.
 */
final class GraphCheckpointPlanner {
	private GraphRoutePlanner $routes;
	private GraphNodeIntentPlanner $intents;

	public function __construct( CapabilitySource $catalog ) {
		$this->routes  = new GraphRoutePlanner( $catalog );
		$this->intents = new GraphNodeIntentPlanner( $catalog );
	}

	/**
	 * @param list<string> $acknowledged Ordered IDs of durably completed State/Action nodes.
	 * @param array<string,array<string,mixed>> $outputs Protected outputs, not for logs or UI.
	 * @return array{
	 *   status:string,
	 *   route:list<string>,
	 *   decisions:array<string,bool>,
	 *   intent:?array{node_id:string,kind:string,capability:\CB\Automations\Capability\CapabilityReference,input:array<string,mixed>}
	 * }
	 */
	public function project( GraphDefinition $definition, array $acknowledged, array $outputs ): array {
		if ( ! array_is_list( $acknowledged ) || count( $acknowledged ) > 512 ) {
			throw new \UnexpectedValueException( 'Advanced acknowledgements are not a bounded list.' );
		}
		$known = [];
		foreach ( $acknowledged as $id ) {
			if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/D', $id ) || isset( $known[ $id ] ) ) {
				throw new \UnexpectedValueException( 'Advanced acknowledgements contain an invalid node ID.' );
			}
			$known[ $id ] = true;
		}

		$nodes = [];
		$trigger = null;
		foreach ( $definition->graph()['nodes'] as $node ) {
			$nodes[ $node['id'] ] = $node;
			if ( 'trigger' === $node['type'] ) {
				if ( null !== $trigger ) {
					throw new \UnexpectedValueException( 'Advanced checkpoint has multiple triggers.' );
				}
				$trigger = $node['id'];
			}
		}
		if ( null === $trigger ) {
			throw new \UnexpectedValueException( 'Advanced checkpoint has no trigger.' );
		}

		foreach ( $outputs as $node_id => $value ) {
			if (
				! is_string( $node_id )
				|| ! is_array( $value )
				|| ( $node_id !== $trigger && ! isset( $known[ $node_id ] ) )
			) {
				throw new \UnexpectedValueException( 'Advanced checkpoint outputs contain an unacknowledged source.' );
			}
		}

		$route = [];
		$visited = [];
		$decisions = [];
		$confirmed = 0;
		$current = $trigger;
		$maximum = count( $nodes );
		while ( count( $route ) < $maximum ) {
			if ( isset( $visited[ $current ] ) || ! isset( $nodes[ $current ] ) ) {
				throw new \UnexpectedValueException( 'Advanced checkpoint route has invalid ancestry.' );
			}
			$visited[ $current ] = true;
			$route[] = $current;
			$type = $nodes[ $current ]['type'];

			if ( 'end' === $type ) {
				if ( $confirmed !== count( $acknowledged ) ) {
					throw new \UnexpectedValueException( 'Advanced checkpoint contains acknowledgements beyond End.' );
				}
				return [
					'status'    => 'complete',
					'route'     => $route,
					'decisions' => $decisions,
					'intent'    => null,
				];
			}

			if ( in_array( $type, [ 'state', 'action' ], true ) ) {
				if ( $confirmed < count( $acknowledged ) ) {
					if ( $acknowledged[ $confirmed ] !== $current ) {
						throw new \UnexpectedValueException( 'Advanced checkpoint acknowledgement diverges from the chosen route.' );
					}
					++$confirmed;
				} else {
					// This is an intent only. The caller must first acquire
					// execution authority and a durable idempotent lease.
					return [
						'status'    => 'pending',
						'route'     => $route,
						'decisions' => $decisions,
						'intent'    => $this->intents->plan( $definition, $current, $outputs ),
					];
				}
			}

			$transition = $this->routes->next( $definition, $current, $outputs );
			if ( null !== $transition['decision'] ) {
				$decisions[ $current ] = $transition['decision'];
			}
			$current = $transition['to'];
		}

		throw new \UnexpectedValueException( 'Advanced checkpoint route exceeded the graph node bound.' );
	}
}
