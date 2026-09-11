<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Automations\Discovery\CapabilityDefinition;

defined( 'ABSPATH' ) || exit;

final class EditorDefinitionProjection {
	/**
	 * Produce an editor-safe copy of a stored workflow definition.
	 *
	 * Sensitive literal input bindings and conditions that compare a sensitive
	 * output with a literal are omitted from the browser projection. The stored
	 * definition is never mutated by this operation.
	 *
	 * @param array<string,mixed>                $definition
	 * @param array<string,CapabilityDefinition> $capabilities Identity-keyed current capabilities.
	 * @return array{definition:array<string,mixed>,redacted:int}
	 */
	public static function project( array $definition, array $capabilities ): array {
		$projected        = $definition;
		$redacted         = 0;
		$sensitive_output = self::sensitive_output_map( $projected, $capabilities );

		foreach ( [ 'states', 'actions' ] as $collection ) {
			if ( ! isset( $projected[ $collection ] ) || ! is_array( $projected[ $collection ] ) ) {
				continue;
			}

			foreach ( $projected[ $collection ] as &$step ) {
				if ( ! is_array( $step ) || ! is_array( $step['bindings'] ?? null ) ) {
					continue;
				}

				$capability = self::definition_for_step( $step, $capabilities );
				if ( null === $capability ) {
					continue;
				}

				$schema = $capability->input_schema();
				foreach ( $step['bindings'] as $field => $binding ) {
					if (
						! is_string( $field )
						|| ! is_array( $binding )
						|| 'literal' !== ( $binding['source'] ?? null )
						|| true !== ( $schema[ $field ]['sensitive'] ?? false )
					) {
						continue;
					}

					unset( $step['bindings'][ $field ] );
					++$redacted;
				}
			}
			unset( $step );
		}

		if ( isset( $projected['conditions'] ) && is_array( $projected['conditions'] ) ) {
			$kept = [];
			foreach ( $projected['conditions'] as $condition ) {
				if ( ! is_array( $condition ) ) {
					$kept[] = $condition;
					continue;
				}

				$left  = is_array( $condition['left'] ?? null ) ? $condition['left'] : [];
				$right = is_array( $condition['right'] ?? null ) ? $condition['right'] : [];
				$left_sensitive  = self::binding_uses_sensitive_output( $left, $sensitive_output );
				$right_sensitive = self::binding_uses_sensitive_output( $right, $sensitive_output );
				$left_literal    = 'literal' === ( $left['source'] ?? null );
				$right_literal   = 'literal' === ( $right['source'] ?? null );

				if ( ( $left_sensitive && $right_literal ) || ( $right_sensitive && $left_literal ) ) {
					++$redacted;
					continue;
				}

				$kept[] = $condition;
			}
			$projected['conditions'] = array_values( $kept );
		}

		return [ 'definition' => $projected, 'redacted' => $redacted ];
	}

	/**
	 * @param array<string,mixed>                $definition
	 * @param array<string,CapabilityDefinition> $capabilities
	 * @return array<string,bool>
	 */
	private static function sensitive_output_map( array $definition, array $capabilities ): array {
		$map   = [];
		$steps = [];
		if ( is_array( $definition['trigger'] ?? null ) ) {
			$steps[] = $definition['trigger'];
		}
		foreach ( [ 'states', 'actions' ] as $collection ) {
			if ( is_array( $definition[ $collection ] ?? null ) ) {
				$steps = array_merge( $steps, $definition[ $collection ] );
			}
		}

		foreach ( $steps as $step ) {
			if ( ! is_array( $step ) || ! is_string( $step['step_id'] ?? null ) ) {
				continue;
			}
			$capability = self::definition_for_step( $step, $capabilities );
			if ( null === $capability ) {
				continue;
			}
			foreach ( $capability->output_schema() as $field => $schema ) {
				if ( is_string( $field ) && true === ( $schema['sensitive'] ?? false ) ) {
					$map[ $step['step_id'] . ':' . $field ] = true;
				}
			}
		}
		return $map;
	}

	/**
	 * @param array<string,mixed>                $step
	 * @param array<string,CapabilityDefinition> $capabilities
	 */
	private static function definition_for_step( array $step, array $capabilities ): ?CapabilityDefinition {
		$reference = is_array( $step['capability'] ?? null ) ? $step['capability'] : [];
		$key = self::identity_key(
			is_string( $reference['kind'] ?? null ) ? $reference['kind'] : '',
			is_string( $reference['provider'] ?? null ) ? $reference['provider'] : '',
			is_string( $reference['id'] ?? null ) ? $reference['id'] : ''
		);
		return $capabilities[ $key ] ?? null;
	}

	/** @param array<string,mixed> $binding @param array<string,bool> $sensitive_output */
	private static function binding_uses_sensitive_output( array $binding, array $sensitive_output ): bool {
		if ( 'step_output' !== ( $binding['source'] ?? null ) ) {
			return false;
		}
		$step_id = is_string( $binding['step_id'] ?? null ) ? $binding['step_id'] : '';
		$field   = is_string( $binding['field'] ?? null ) ? $binding['field'] : '';
		return true === ( $sensitive_output[ $step_id . ':' . $field ] ?? false );
	}

	private static function identity_key( string $kind, string $provider, string $id ): string {
		return $kind . ':' . $provider . ':' . $id;
	}
}
