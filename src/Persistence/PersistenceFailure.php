<?php
declare(strict_types=1);

namespace CB\Automations\Persistence;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class PersistenceFailure extends RuntimeException {
	private const DEFINITION_FAILURE_CODE = 1001;

	/** Stable internal classification. Never inspect exception prose to identify stored-definition failure. */
	public function is_definition_failure(): bool {
		return self::DEFINITION_FAILURE_CODE === $this->getCode();
	}
	public static function schema(): self {
		return new self( 'Automations persistence schema is unavailable.' );
	}

	public static function database( string $operation ): self {
		return new self( sprintf( 'Automations persistence failed to %s.', $operation ) );
	}

	public static function definition(): self {
		return new self( 'Stored workflow definition is malformed or unsupported.', self::DEFINITION_FAILURE_CODE );
	}
}
