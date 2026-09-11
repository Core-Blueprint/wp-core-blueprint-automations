<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;

use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityReference;
use CB\Automations\Condition\Condition;
use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

final class DefinitionCodec {
	/** @param array<string,mixed> $data */
	public static function decode( array $data ): Definition {
		self::assert_exact_keys( $data, [ 'definition_version', 'trigger', 'states', 'conditions', 'actions' ], 'workflow definition' );

		if ( Definition::VERSION !== ( $data['definition_version'] ?? null ) ) {
			throw new UnexpectedValueException( 'Unsupported workflow definition version.' );
		}

		$trigger = null;
		if ( null !== $data['trigger'] ) {
			if ( ! is_array( $data['trigger'] ) ) {
				throw new UnexpectedValueException( 'Workflow trigger must be an object or null.' );
			}
			$trigger = self::decode_step( $data['trigger'] );
		}

		$states     = self::decode_step_list( $data['states'] ?? null, 'states' );
		$conditions = self::decode_condition_list( $data['conditions'] ?? null );
		$actions    = self::decode_step_list( $data['actions'] ?? null, 'actions' );

		$definition = Definition::from_values( $trigger, $states, $conditions, $actions );
		if ( null === $definition ) {
			throw new UnexpectedValueException( 'Workflow definition contains duplicate or malformed identifiers.' );
		}

		return $definition;
	}

	/** @return array<string,mixed> */
	public static function encode( Definition $definition ): array {
		return $definition->to_array();
	}

	/** @param array<string,mixed> $data */
	private static function decode_step( array $data ): Step {
		self::assert_exact_keys( $data, [ 'step_id', 'capability', 'bindings' ], 'workflow step' );

		$step_id = is_string( $data['step_id'] ?? null ) ? $data['step_id'] : '';
		if ( ! is_array( $data['capability'] ?? null ) ) {
			throw new UnexpectedValueException( 'Workflow step capability must be an object.' );
		}
		$capability = self::decode_capability( $data['capability'] );

		if ( ! is_array( $data['bindings'] ?? null ) ) {
			throw new UnexpectedValueException( 'Workflow step bindings must be an object.' );
		}

		$bindings = [];
		foreach ( $data['bindings'] as $field => $binding_data ) {
			if ( ! is_string( $field ) || ! is_array( $binding_data ) ) {
				throw new UnexpectedValueException( 'Workflow step contains a malformed binding.' );
			}
			$binding = Binding::from_array( $binding_data );
			if ( null === $binding ) {
				throw new UnexpectedValueException( 'Workflow step contains a malformed binding.' );
			}
			$bindings[ $field ] = $binding;
		}

		$step = Step::from_values( $step_id, $capability, $bindings );
		if ( null === $step ) {
			throw new UnexpectedValueException( 'Workflow step contains a malformed identifier.' );
		}
		return $step;
	}

	/** @param array<string,mixed> $data */
	private static function decode_capability( array $data ): CapabilityReference {
		self::assert_exact_keys( $data, [ 'kind', 'provider', 'id', 'schema_version' ], 'capability reference' );

		$reference = CapabilityReference::from_values(
			is_string( $data['kind'] ?? null ) ? $data['kind'] : '',
			is_string( $data['provider'] ?? null ) ? $data['provider'] : '',
			is_string( $data['id'] ?? null ) ? $data['id'] : '',
			is_string( $data['schema_version'] ?? null ) ? $data['schema_version'] : ''
		);
		if ( null === $reference ) {
			throw new UnexpectedValueException( 'Workflow contains a malformed capability reference.' );
		}
		return $reference;
	}

	/** @return Step[] */
	private static function decode_step_list( mixed $data, string $label ): array {
		if ( ! is_array( $data ) || ! array_is_list( $data ) ) {
			throw new UnexpectedValueException( sprintf( 'Workflow %s must be a list.', $label ) );
		}

		$steps = [];
		foreach ( $data as $item ) {
			if ( ! is_array( $item ) ) {
				throw new UnexpectedValueException( sprintf( 'Workflow %s contains a malformed step.', $label ) );
			}
			$steps[] = self::decode_step( $item );
		}
		return $steps;
	}

	/** @return Condition[] */
	private static function decode_condition_list( mixed $data ): array {
		if ( ! is_array( $data ) || ! array_is_list( $data ) ) {
			throw new UnexpectedValueException( 'Workflow conditions must be a list.' );
		}

		$conditions = [];
		foreach ( $data as $item ) {
			if ( ! is_array( $item ) ) {
				throw new UnexpectedValueException( 'Workflow conditions contains a malformed condition.' );
			}
			self::assert_exact_keys( $item, [ 'condition_id', 'left', 'operator', 'right' ], 'condition' );
			if ( ! is_array( $item['left'] ?? null ) ) {
				throw new UnexpectedValueException( 'Condition left operand must be a binding.' );
			}
			$left = Binding::from_array( $item['left'] );
			if ( null === $left ) {
				throw new UnexpectedValueException( 'Condition left operand is malformed.' );
			}

			$right = null;
			if ( null !== ( $item['right'] ?? null ) ) {
				if ( ! is_array( $item['right'] ) ) {
					throw new UnexpectedValueException( 'Condition right operand must be a binding or null.' );
				}
				$right = Binding::from_array( $item['right'] );
				if ( null === $right ) {
					throw new UnexpectedValueException( 'Condition right operand is malformed.' );
				}
			}

			$condition = Condition::from_values(
				is_string( $item['condition_id'] ?? null ) ? $item['condition_id'] : '',
				$left,
				is_string( $item['operator'] ?? null ) ? $item['operator'] : '',
				$right
			);
			if ( null === $condition ) {
				throw new UnexpectedValueException( 'Condition contains a malformed identifier or operator.' );
			}
			$conditions[] = $condition;
		}
		return $conditions;
	}

	/** @param string[] $expected */
	private static function assert_exact_keys( array $data, array $expected, string $label ): void {
		$keys = array_keys( $data );
		sort( $keys );
		sort( $expected );
		if ( $keys !== $expected ) {
			throw new UnexpectedValueException( sprintf( 'Malformed %s: unexpected or missing fields.', $label ) );
		}
	}
}
