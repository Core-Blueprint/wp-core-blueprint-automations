<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;

use CB\Automations\Validation\ValidationResult;

defined( 'ABSPATH' ) || exit;

final readonly class WorkflowSaveResult {
	public const SAVED               = 'saved';
	public const SAVED_DISABLED      = 'saved_disabled';
	public const PERSISTENCE_BLOCKED = 'persistence_blocked';
	public const CONFLICT            = 'conflict';

	private function __construct(
		private string $status,
		private ValidationResult $validation
	) {}

	public static function saved( ValidationResult $validation ): self {
		return new self( self::SAVED, $validation );
	}

	public static function saved_disabled( ValidationResult $validation ): self {
		return new self( self::SAVED_DISABLED, $validation );
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
		return in_array( $this->status, [ self::SAVED, self::SAVED_DISABLED ], true );
	}
}
