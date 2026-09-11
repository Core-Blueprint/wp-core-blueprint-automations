<?php
declare(strict_types=1);

namespace CB\Automations\Discovery;

defined( 'ABSPATH' ) || exit;

final readonly class CapabilityReference {
	private function __construct(
		private string $kind,
		private string $provider,
		private string $id,
		private string $schema_version
	) {}

	/** @param array<string,mixed> $definition */
	public static function from_base_definition( string $kind, array $definition ): ?self {
		$provider       = (string) ( $definition['provider'] ?? '' );
		$id             = (string) ( $definition['id'] ?? '' );
		$schema_version = (string) ( $definition['schema_version'] ?? '' );

		if (
			! CapabilityKind::is_valid( $kind )
			|| '' === $provider
			|| '' === $id
			|| 1 !== preg_match( '/^[1-9]\\d*$/', $schema_version )
		) {
			return null;
		}

		return new self( $kind, $provider, $id, $schema_version );
	}

	public function kind(): string {
		return $this->kind;
	}

	public function provider(): string {
		return $this->provider;
	}

	public function id(): string {
		return $this->id;
	}

	public function schema_version(): string {
		return $this->schema_version;
	}

	/** @return array{kind:string,provider:string,id:string,schema_version:string} */
	public function to_array(): array {
		return [
			'kind'           => $this->kind,
			'provider'       => $this->provider,
			'id'             => $this->id,
			'schema_version' => $this->schema_version,
		];
	}

	public function key(): string {
		return implode( ':', [ $this->kind, $this->provider, $this->id, $this->schema_version ] );
	}
}
