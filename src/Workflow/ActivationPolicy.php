<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;

use CB\Automations\Validation\ValidationResult;

defined( 'ABSPATH' ) || exit;

final class ActivationPolicy {
	public static function allows( ActivationState $target, ValidationResult $validation ): bool {
		if ( ActivationState::Disabled === $target ) {
			return true;
		}

		return $validation->is_valid();
	}
}
