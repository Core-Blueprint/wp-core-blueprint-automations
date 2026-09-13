<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;

defined( 'ABSPATH' ) || exit;

final readonly class ExecutionAuthorityDecision {
	public const PRINCIPAL_MISSING = 'principal_missing';
	public const PRINCIPAL_INVALID = 'principal_invalid';
	public const PRINCIPAL_PERMISSION_DENIED = 'principal_permission_denied';
	public const OPERATOR_PERMISSION_DENIED = 'operator_permission_denied';
	public const CAPABILITY_UNAVAILABLE = 'capability_unavailable';

	private function __construct(
		private bool $allowed,
		private ?string $reason
	) {}

	public static function allow(): self {
		return new self( true, null );
	}

	public static function deny( string $reason ): self {
		$allowed = [
			self::PRINCIPAL_MISSING,
			self::PRINCIPAL_INVALID,
			self::PRINCIPAL_PERMISSION_DENIED,
			self::OPERATOR_PERMISSION_DENIED,
			self::CAPABILITY_UNAVAILABLE,
		];
		if ( ! in_array( $reason, $allowed, true ) ) {
			throw new \InvalidArgumentException( 'Unknown execution authority block reason.' );
		}
		return new self( false, $reason );
	}

	public function is_allowed(): bool {
		return $this->allowed;
	}

	public function reason(): ?string {
		return $this->reason;
	}
}
