<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Binding\Binding;

defined( 'ABSPATH' ) || exit;

final class BindingResolver {
	/**
	 * @param array<string,Binding> $bindings
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public static function resolve_all( array $bindings, array $context ): array {
		$resolved = [];
		foreach ( $bindings as $field => $binding ) {
			if ( ! is_string( $field ) || ! $binding instanceof Binding ) {
				throw new \UnexpectedValueException( 'Automation binding map is malformed.' );
			}
			$resolved[ $field ] = self::resolve( $binding, $context );
		}
		return $resolved;
	}

	/** @param array<string,mixed> $context */
	public static function resolve( Binding $binding, array $context ): mixed {
		if ( Binding::SOURCE_LITERAL === $binding->source() ) {
			return $binding->value();
		}
		if ( Binding::SOURCE_STEP_OUTPUT !== $binding->source() ) {
			throw new \UnexpectedValueException( 'Automation binding source is unsupported.' );
		}

		$step_id = $binding->step_id();
		$field = $binding->field();
		$outputs = $context['outputs'] ?? null;
		if (
			! is_string( $step_id )
			|| ! is_string( $field )
			|| ! is_array( $outputs )
			|| ! isset( $outputs[ $step_id ] )
			|| ! is_array( $outputs[ $step_id ] )
			|| ! array_key_exists( $field, $outputs[ $step_id ] )
		) {
			throw new \UnexpectedValueException( 'Automation step output binding cannot be resolved.' );
		}
		return $outputs[ $step_id ][ $field ];
	}
}
