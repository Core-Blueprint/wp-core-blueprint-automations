<?php
declare(strict_types=1);

namespace CB\Automations\Discovery;

defined( 'ABSPATH' ) || exit;

final class CapabilityKind {
	public const TRIGGER = 'trigger';
	public const ACTION  = 'action';
	public const STATE   = 'state';

	/** @return string[] */
	public static function all(): array {
		return [ self::TRIGGER, self::STATE, self::ACTION ];
	}

	public static function is_valid( string $kind ): bool {
		return in_array( $kind, self::all(), true );
	}
}
