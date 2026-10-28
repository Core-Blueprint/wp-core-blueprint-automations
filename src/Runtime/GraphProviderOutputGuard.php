<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Capability\CapabilityReference;
use CB\Automations\Discovery\CapabilityDefinition;
use CB\Automations\Discovery\CapabilitySource;

defined( 'ABSPATH' ) || exit;

/**
 * ADV-02C C3: verify a provider result against its exact current capability.
 *
 * This validates *shape*, not provenance. A future authoritative invoker must
 * call Core Base and supply the actual returned value. No caller-supplied
 * output becomes a trusted execution witness merely by passing this check.
 */
final class GraphProviderOutputGuard {
	/** @param array<string,mixed> $output */
	public static function require_valid(
		CapabilitySource $catalog,
		CapabilityReference $reference,
		array $output
	): void {
		if ( ! in_array( $reference->kind(), [ 'state', 'action' ], true ) ) {
			throw new \UnexpectedValueException( 'Advanced output requires a State or Action capability.' );
		}
		try {
			$available = $catalog->provider_status( $reference->provider() )->is_available();
			$definition = $available ? $catalog->current( $reference ) : null;
		} catch ( \Throwable ) {
			throw new \UnexpectedValueException( 'Advanced output provider cannot be verified.' );
		}
		if ( ! $available || ! $definition instanceof CapabilityDefinition
			|| $definition->reference()->key() !== $reference->key() ) {
			throw new \UnexpectedValueException( 'Advanced output capability identity is unavailable or changed.' );
		}
		$schema = $definition->output_schema();
		foreach ( $schema as $name => $field ) {
			if ( ! is_string( $name ) || ! is_array( $field ) || ! isset( $field['type'] )
				|| ( ! empty( $field['required'] ) && ! array_key_exists( $name, $output ) ) ) {
				throw new \UnexpectedValueException( 'Advanced required output or schema is invalid.' );
			}
		}
		foreach ( $output as $name => $value ) {
			$field = $schema[ $name ] ?? null;
			if ( ! is_string( $name ) || ! is_array( $field ) || ! self::matches( $value, $field ) ) {
				throw new \UnexpectedValueException( 'Advanced result does not match its exact provider output schema.' );
			}
		}
	}

	/** @param array<string,mixed> $field */
	private static function matches( mixed $value, array $field ): bool {
		return match ( $field['type'] ?? null ) {
			'string' => is_string( $value ),
			'integer' => is_int( $value ),
			'number' => is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ),
			'boolean' => is_bool( $value ),
			'array' => is_array( $value ) && array_is_list( $value )
				&& self::items_match( $value, $field['items'] ?? null ),
			default => false,
		};
	}

	/** @param list<mixed> $values */
	private static function items_match( array $values, mixed $type ): bool {
		if ( ! is_string( $type ) ) {
			return false;
		}
		foreach ( $values as $item ) {
			if ( ! self::matches( $item, [ 'type' => $type ] ) ) {
				return false;
			}
		}
		return true;
	}
}
