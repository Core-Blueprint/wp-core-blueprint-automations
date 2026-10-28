<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityReference;
use CB\Automations\Discovery\CapabilityDefinition;
use CB\Automations\Discovery\CapabilitySource;
use CB\Automations\Validation\GraphValidator;
use CB\Automations\Workflow\GraphDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * ADV-02B B2: pre-invocation, read-only graph node intent.
 *
 * Resolve a single State/Action node's canonical capability and typed input.
 * This class NEVER calls a provider, persists data, dispatches jobs or grants
 * runtime authority. The returned input can be sensitive: never log it, store
 * it in workflow metadata, or expose it through an admin response.
 *
 * Only a separately accepted lease-fenced executor may consume this intent.
 */
final class GraphNodeIntentPlanner {
	private GraphValidator $validator;

	public function __construct( private CapabilitySource $catalog ) {
		$this->validator = new GraphValidator( $catalog );
	}

	/**
	 * @param array<string,mixed> $outputs Already acknowledged prior step outputs.
	 * @return array{node_id:string,kind:string,capability:CapabilityReference,input:array<string,mixed>}
	 */
	public function plan( GraphDefinition $definition, string $node_id, array $outputs ): array {
		if ( ! $this->validator->validate( $definition )->is_valid() ) {
			throw new \UnexpectedValueException( 'Advanced node intent requires a valid graph.' );
		}

		$node = null;
		foreach ( $definition->graph()['nodes'] as $entry ) {
			if ( $entry['id'] === $node_id ) {
				$node = $entry;
				break;
			}
		}
		if ( null === $node || ! in_array( $node['type'], [ 'state', 'action' ], true ) ) {
			throw new \UnexpectedValueException( 'Only configured Advanced State/Action nodes can be planned.' );
		}

		$config = $node['config'];
		$raw = $config['capability'];
		$reference = CapabilityReference::from_values(
			$raw['kind'], $raw['provider'], $raw['id'], $raw['schema_version']
		);
		if ( null === $reference || $reference->kind() !== $node['type'] ) {
			throw new \UnexpectedValueException( 'Advanced node capability kind is invalid.' );
		}

		// Repeat this check immediately before planning an invocation. A
		// previously discovered provider may have been deactivated.
		try {
			if ( ! $this->catalog->provider_status( $reference->provider() )->is_available() ) {
				throw new \UnexpectedValueException( 'Advanced node provider is unavailable.' );
			}
			$current = $this->catalog->current( $reference );
		} catch ( \Throwable ) {
			throw new \UnexpectedValueException( 'Advanced node provider could not be verified.' );
		}
		if ( ! $current instanceof CapabilityDefinition || $current->reference()->key() !== $reference->key() ) {
			throw new \UnexpectedValueException( 'Advanced node capability identity changed.' );
		}

		$bindings = [];
		foreach ( $config['bindings'] as $field => $data ) {
			$binding = Binding::from_array( $data );
			if ( null === $binding ) {
				throw new \UnexpectedValueException( 'Advanced node input binding is malformed.' );
			}
			$bindings[ $field ] = $binding;
		}
		$input = BindingResolver::resolve_all( $bindings, [ 'outputs' => $outputs ] );
		self::require_valid_input( $input, $current->input_schema() );

		return [
			'node_id' => $node_id,
			'kind' => $node['type'],
			'capability' => $reference,
			'input' => $input,
		];
	}

	/**
	 * @param array<string,mixed> $input
	 * @param array<string,array<string,mixed>> $schema
	 */
	private static function require_valid_input( array $input, array $schema ): void {
		foreach ( $schema as $field => $definition ) {
			if ( ! empty( $definition['required'] ) && ! array_key_exists( $field, $input ) ) {
				throw new \UnexpectedValueException( 'Advanced node required input is missing.' );
			}
		}
		foreach ( $input as $field => $value ) {
			$definition = $schema[ $field ] ?? null;
			if ( ! is_array( $definition ) || ! self::matches( $value, $definition ) ) {
				throw new \UnexpectedValueException( 'Advanced node input does not match provider schema.' );
			}
		}
	}

	/** @param array<string,mixed> $schema */
	private static function matches( mixed $value, array $schema ): bool {
		return match ( $schema['type'] ?? null ) {
			'string'  => is_string( $value ),
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ),
			'boolean' => is_bool( $value ),
			'array'   => is_array( $value ) && array_is_list( $value )
				&& self::matches_items( $value, $schema['items'] ?? null ),
			default   => false,
		};
	}

	/** @param list<mixed> $items */
	private static function matches_items( array $items, mixed $item_type ): bool {
		if ( ! is_string( $item_type ) ) {
			return false;
		}
		foreach ( $items as $value ) {
			if ( ! self::matches( $value, [ 'type' => $item_type ] ) ) {
				return false;
			}
		}
		return true;
	}
}
