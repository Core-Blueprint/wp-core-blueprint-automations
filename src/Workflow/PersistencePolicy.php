<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;

use CB\Automations\Validation\ValidationResult;

defined( 'ABSPATH' ) || exit;

final class PersistencePolicy {
	public static function allows( ValidationResult $validation ): bool {
		return ! $validation->has_code( 'privacy.sensitive_literal' );
	}
}
