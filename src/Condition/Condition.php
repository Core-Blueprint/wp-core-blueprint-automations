<?php
declare(strict_types=1);

namespace CB\Automations\Condition;

use CB\Automations\Binding\Binding;

defined( 'ABSPATH' ) || exit;

final readonly class Condition {
	private function __construct(
		private string $condition_id,
		private Binding $left,
		private string $operator,
		private ?Binding $right
	) {}

	public static function from_values( string $condition_id, Binding $left, string $operator, ?Binding $right ): ?self {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $condition_id ) ) {
			return null;
		}
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $operator ) ) {
			return null;
		}

		return new self( $condition_id, $left, $operator, $right );
	}

	public function condition_id(): string {
		return $this->condition_id;
	}

	public function left(): Binding {
		return $this->left;
	}

	public function operator(): string {
		return $this->operator;
	}

	public function right(): ?Binding {
		return $this->right;
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'condition_id' => $this->condition_id,
			'left'         => $this->left->to_array(),
			'operator'     => $this->operator,
			'right'        => null === $this->right ? null : $this->right->to_array(),
		];
	}
}
