<?php
declare(strict_types=1);

namespace CB\Automations\Validation;

use CB\Automations\Capability\CapabilityReference;
use CB\Automations\Condition\OperatorCatalog;
use CB\Automations\Discovery\CapabilitySource;
use CB\Automations\Workflow\GraphDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * Advanced v2 draft diagnostics. Validity here NEVER enables runtime admission:
 * all v2 workflows remain disabled until a separately accepted graph executor.
 */
final class GraphValidator {
	public function __construct( private CapabilitySource $catalog ) {}

	public function validate( GraphDefinition $definition ): ValidationResult {
		$issues = [];
		$add = static function ( string $code, string $path ) use ( &$issues ): void {
			$issues[] = new ValidationIssue( $code, $path );
		};
		$graph = $definition->graph();
		$nodes = [];
		$out = [];
		$in = [];
		$types = [];
		$capabilities = [];
		$counts = [ 'trigger' => 0, 'end' => 0 ];

		foreach ( $graph['nodes'] as $index => $node ) {
			$id = $node['id'];
			$nodes[ $id ] = $node;
			$types[ $id ] = $node['type'];
			$out[ $id ] = [];
			$in[ $id ] = [];
			if ( isset( $counts[ $node['type'] ] ) ) {
				++$counts[ $node['type'] ];
			}
			$config = $node['config'];
			if ( [] === $config ) {
				if ( ! in_array( $node['type'], [ 'exclusive_merge', 'end' ], true ) ) {
					$add( 'graph.configuration_incomplete', 'graph.nodes.' . $index . '.config' );
				}
				continue;
			}
			if ( in_array( $node['type'], [ 'trigger', 'state', 'action' ], true ) ) {
				$ref = $config['capability'];
				$reference = CapabilityReference::from_values( $ref['kind'], $ref['provider'], $ref['id'], $ref['schema_version'] );
				if ( null === $reference ) {
					$add( 'graph.capability_invalid', 'graph.nodes.' . $index );
					continue;
				}
				try {
					$available = $this->catalog->provider_status( $reference->provider() )->is_available();
					$current = $available ? $this->catalog->current( $reference ) : null;
					if ( ! $available || null === $current || $current->reference()->key() !== $reference->key() ) {
						$add( 'capability.missing', 'graph.nodes.' . $index . '.config.capability' );
					} else {
						$capabilities[ $id ] = $current;
						foreach ( $current->input_schema() as $field => $schema ) {
							if ( ! empty( $schema['required'] ) && ! array_key_exists( $field, $config['bindings'] ) ) {
								$add( 'binding.required_missing', 'graph.nodes.' . $index . '.config.bindings.' . $field );
							}
						}
						foreach ( $config['bindings'] as $field => $binding ) {
							$schema = $current->input_schema()[ $field ] ?? null;
							if ( ! is_array( $schema ) ) {
								$add( 'binding.unknown_field', 'graph.nodes.' . $index . '.config.bindings.' . $field );
							} elseif ( 'literal' === $binding['source'] && ! empty( $schema['sensitive'] ) ) {
								$add( 'privacy.sensitive_literal', 'graph.nodes.' . $index . '.config.bindings.' . $field );
							} elseif ( 'literal' === $binding['source'] && ! self::literal_matches( $binding['value'], $schema ) ) {
								$add( 'binding.type_mismatch', 'graph.nodes.' . $index . '.config.bindings.' . $field );
							}
						}
					}
				} catch ( \Throwable ) {
					$add( 'capability.unavailable', 'graph.nodes.' . $index . '.config.capability' );
				}
				// Without a verified provider schema, any literal might be sensitive.
				if ( ! isset( $capabilities[ $id ] ) ) {
					foreach ( $config['bindings'] as $field => $binding ) {
						if ( 'literal' === $binding['source'] ) {
							$add( 'privacy.unverified_literal', 'graph.nodes.' . $index . '.config.bindings.' . $field );
						}
					}
				}
			} elseif ( 'if_else' === $node['type'] ) {
				$operator = OperatorCatalog::get( $config['operator'] );
				if ( null === $operator ) {
					$add( 'condition.operator_unsupported', 'graph.nodes.' . $index . '.config.operator' );
				} elseif ( ( 1 === $operator['arity'] ) !== ( null === $config['right'] ) ) {
					$add( 'condition.arity_mismatch', 'graph.nodes.' . $index . '.config.right' );
				}
			}
		}

		if ( 1 !== $counts['trigger'] ) $add( 'graph.trigger_count', 'graph.nodes' );
		if ( 1 !== $counts['end'] ) $add( 'graph.end_count', 'graph.nodes' );

		$connections = [];
		$inputs = [];
		$outputs = [];
		foreach ( $graph['edges'] as $i => $edge ) {
			$from = $edge['from'];
			$to = $edge['to'];
			$source = $types[ $from ] ?? null;
			$target = $types[ $to ] ?? null;
			if ( null === $source || null === $target || $from === $to ) {
				$add( 'graph.edge_endpoint', 'graph.edges.' . $i );
				continue;
			}
			$valid_out = 'if_else' === $source ? [ 'true', 'false' ] : ( 'end' === $source ? [] : [ 'next' ] );
			$valid_in = 'exclusive_merge' === $target ? [ 'true', 'false' ] : ( 'trigger' === $target ? [] : [ 'in' ] );
			if ( ! in_array( $edge['from_port'], $valid_out, true )
				|| ! in_array( $edge['to_port'], $valid_in, true ) ) {
				$add( 'graph.port_invalid', 'graph.edges.' . $i );
				continue;
			}
			$key = $from . ':' . $edge['from_port'] . '>' . $to . ':' . $edge['to_port'];
			$source_port = $from . ':' . $edge['from_port'];
			$target_port = $to . ':' . $edge['to_port'];
			if ( isset( $connections[ $key ] ) || isset( $outputs[ $source_port ] )
				|| isset( $inputs[ $target_port ] ) ) {
				$add( 'graph.port_occupied', 'graph.edges.' . $i );
				continue;
			}
			$connections[ $key ] = true;
			$outputs[ $source_port ] = true;
			$inputs[ $target_port ] = true;
			$out[ $from ][ $edge['from_port'] ] = $to;
			$in[ $to ][ $edge['to_port'] ] = $from;
		}

		// A DAG with exactly one entry/end and no dead ends can be walked safely.
		$starts = array_keys( array_filter( $types, static fn ( string $type ): bool => 'trigger' === $type ) );
		$ends = array_keys( array_filter( $types, static fn ( string $type ): bool => 'end' === $type ) );
		$start = 1 === count( $starts ) ? $starts[0] : null;
		$end = 1 === count( $ends ) ? $ends[0] : null;
		$visited = [];
		$stack = null === $start ? [] : [ $start ];
		while ( $stack ) {
			$current = array_pop( $stack );
			if ( isset( $visited[ $current ] ) ) continue;
			$visited[ $current ] = true;
			foreach ( $out[ $current ] as $next ) $stack[] = $next;
		}
		foreach ( $types as $id => $type ) {
			if ( ! isset( $visited[ $id ] ) ) $add( 'graph.unreachable', 'graph.nodes.' . $id );
			$expected_in = match ( $type ) {
				'trigger' => 0,
				'exclusive_merge' => 2,
				default => 1,
			};
			$expected_out = match ( $type ) {
				'end' => 0,
				'if_else' => 2,
				default => 1,
			};
			if ( count( $in[ $id ] ) !== $expected_in || count( $out[ $id ] ) !== $expected_out ) {
				$add( 'graph.incomplete_ports', 'graph.nodes.' . $id );
			}
			if ( 'if_else' === $type && ( ! isset( $out[ $id ]['true'], $out[ $id ]['false'] ) ) ) {
				$add( 'graph.branch_incomplete', 'graph.nodes.' . $id );
			}
			if ( 'exclusive_merge' === $type && ( ! isset( $in[ $id ]['true'], $in[ $id ]['false'] ) ) ) {
				$add( 'graph.merge_incomplete', 'graph.nodes.' . $id );
			}
		}

		// Kahn ordering detects cycles and supplies stable path-dominance input.
		$degree = [];
		foreach ( $types as $id => $type ) $degree[ $id ] = count( $in[ $id ] );
		$queue = [];
		foreach ( $degree as $id => $n ) if ( 0 === $n ) $queue[] = $id;
		$order = [];
		for ( $p = 0; $p < count( $queue ); $p++ ) {
			$id = $queue[ $p ];
			$order[] = $id;
			foreach ( $out[ $id ] as $next ) {
				if ( --$degree[ $next ] === 0 ) $queue[] = $next;
			}
		}
		if ( count( $order ) !== count( $types ) ) $add( 'graph.cycle', 'graph.edges' );

		// Dominators: only a producer on EVERY path to a consumer is guaranteed.
		$dominators = [];
		foreach ( $order as $id ) {
			if ( $id === $start ) {
				$dominators[ $id ] = [ $id => true ];
				continue;
			}
			$parents = array_values( $in[ $id ] );
			$shared = null;
			foreach ( $parents as $parent ) {
				if ( ! isset( $dominators[ $parent ] ) ) continue;
				$shared = null === $shared ? $dominators[ $parent ] : array_intersect_key( $shared, $dominators[ $parent ] );
			}
			$dominators[ $id ] = ( $shared ?? [] ) + [ $id => true ];
		}

		// IF/ELSE has an explicit named exclusive merge; both branches must
		// reach it and no branch may terminate or cross the opposite branch.
		foreach ( $nodes as $id => $node ) {
			if ( 'if_else' !== $node['type'] || [] === $node['config'] ) continue;
			$merge = $node['config']['merge_id'];
			if ( ! isset( $types[ $merge ] ) || 'exclusive_merge' !== $types[ $merge ]
				|| ! isset( $out[ $id ]['true'], $out[ $id ]['false'] ) ) {
				$add( 'graph.merge_invalid', 'graph.nodes.' . $id . '.config.merge_id' );
				continue;
			}
			$true_nodes = $this->branch_path( $out, $out[ $id ]['true'], $merge );
			$false_nodes = $this->branch_path( $out, $out[ $id ]['false'], $merge );
			if ( null === $true_nodes || null === $false_nodes
				|| array_intersect_key( $true_nodes, $false_nodes )
				|| ! isset( $dominators[ $merge ][ $id ] ) ) {
				$add( 'graph.branch_structure', 'graph.nodes.' . $id );
			}
		}

		foreach ( $nodes as $id => $node ) {
			$config = $node['config'];
			if ( [] === $config ) continue;
			$bindings = [];
			if ( in_array( $node['type'], [ 'trigger', 'state', 'action' ], true ) ) {
				$bindings = $config['bindings'];
			} elseif ( 'if_else' === $node['type'] ) {
				$bindings = [ 'left' => $config['left'] ];
				if ( null !== $config['right'] ) $bindings['right'] = $config['right'];
			}
			foreach ( $bindings as $field => $binding ) {
				if ( 'step_output' !== $binding['source'] ) continue;
				$producer = $binding['step_id'];
				if ( ! isset( $types[ $producer ] )
					|| ! isset( $dominators[ $id ][ $producer ] )
					|| $producer === $id ) {
					$add( 'binding.source_not_guaranteed', 'graph.nodes.' . $id . '.config.' . $field );
					continue;
				}
				if ( isset( $capabilities[ $producer ] ) ) {
					$source_schema = $capabilities[ $producer ]->output_schema()[ $binding['field'] ] ?? null;
					if ( ! is_array( $source_schema ) ) {
						$add( 'binding.field_missing', 'graph.nodes.' . $id . '.config.' . $field );
					} elseif ( isset( $capabilities[ $id ] ) ) {
						$target_schema = $capabilities[ $id ]->input_schema()[ $field ] ?? null;
						if ( is_array( $target_schema ) && ! self::compatible_schema( $source_schema, $target_schema ) ) {
							$add( 'binding.type_mismatch', 'graph.nodes.' . $id . '.config.' . $field );
						}
					}
				}
			}
		}
		// Nothing in v2 is eligible for activation until ADV-02B/C is accepted.
		return new ValidationResult( $issues );
	}


