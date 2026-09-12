<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;
defined( 'ABSPATH' ) || exit;

final readonly class ConditionPhaseResult {
	public const MATCHED = 'matched';
	public const SKIPPED = 'skipped';
	public const FAILED = 'failed';
	public const BLOCKED = 'blocked';

	private function __construct( private string $status, private string $error_code = '' ) {}

	public static function matched(): self { return new self( self::MATCHED ); }
	public static function skipped(): self { return new self( self::SKIPPED ); }
	public static function failed( string $error_code ): self { return new self( self::FAILED, $error_code ); }
	public static function blocked( string $error_code ): self { return new self( self::BLOCKED, $error_code ); }

	public function status(): string { return $this->status; }
	public function error_code(): string { return $this->error_code; }
	public function is_matched(): bool { return self::MATCHED === $this->status; }
}
