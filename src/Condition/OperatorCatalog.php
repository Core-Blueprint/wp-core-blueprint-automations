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
			'is_empty'               => [ 'arity' => 1, 'left_types' => [ 'string', 'array' ] ],
			'is_not_empty'           => [ 'arity' => 1, 'left_types' => [ 'string', 'array' ] ],
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
}
