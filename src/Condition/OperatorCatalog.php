<?php
declare(strict_types=1);

namespace CB\Automations\Condition;

defined( 'ABSPATH' ) || exit;

final class OperatorCatalog {
	/** @return array<string,array{arity:int,left_types:string[]}> */
	public static function definitions(): array {
		return [
			'equals'                => [ 'arity' => 2, 'left_types' => [ 'string', 'integer', 'number', 'boolean', 'array' ] ],
			'not_equals'            => [ 'arity' => 2, 'left_types' => [ 'string', 'integer', 'number', 'boolean', 'array' ] ],
			'contains'              => [ 'arity' => 2, 'left_types' => [ 'string', 'array' ] ],
			'not_contains'          => [ 'arity' => 2, 'left_types' => [ 'string', 'array' ] ],
			'greater_than'          => [ 'arity' => 2, 'left_types' => [ 'integer', 'number' ] ],
			'greater_than_or_equal' => [ 'arity' => 2, 'left_types' => [ 'integer', 'number' ] ],
			'less_than'             => [ 'arity' => 2, 'left_types' => [ 'integer', 'number' ] ],
			'less_than_or_equal'    => [ 'arity' => 2, 'left_types' => [ 'integer', 'number' ] ],
			'is_empty'              => [ 'arity' => 1, 'left_types' => [ 'string', 'array' ] ],
			'is_not_empty'          => [ 'arity' => 1, 'left_types' => [ 'string', 'array' ] ],
		];
	}

	/** @return array{arity:int,left_types:string[]}|null */
	public static function get( string $operator ): ?array {
		$definitions = self::definitions();
		return $definitions[ $operator ] ?? null;
	}

	public static function is_supported( string $operator ): bool {
		return null !== self::get( $operator );
	}

	public static function evaluate( string $operator, mixed $left, mixed $right = null ): bool {
		$definition = self::get( $operator );
		if ( null === $definition ) {
			throw new \UnexpectedValueException( 'Automation condition operator is unsupported.' );
		}

		$left_type = self::runtime_type( $left );
		if ( null === $left_type || ! in_array( $left_type, $definition['left_types'], true ) ) {
			throw new \UnexpectedValueException( 'Automation condition left operand does not match the operator contract.' );
		}
		if ( 1 === $definition['arity'] && null !== $right ) {
			throw new \UnexpectedValueException( 'Unary automation condition received a right operand.' );
		}
		if ( 2 === $definition['arity'] && null === $right ) {
			throw new \UnexpectedValueException( 'Binary automation condition is missing its right operand.' );
		}

		if ( 2 === $definition['arity'] && ! self::runtime_operands_compatible( $operator, $left, $right ) ) {
			throw new \UnexpectedValueException( 'Automation condition operands do not match the operator contract.' );
		}

		return match ( $operator ) {
			'equals'                => self::values_equal( $left, $right ),
			'not_equals'            => ! self::values_equal( $left, $right ),
			'contains'              => self::contains( $left, $right ),
			'not_contains'          => ! self::contains( $left, $right ),
			'greater_than'          => $left > $right,
			'greater_than_or_equal' => $left >= $right,
			'less_than'             => $left < $right,
			'less_than_or_equal'    => $left <= $right,
			'is_empty'              => '' === $left || [] === $left,
			'is_not_empty'          => '' !== $left && [] !== $left,
			default                 => throw new \UnexpectedValueException( 'Automation condition operator has no evaluator.' ),
		};
	}

	private static function runtime_operands_compatible( string $operator, mixed $left, mixed $right ): bool {
		$left_type = self::runtime_type( $left );
		$right_type = self::runtime_type( $right );
		if ( null === $left_type || null === $right_type ) {
			return false;
		}

		if ( in_array( $operator, [ 'greater_than', 'greater_than_or_equal', 'less_than', 'less_than_or_equal' ], true ) ) {
			return self::is_numeric_type( $left_type ) && self::is_numeric_type( $right_type );
		}
		if ( in_array( $operator, [ 'contains', 'not_contains' ], true ) ) {
			if ( 'string' === $left_type ) {
				return 'string' === $right_type;
			}
			return 'array' === $left_type && 'array' !== $right_type;
		}
		if ( 'array' === $left_type || 'array' === $right_type ) {
			return 'array' === $left_type && 'array' === $right_type;
		}
		return $left_type === $right_type || ( self::is_numeric_type( $left_type ) && self::is_numeric_type( $right_type ) );
	}

	private static function contains( mixed $left, mixed $right ): bool {
		if ( is_string( $left ) && is_string( $right ) ) {
			return str_contains( $left, $right );
		}
		if ( ! is_array( $left ) || ! array_is_list( $left ) ) {
			throw new \UnexpectedValueException( 'Automation contains operand is malformed.' );
		}
		foreach ( $left as $item ) {
			if ( self::values_equal( $item, $right ) ) {
				return true;
			}
		}
		return false;
	}

	private static function values_equal( mixed $left, mixed $right ): bool {
		$left_type = self::runtime_type( $left );
		$right_type = self::runtime_type( $right );
		if ( null === $left_type || null === $right_type ) {
			return false;
		}
		if ( self::is_numeric_type( $left_type ) && self::is_numeric_type( $right_type ) ) {
			return $left == $right;
		}
		if ( is_array( $left ) && is_array( $right ) ) {
			if ( count( $left ) !== count( $right ) ) {
				return false;
			}
			foreach ( $left as $index => $value ) {
				if ( ! array_key_exists( $index, $right ) || ! self::values_equal( $value, $right[ $index ] ) ) {
					return false;
				}
			}
			return true;
		}
		return $left === $right;
	}

	private static function runtime_type( mixed $value ): ?string {
		if ( is_string( $value ) ) {
			return 'string';
		}
		if ( is_int( $value ) ) {
			return 'integer';
		}
		if ( is_float( $value ) && is_finite( $value ) ) {
			return 'number';
		}
		if ( is_bool( $value ) ) {
			return 'boolean';
		}
		if ( is_array( $value ) && array_is_list( $value ) ) {
			foreach ( $value as $item ) {
				if ( null === self::runtime_type( $item ) || is_array( $item ) ) {
					return null;
				}
			}
			return 'array';
		}
		return null;
	}

	private static function is_numeric_type( string $type ): bool {
		return in_array( $type, [ 'integer', 'number' ], true );
	}
}
