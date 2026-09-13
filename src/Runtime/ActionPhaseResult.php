<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;
defined( 'ABSPATH' ) || exit;

final readonly class ActionPhaseResult {
	public const COMPLETE = 'complete';
	public const FAILED = 'failed';
	public const BLOCKED = 'blocked';
	public const INDETERMINATE = 'indeterminate';

	private function __construct( private string $status, private string $error_code = '', private string $provider_error_code = '' ) {}

	public static function complete(): self { return new self( self::COMPLETE ); }
	public static function failed( string $error_code, string $provider_error_code = '' ): self { return new self( self::FAILED, $error_code, $provider_error_code ); }
	public static function blocked( string $error_code, string $provider_error_code = '' ): self { return new self( self::BLOCKED, $error_code, $provider_error_code ); }
	public static function indeterminate( string $error_code, string $provider_error_code = '' ): self { return new self( self::INDETERMINATE, $error_code, $provider_error_code ); }

	public function status(): string { return $this->status; }
	public function error_code(): string { return $this->error_code; }
	public function provider_error_code(): string { return $this->provider_error_code; }
	public function is_complete(): bool { return self::COMPLETE === $this->status; }
}
