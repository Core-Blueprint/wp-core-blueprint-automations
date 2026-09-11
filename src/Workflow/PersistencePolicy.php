<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;

use CB\Automations\Validation\ValidationResult;

defined( 'ABSPATH' ) || exit;

final class PersistencePolicy {
	public const MAX_DEFINITION_BYTES = 262144;

	public static function allows( ValidationResult $validation ): bool {
		return ! $validation->has_code( 'privacy.sensitive_literal' );
	}

	public static function allows_encoded_definition( string $json ): bool {
		return strlen( $json ) <= self::MAX_DEFINITION_BYTES;
	}
}
