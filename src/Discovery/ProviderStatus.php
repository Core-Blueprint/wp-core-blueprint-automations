<?php
declare(strict_types=1);

namespace CB\Automations\Discovery;

defined( 'ABSPATH' ) || exit;

final readonly class ProviderStatus {
	private function __construct(
		private string $id,
		private string $name,
		private bool $installed,
		private bool $active,
		private bool $registered,
		private bool $compatible,
		private string $health,
		private string $health_detail,
		private bool $base_provider
	) {}

	public static function base(): self {
		return new self(
			'core-blueprint',
			'Core Blueprint Base',
			true,
			true,
			true,
			true,
			'ok',
			'Ready',
			true
		);
	}

	/** @param array<string,mixed>|null $inventory */
	public static function from_inventory( string $id, ?array $inventory ): self {
		if ( null === $inventory ) {
			return new self( $id, $id, false, false, false, false, '', '', false );
		}

		return new self(
			$id,
			(string) ( $inventory['name'] ?? $id ),
			(bool) ( $inventory['installed'] ?? false ),
			(bool) ( $inventory['active'] ?? false ),
			(bool) ( $inventory['registered'] ?? false ),
			(bool) ( $inventory['compatible'] ?? false ),
			(string) ( $inventory['health'] ?? '' ),
			(string) ( $inventory['health_detail'] ?? '' ),
			false
		);
	}

	public function id(): string {
		return $this->id;
	}

	public function name(): string {
		return $this->name;
	}

	public function installed(): bool {
		return $this->installed;
	}

	public function active(): bool {
		return $this->active;
	}

	public function registered(): bool {
		return $this->registered;
	}

	public function compatible(): bool {
		return $this->compatible;
	}

	public function health(): string {
		return $this->health;
	}

	public function health_detail(): string {
		return $this->health_detail;
	}

	public function is_available(): bool {
		return $this->active && $this->compatible && ( $this->base_provider || $this->registered );
	}

	public function is_base_provider(): bool {
		return $this->base_provider;
	}
}
