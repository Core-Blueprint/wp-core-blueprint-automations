<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

defined( 'ABSPATH' ) || exit;

final class VaultFailure extends \RuntimeException {
	public static function unavailable(): self {
		return new self( 'Automation runtime encryption is unavailable.' );
	}

	public static function invalid_envelope(): self {
		return new self( 'Automation runtime encryption envelope is invalid.' );
	}

	public static function authentication(): self {
		return new self( 'Automation runtime encrypted data could not be authenticated.' );
	}

	public static function payload(): self {
		return new self( 'Automation runtime payload is invalid or exceeds the supported size.' );
	}
}
