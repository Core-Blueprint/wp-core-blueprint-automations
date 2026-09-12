<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;
defined( 'ABSPATH' ) || exit;

final class RunCursor {
	public const CONDITIONS = 'conditions';
	public const COMPLETE = 'complete';

	public static function state_index( string $cursor, int $state_count ): ?int {
		if ( $state_count < 0 ) {
			throw new \InvalidArgumentException( 'State count cannot be negative.' );
		}
		if ( '' === $cursor ) {
			return 0;
		}
		if (
			self::CONDITIONS === $cursor
			|| self::COMPLETE === $cursor
			|| str_starts_with( $cursor, 'conditions:' )
			|| str_starts_with( $cursor, 'actions:' )
		) {
			return null;
		}
		if ( 1 !== preg_match( '/^states:([0-9]+)$/D', $cursor, $matches ) ) {
			throw new \UnexpectedValueException( 'Automation run cursor is invalid.' );
		}
		$index = (int) $matches[1];
		if ( $index < 0 || $index > $state_count ) {
			throw new \UnexpectedValueException( 'Automation state cursor exceeds the immutable run definition.' );
		}
		return $index;
	}

	public static function after_state( int $next_index, int $state_count ): string {
		if ( $next_index < 0 || $state_count < 0 || $next_index > $state_count ) {
			throw new \InvalidArgumentException( 'Invalid automation state cursor.' );
		}
		return $next_index === $state_count ? self::CONDITIONS : 'states:' . $next_index;
	}

	public static function condition_index( string $cursor, int $condition_count ): ?int {
		if ( $condition_count < 0 ) {
			throw new \InvalidArgumentException( 'Condition count cannot be negative.' );
		}
		if ( self::CONDITIONS === $cursor ) {
			return 0;
		}
		if ( self::COMPLETE === $cursor || str_starts_with( $cursor, 'actions:' ) ) {
			return null;
		}
		if ( 1 !== preg_match( '/^conditions:([0-9]+)$/D', $cursor, $matches ) ) {
			throw new \UnexpectedValueException( 'Automation condition cursor is invalid.' );
		}
		$index = (int) $matches[1];
		if ( $index < 0 || $index > $condition_count ) {
			throw new \UnexpectedValueException( 'Automation condition cursor exceeds the immutable run definition.' );
		}
		return $index;
	}

	public static function after_condition( int $next_index, int $condition_count ): string {
		if ( $next_index < 0 || $condition_count < 0 || $next_index > $condition_count ) {
			throw new \InvalidArgumentException( 'Invalid automation condition cursor.' );
		}
		return $next_index === $condition_count ? 'actions:0' : 'conditions:' . $next_index;
	}

	public static function action_index( string $cursor, int $action_count ): ?int {
		if ( $action_count < 0 ) {
			throw new \InvalidArgumentException( 'Action count cannot be negative.' );
		}
		if ( self::COMPLETE === $cursor ) {
			return null;
		}
		if ( 1 !== preg_match( '/^actions:([0-9]+)$/D', $cursor, $matches ) ) {
			throw new \UnexpectedValueException( 'Automation action cursor is invalid.' );
		}
		$index = (int) $matches[1];
		if ( $index < 0 || $index > $action_count ) {
			throw new \UnexpectedValueException( 'Automation action cursor exceeds the immutable run definition.' );
		}
		return $index;
	}

	public static function after_action( int $next_index, int $action_count ): string {
		if ( $next_index < 0 || $action_count < 0 || $next_index > $action_count ) {
			throw new \InvalidArgumentException( 'Invalid automation action cursor.' );
		}
		return $next_index === $action_count ? self::COMPLETE : 'actions:' . $next_index;
	}

	public static function is_valid( string $cursor ): bool {
		return '' === $cursor
			|| self::CONDITIONS === $cursor
			|| self::COMPLETE === $cursor
			|| 1 === preg_match( '/^(?:states|conditions|actions):[0-9]+$/D', $cursor );
	}
}
