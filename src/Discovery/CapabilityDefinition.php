<?php
declare(strict_types=1);

namespace CB\Automations\Discovery;

defined( 'ABSPATH' ) || exit;

final readonly class CapabilityDefinition {
	/**
	 * @param array<string,array<string,mixed>> $input_schema
	 * @param array<string,array<string,mixed>> $output_schema
	 */
	private function __construct(
		private CapabilityReference $reference,
		private string $label,
		private string $description,
		private array $input_schema,
		private array $output_schema,
		private ?string $required_capability
	) {}

	/** @param array<string,mixed> $definition */
	public static function from_base_definition( string $kind, array $definition ): ?self {
		$reference = CapabilityReference::from_base_definition( $kind, $definition );
		if ( null === $reference ) {
			return null;
		}

		$input_schema        = [];
		$output_schema       = [];
		$required_capability = null;

		if ( CapabilityKind::TRIGGER === $kind ) {
			$output_schema = is_array( $definition['payload_schema'] ?? null )
				? $definition['payload_schema']
				: [];
		} else {
			$input_schema = is_array( $definition['input_schema'] ?? null )
				? $definition['input_schema']
				: [];
			$output_schema = is_array( $definition['output_schema'] ?? null )
				? $definition['output_schema']
				: [];

			$required = (string) ( $definition['required_capability'] ?? '' );
			$required_capability = '' !== $required ? $required : null;
		}

		return new self(
			$reference,
			(string) ( $definition['label'] ?? $reference->id() ),
			(string) ( $definition['description'] ?? '' ),
			$input_schema,
			$output_schema,
			$required_capability
		);
	}

	public function reference(): CapabilityReference {
		return $this->reference;
	}

	public function label(): string {
		return $this->label;
	}

	public function description(): string {
		return $this->description;
	}

	/** @return array<string,array<string,mixed>> */
	public function input_schema(): array {
		return $this->input_schema;
	}

	/** @return array<string,array<string,mixed>> */
	public function output_schema(): array {
		return $this->output_schema;
	}

	public function required_capability(): ?string {
		return $this->required_capability;
	}

	public function has_sensitive_output(): bool {
		foreach ( $this->output_schema as $field ) {
			if ( true === ( $field['sensitive'] ?? false ) ) {
				return true;
			}
		}

		return false;
	}
}
