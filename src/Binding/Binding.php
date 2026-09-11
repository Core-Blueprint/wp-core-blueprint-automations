<?php
declare(strict_types=1);

namespace CB\Automations\Binding;

defined( 'ABSPATH' ) || exit;

final readonly class Binding {
	public const SOURCE_LITERAL     = 'literal';
	public const SOURCE_STEP_OUTPUT = 'step_output';

	private function __construct(
		private string $source,
		private mixed $value,
		private ?string $step_id,
		private ?string $field
	) {}

	public static function literal( mixed $value ): ?self {
		if ( ! self::is_supported_literal( $value ) ) {
			return null;
		}
		return new self( self::SOURCE_LITERAL, $value, null, null );
	}

	public static function step_output( string $step_id, string $field ): ?self {
		if ( ! self::is_identifier( $step_id ) || ! self::is_field( $field ) ) {
			return null;
		}
		return new self( self::SOURCE_STEP_OUTPUT, null, $step_id, $field );
	}

	/** @param array<string,mixed> $data */
	public static function from_array( array $data ): ?self {
		$source = is_string( $data['source'] ?? null ) ? $data['source'] : '';

		if ( self::SOURCE_LITERAL === $source ) {
			if ( ! self::has_exact_keys( $data, [ 'source', 'value' ] ) || ! array_key_exists( 'value', $data ) ) {
				return null;
			}
			return self::literal( $data['value'] );
		}

		if ( self::SOURCE_STEP_OUTPUT === $source ) {
			if ( ! self::has_exact_keys( $data, [ 'source', 'step_id', 'field' ] ) ) {
				return null;
			}
			return self::step_output(
				is_string( $data['step_id'] ?? null ) ? $data['step_id'] : '',
				is_string( $data['field'] ?? null ) ? $data['field'] : ''
			);
		}

		return null;
	}

	public function source(): string {
		return $this->source;
	}

	public function value(): mixed {
		return $this->value;
	}

	public function step_id(): ?string {
		return $this->step_id;
	}

	public function field(): ?string {
		return $this->field;
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		if ( self::SOURCE_LITERAL === $this->source ) {
			return [ 'source' => self::SOURCE_LITERAL, 'value' => $this->value ];
		}

		return [
			'source'  => self::SOURCE_STEP_OUTPUT,
			'step_id' => $this->step_id,
			'field'   => $this->field,
		];
	}

	private static function is_supported_literal( mixed $value ): bool {
		if ( is_string( $value ) || is_int( $value ) || is_bool( $value ) ) {
			return true;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value );
		}
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) && ! is_int( $item ) && ! is_bool( $item ) && ! ( is_float( $item ) && is_finite( $item ) ) ) {
				return false;
			}
		}
		return true;
	}

	private static function is_identifier( string $value ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $value );
	}

	private static function is_field( string $value ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9_]*$/', $value );
	}

	/** @param string[] $expected */
	private static function has_exact_keys( array $data, array $expected ): bool {
		$keys = array_keys( $data );
		sort( $keys );
		sort( $expected );
		return $keys === $expected;
	}
}