	private static function literal_matches( mixed $value, array $schema ): bool {
		$type = $schema['type'] ?? null;
		return match ( $type ) {
			'string' => is_string( $value ),
			'integer' => is_int( $value ),
			'number' => is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ),
			'boolean' => is_bool( $value ),
			'array' => is_array( $value ) && array_is_list( $value )
				&& array_reduce( $value, static function ( bool $ok, mixed $item ) use ( $schema ): bool {
					$inner = $schema['items'] ?? null;
					return $ok && is_string( $inner ) && self::literal_matches( $item, [ 'type' => $inner ] );
				}, true ),
			default => false,
		};
	}

	private static function compatible_schema( array $source, array $target ): bool {
		$source_type = $source['type'] ?? null;
		$target_type = $target['type'] ?? null;
		$source_semantic = $source['semantic_type'] ?? null;
		$target_semantic = $target['semantic_type'] ?? null;
		if ( null !== $target_semantic && $source_semantic !== $target_semantic ) return false;
		if ( 'number' === $target_type && 'integer' === $source_type ) return true;
		if ( $source_type !== $target_type || ! is_string( $source_type ) ) return false;
		if ( 'array' === $source_type ) return ( $source['items'] ?? null ) === ( $target['items'] ?? null );
		return true;
	}

	/** All outgoing paths must reach the specified merge before reaching End. */
	private function branch_path( array $out, string $entry, string $merge ): ?array {
		$seen = [];
		$stack = [ $entry ];
		while ( $stack ) {
			$id = array_pop( $stack );
			if ( $id === $merge ) continue;
			if ( isset( $seen[ $id ] ) ) continue;
			if ( ! isset( $out[ $id ] ) || [] === $out[ $id ] ) return null;
			$seen[ $id ] = true;
			foreach ( $out[ $id ] as $next ) $stack[] = $next;
		}
		return $seen;
	}
}
