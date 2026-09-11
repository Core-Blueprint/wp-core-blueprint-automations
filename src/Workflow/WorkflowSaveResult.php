<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;

use CB\Automations\Validation\ValidationResult;

defined( 'ABSPATH' ) || exit;

final readonly class WorkflowSaveResult {
	public const SAVED               = 'saved';
	public const VALIDATION_FAILED   = 'validation_failed';
	public const PERSISTENCE_BLOCKED = 'persistence_blocked';
	public const CONFLICT            = 'conflict';

	private function __construct(
		private string $status,
		private ValidationResult $validation
	) {}

	public static function saved( ValidationResult $validation ): self {
		return new self( self::SAVED, $validation );
	}

	public static function validation_failed( ValidationResult $validation ): self {
		return new self( self::VALIDATION_FAILED, $validation );
	}

	public static function persistence_blocked( ValidationResult $validation ): self {
		return new self( self::PERSISTENCE_BLOCKED, $validation );
	}

	public static function conflict( ValidationResult $validation ): self {
		return new self( self::CONFLICT, $validation );
	}

	public function status(): string {
		return $this->status;
	}

	public function validation(): ValidationResult {
		return $this->validation;
	}

	public function was_saved(): bool {
		return self::SAVED === $this->status;
	}
}
