<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;

use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityReference;
use CB\Automations\Condition\Condition;
use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

/**
 * Exact, bounded Advanced draft document codec. Runtime readiness is NOT
 * conferred by successfully decoding an authorable definition.
 */
final class GraphDefinitionCodec {
	private const ID = '/^[a-z][a-z0-9_]{0,63}$/D';
	private const PORT = '/^[a-z][a-z0-9_]{0,63}$/D';
	private const MAX_ITEMS = 512;

	public static function decode( array $data ): GraphDefinition {
		self::keys( $data, [ 'definition_version', 'mode', 'graph', 'layout' ] );
		if ( GraphDefinition::VERSION !== $data['definition_version'] || 'advanced' !== $data['mode'] ) {
			throw new UnexpectedValueException( 'Unsupported Advanced definition.' );
		}
		$graph = $data['graph'];
		$layout = $data['layout'];
		self::keys( $graph, [ 'nodes', 'edges' ] );
		self::keys( $layout, [ 'nodes' ] );
		if ( ! is_array( $graph['nodes'] ) || ! array_is_list( $graph['nodes'] )
			|| ! is_array( $graph['edges'] ) || ! array_is_list( $graph['edges'] )
			|| count( $graph['nodes'] ) > self::MAX_ITEMS || count( $graph['edges'] ) > self::MAX_ITEMS * 2
			|| ! is_array( $layout['nodes'] ) || array_is_list( $layout['nodes'] ) && [] !== $layout['nodes'] ) {
			throw new UnexpectedValueException( 'Malformed Advanced graph collections.' );
		}
		$nodes = [];
		foreach ( $graph['nodes'] as $node ) {
			self::keys( $node, [ 'id', 'type', 'config' ] );
			$id = $node['id'];
			$type = $node['type'];
			if ( ! self::id( $id ) || isset( $nodes[ $id ] )
				|| ! in_array( $type, [ 'trigger', 'state', 'if_else', 'action', 'exclusive_merge', 'end' ], true ) ) {
				throw new UnexpectedValueException( 'Invalid or duplicate Advanced node.' );
			}
			self::config( $node['config'], $type, $id );
			$nodes[ $id ] = true;
		}
		$edges = [];
		foreach ( $graph['edges'] as $edge ) {
			self::keys( $edge, [ 'id', 'from', 'from_port', 'to', 'to_port' ] );
			if ( ! self::id( $edge['id'] ) || isset( $edges[ $edge['id'] ] )
				|| ! self::id( $edge['from'] ) || ! self::id( $edge['to'] )
				|| ! self::port( $edge['from_port'] ) || ! self::port( $edge['to_port'] ) ) {
				throw new UnexpectedValueException( 'Invalid Advanced edge identity.' );
			}
			$edges[ $edge['id'] ] = true;
		}
		foreach ( $layout['nodes'] as $id => $point ) {
			self::keys( $point, [ 'x', 'y' ] );
			if ( ! is_string( $id ) || ! isset( $nodes[ $id ] )
				|| ! self::coordinate( $point['x'] ) || ! self::coordinate( $point['y'] ) ) {
				throw new UnexpectedValueException( 'Invalid Advanced layout coordinate.' );
			}
		}
		// Bounded serialized size also rejects unsafe values and encoding errors.
		try {
			$json = json_encode( $data, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			throw new UnexpectedValueException( 'Advanced document cannot be encoded.' );
		}
		if ( ! PersistencePolicy::allows_encoded_definition( $json ) ) {
			throw new UnexpectedValueException( 'Advanced definition is too large.' );
		}
		return GraphDefinition::from_validated( $graph, $layout );
	}

	/** @return array<string,mixed> */
	public static function encode( GraphDefinition $definition ): array {
		return $definition->to_array();
	}

	private static function config( mixed $value, string $type, string $id ): void {
		if ( [] === $value ) {
			return; // An incomplete but structurally safe disabled draft.
		}
		if ( in_array( $type, [ 'trigger', 'state', 'action' ], true ) ) {
			self::keys( $value, [ 'capability', 'bindings' ] );
			self::keys( $value['capability'], [ 'kind', 'provider', 'id', 'schema_version' ] );
			$reference = CapabilityReference::from_values(
				is_string( $value['capability']['kind'] ) ? $value['capability']['kind'] : '',
				is_string( $value['capability']['provider'] ) ? $value['capability']['provider'] : '',
				is_string( $value['capability']['id'] ) ? $value['capability']['id'] : '',
				is_string( $value['capability']['schema_version'] ) ? $value['capability']['schema_version'] : ''
			);
			if ( null === $reference || $reference->kind() !== $type || ! is_array( $value['bindings'] ) ) {
				throw new UnexpectedValueException( 'Advanced capability does not match node type.' );
			}
			$bindings = [];
			foreach ( $value['bindings'] as $field => $raw ) {
				if ( ! is_string( $field ) || 1 !== preg_match( '/^[a-z][a-z0-9_]*$/D', $field )
					|| ! is_array( $raw ) || null === ( $binding = Binding::from_array( $raw ) ) ) {
					throw new UnexpectedValueException( 'Malformed Advanced capability binding.' );
				}
				$bindings[ $field ] = $binding;
			}
			if ( null === Step::from_values( $id, $reference, $bindings ) ) {
				throw new UnexpectedValueException( 'Malformed Advanced capability step.' );
			}
			return;
		}
		if ( 'if_else' === $type ) {
			self::keys( $value, [ 'left', 'operator', 'right' ] );
			$left = is_array( $value['left'] ) ? Binding::from_array( $value['left'] ) : null;
			$right = null === $value['right'] ? null
				: ( is_array( $value['right'] ) ? Binding::from_array( $value['right'] ) : null );
			if ( null === $left || ( null !== $value['right'] && null === $right )
				|| ! is_string( $value['operator'] )
				|| null === Condition::from_values( $id, $left, $value['operator'], $right ) ) {
				throw new UnexpectedValueException( 'Malformed Advanced condition.' );
			}
			return;
		}
		throw new UnexpectedValueException( 'Nonconfigurable Advanced node must have empty config.' );
	}

	private static function id( mixed $id ): bool {
		return is_string( $id ) && 1 === preg_match( self::ID, $id );
	}

	private static function port( mixed $port ): bool {
		return is_string( $port ) && 1 === preg_match( self::PORT, $port );
	}

	private static function coordinate( mixed $value ): bool {
		return ( is_int( $value ) && abs( $value ) <= 100000000 )
			|| ( is_float( $value ) && is_finite( $value ) && abs( $value ) <= 100000000 );
	}

	/** @param string[] $expected */
	private static function keys( mixed $value, array $expected ): void {
		if ( ! is_array( $value ) || count( $value ) !== count( $expected )
			|| array_diff( array_keys( $value ), $expected )
			|| array_diff( $expected, array_keys( $value ) ) ) {
			throw new UnexpectedValueException( 'Advanced definition contains missing or unsupported fields.' );
		}
	}
}
