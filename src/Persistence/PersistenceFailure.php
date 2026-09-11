<?php
declare(strict_types=1);

namespace CB\Automations\Persistence;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class PersistenceFailure extends RuntimeException {
	public static function schema(): self {
		return new self( 'Automations persistence schema is unavailable.' );
	}

	public static function database( string $operation ): self {
		return new self( sprintf( 'Automations persistence failed to %s.', $operation ) );
	}

	public static function definition(): self {
		return new self( 'Stored workflow definition is malformed or unsupported.' );
	}
}
