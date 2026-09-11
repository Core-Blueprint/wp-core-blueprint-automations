<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;

use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityReference;

defined( 'ABSPATH' ) || exit;

final readonly class Step {
	/** @param array<string,Binding> $bindings */
	private function __construct(
		private string $step_id,
		private CapabilityReference $capability,
		private array $bindings
	) {}

	/** @param array<string,Binding> $bindings */
	public static function from_values( string $step_id, CapabilityReference $capability, array $bindings = [] ): ?self {
		if ( 1 !== preg_match( '/^[a-z][a-z0-9_]{0,63}$/', $step_id ) ) {
			return null;
		}

		foreach ( $bindings as $field => $binding ) {
			if ( ! is_string( $field ) || 1 !== preg_match( '/^[a-z][a-z0-9_]*$/', $field ) || ! $binding instanceof Binding ) {
				return null;
			}
		}

		return new self( $step_id, $capability, $bindings );
	}

	public function step_id(): string {
		return $this->step_id;
	}

	public function capability(): CapabilityReference {
		return $this->capability;
	}

	/** @return array<string,Binding> */
	public function bindings(): array {
		return $this->bindings;
	}

	/** @return array{step_id:string,capability:array{kind:string,provider:string,id:string,schema_version:string},bindings:array<string,array<string,mixed>>} */
	public function to_array(): array {
		$bindings = [];
		foreach ( $this->bindings as $field => $binding ) {
			$bindings[ $field ] = $binding->to_array();
		}

		return [
			'step_id'    => $this->step_id,
			'capability' => $this->capability->to_array(),
			'bindings'   => $bindings,
		];
	}
}
