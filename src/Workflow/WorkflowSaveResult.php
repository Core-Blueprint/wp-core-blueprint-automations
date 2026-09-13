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
	public const WORKFLOW_INVALID    = 'workflow_invalid';
	public const EXECUTION_UNAVAILABLE = 'execution_unavailable';

	private function __construct(
		private string $status,
		private ValidationResult $validation,
		private ?ActivationState $activation_state = null,
		private ?int $execution_principal_user_id = null,
		private ?string $activation_block_reason = null
	) {}

	public static function saved( ValidationResult $validation, ActivationState $state, int $principal_user_id ): self {
		return new self( self::SAVED, $validation, $state, $principal_user_id );
	}

	public static function saved_disabled( ValidationResult $validation, int $principal_user_id, string $reason ): self {
		return new self( self::SAVED_DISABLED, $validation, ActivationState::Disabled, $principal_user_id, $reason );
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

	public function activation_state(): ?ActivationState {
		return $this->activation_state;
	}

	public function execution_principal_user_id(): ?int {
		return $this->execution_principal_user_id;
	}

	public function activation_block_reason(): ?string {
		return $this->activation_block_reason;
	}

	public function was_saved(): bool {
		return in_array( $this->status, [ self::SAVED, self::SAVED_DISABLED ], true );
	}
}
