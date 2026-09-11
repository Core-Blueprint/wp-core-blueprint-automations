<?php
declare(strict_types=1);

namespace CB\Automations\Validation;

use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityKind;
use CB\Automations\Condition\Condition;
use CB\Automations\Condition\OperatorCatalog;
use CB\Automations\Discovery\CapabilityDefinition;
use CB\Automations\Discovery\CapabilitySource;
use CB\Automations\Discovery\ProviderStatus;
use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\Step;

defined( 'ABSPATH' ) || exit;

final class WorkflowValidator {
	public function __construct( private CapabilitySource $catalog ) {}

	public function validate( Definition $definition ): ValidationResult {
		$issues      = [];
		$resolved    = [];
		$steps_by_id = [];
		$order_by_id = [];
		$order       = 0;

		$trigger = $definition->trigger();
		if ( null === $trigger ) {
			$issues[] = new ValidationIssue( 'workflow.trigger_missing', 'trigger' );
		} else {
			$steps_by_id[ $trigger->step_id() ] = $trigger;
			$order_by_id[ $trigger->step_id() ] = $order++;
			$capability = $this->validate_capability( $trigger, CapabilityKind::TRIGGER, 'trigger', $issues );
			if ( null !== $capability ) {
				$resolved[ $trigger->step_id() ] = $capability;
			}
			if ( [] !== $trigger->bindings() ) {
				$issues[] = new ValidationIssue( 'binding.trigger_has_bindings', 'trigger.bindings' );
			}
		}

		foreach ( $definition->states() as $index => $step ) {
			$path = sprintf( 'states.%d', $index );
			$steps_by_id[ $step->step_id() ] = $step;
			$order_by_id[ $step->step_id() ] = $order++;
			$capability = $this->validate_capability( $step, CapabilityKind::STATE, $path, $issues );
			if ( null !== $capability ) {
				$resolved[ $step->step_id() ] = $capability;
			}
		}

		$condition_order = $order;

		if ( [] === $definition->actions() ) {
			$issues[] = new ValidationIssue( 'workflow.action_missing', 'actions' );
		}
		foreach ( $definition->actions() as $index => $step ) {
			$path = sprintf( 'actions.%d', $index );
			$steps_by_id[ $step->step_id() ] = $step;
			$order_by_id[ $step->step_id() ] = $order++;
			$capability = $this->validate_capability( $step, CapabilityKind::ACTION, $path, $issues );
			if ( null !== $capability ) {
				$resolved[ $step->step_id() ] = $capability;
			}
		}

		foreach ( $definition->states() as $index => $step ) {
			$this->validate_step_bindings(
				$step,
				sprintf( 'states.%d', $index ),
				$order_by_id[ $step->step_id() ],
				$steps_by_id,
				$order_by_id,
				$resolved,
				$issues
			);
		}

		foreach ( $definition->conditions() as $index => $condition ) {
			$this->validate_condition(
				$condition,
				sprintf( 'conditions.%d', $index ),
				$condition_order,
				$steps_by_id,
				$order_by_id,
				$resolved,
				$issues
			);
		}

		foreach ( $definition->actions() as $index => $step ) {
			$this->validate_step_bindings(
				$step,
				sprintf( 'actions.%d', $index ),
				$order_by_id[ $step->step_id() ],
				$steps_by_id,
				$order_by_id,
				$resolved,
				$issues
			);
		}

		return new ValidationResult( $issues, $this->contains_sensitive_paths( $definition, $resolved ) );
	}

	/** @param ValidationIssue[] $issues */
	private function validate_capability( Step $step, string $expected_kind, string $path, array &$issues ): ?CapabilityDefinition {
		$reference = $step->capability();
		if ( $reference->kind() !== $expected_kind ) {
			$issues[] = new ValidationIssue(
				'capability.kind_mismatch',
				$path . '.capability.kind',
				[ 'expected' => $expected_kind, 'actual' => $reference->kind() ]
			);
			return null;
		}

		$status = $this->catalog->provider_status( $reference->provider() );
		if ( ! $status->is_available() ) {
			$issues[] = new ValidationIssue(
				$this->provider_issue_code( $status ),
				$path . '.capability.provider',
				[ 'provider' => $reference->provider() ]
			);
			return null;
		}

		$current = $this->catalog->current( $reference );
		if ( null === $current ) {
			$issues[] = new ValidationIssue(
				'capability.missing',
				$path . '.capability',
				[ 'provider' => $reference->provider(), 'id' => $reference->id() ]
			);
			return null;
		}

		$current_version = $current->reference()->schema_version();
		if ( $current_version !== $reference->schema_version() ) {
			$issues[] = new ValidationIssue(
				'capability.schema_mismatch',
				$path . '.capability.schema_version',
				[ 'stored' => $reference->schema_version(), 'current' => $current_version ]
			);
			return null;
		}

		return $current;
	}

	private function provider_issue_code( ProviderStatus $status ): string {
		if ( $status->installed() && ! $status->active() ) {
			return 'dependency.provider_inactive';
		}
		if ( $status->installed() && ! $status->compatible() ) {
			return 'dependency.provider_incompatible';
		}
		if ( $status->active() && ! $status->registered() ) {
			return 'dependency.provider_unregistered';
		}
		return 'dependency.provider_unavailable';
	}

	/**
	 * @param array<string,Step>                 $steps_by_id
	 * @param array<string,int>                  $order_by_id
	 * @param array<string,CapabilityDefinition> $resolved
	 * @param ValidationIssue[]                  $issues
	 */
	private function validate_step_bindings(
		Step $step,
		string $path,
		int $consumer_order,
		array $steps_by_id,
		array $order_by_id,
		array $resolved,
		array &$issues
	): void {
		$target = $resolved[ $step->step_id() ] ?? null;
		if ( null === $target ) {
			return;
		}

		$schema   = $target->input_schema();
		$bindings = $step->bindings();

		foreach ( $schema as $field => $definition ) {
			if ( true === ( $definition['required'] ?? false ) && ! array_key_exists( $field, $bindings ) ) {
				$issues[] = new ValidationIssue( 'binding.required_missing', $path . '.bindings.' . $field, [ 'field' => $field ] );
			}
		}

		foreach ( $bindings as $field => $binding ) {
			$binding_path = $path . '.bindings.' . $field;
			if ( ! isset( $schema[ $field ] ) ) {
				$issues[] = new ValidationIssue( 'binding.input_unknown', $binding_path, [ 'field' => $field ] );
				continue;
			}

			if ( Binding::SOURCE_LITERAL === $binding->source() ) {
				if ( true === ( $schema[ $field ]['sensitive'] ?? false ) ) {
					$issues[] = new ValidationIssue( 'privacy.sensitive_literal', $binding_path, [ 'field' => $field ] );
					continue;
				}
				if ( ! $this->literal_matches_schema( $binding->value(), $schema[ $field ] ) ) {
					$issues[] = new ValidationIssue( 'binding.type_mismatch', $binding_path, [ 'field' => $field ] );
				}
				continue;
			}

			$source = $this->resolve_output_binding(
				$binding,
				$binding_path,
				$consumer_order,
				$steps_by_id,
				$order_by_id,
				$resolved,
				$issues
			);
			if ( null !== $source && ! $this->schemas_compatible( $source, $schema[ $field ] ) ) {
				$issues[] = new ValidationIssue( 'binding.type_mismatch', $binding_path, [ 'field' => $field ] );
			}
		}
	}

	/**
	 * @param array<string,Step>                 $steps_by_id
	 * @param array<string,int>                  $order_by_id
	 * @param array<string,CapabilityDefinition> $resolved
	 * @param ValidationIssue[]                  $issues
	 */
	private function validate_condition(
		Condition $condition,
		string $path,
		int $consumer_order,
		array $steps_by_id,
		array $order_by_id,
		array $resolved,
		array &$issues
	): void {
		$operator = OperatorCatalog::get( $condition->operator() );
		if ( null === $operator ) {
			$issues[] = new ValidationIssue( 'condition.operator_unsupported', $path . '.operator', [ 'operator' => $condition->operator() ] );
			return;
		}

		$right = $condition->right();
		if ( 1 === $operator['arity'] && null !== $right ) {
			$issues[] = new ValidationIssue( 'condition.arity_mismatch', $path . '.right' );
		}
		if ( 2 === $operator['arity'] && null === $right ) {
			$issues[] = new ValidationIssue( 'condition.arity_mismatch', $path . '.right' );
		}

		$left_type = $this->resolve_operand_type( $condition->left(), $path . '.left', $consumer_order, $steps_by_id, $order_by_id, $resolved, $issues );
		$right_type = null;
		if ( null !== $right ) {
			$right_type = $this->resolve_operand_type( $right, $path . '.right', $consumer_order, $steps_by_id, $order_by_id, $resolved, $issues );
		}

		if (
			null !== $right
			&& (
				( null !== $left_type && $left_type['sensitive'] && Binding::SOURCE_LITERAL === $right->source() )
				|| ( null !== $right_type && $right_type['sensitive'] && Binding::SOURCE_LITERAL === $condition->left()->source() )
			)
		) {
			$issues[] = new ValidationIssue( 'privacy.sensitive_literal', $path );
		}

		if ( null === $left_type ) {
			return;
		}
		if ( ! in_array( $left_type['type'], $operator['left_types'], true ) ) {
			$issues[] = new ValidationIssue( 'condition.type_mismatch', $path . '.left', [ 'operator' => $condition->operator() ] );
			return;
		}
		if (
			2 === $operator['arity']
			&& null !== $right_type
			&& ! $this->condition_types_compatible(
				$condition->operator(),
				$left_type,
				$right_type,
				null !== $right && Binding::SOURCE_LITERAL === $right->source()
			)
		) {
			$issues[] = new ValidationIssue( 'condition.type_mismatch', $path, [ 'operator' => $condition->operator() ] );
		}
	}

	/**
	 * @param array<string,Step>                 $steps_by_id
	 * @param array<string,int>                  $order_by_id
	 * @param array<string,CapabilityDefinition> $resolved
	 * @param ValidationIssue[]                  $issues
	 * @return array{type:string,items:?string,sensitive:bool,semantic_type:?string}|null
	 */
	private function resolve_operand_type(
		Binding $binding,
		string $path,
		int $consumer_order,
		array $steps_by_id,
		array $order_by_id,
		array $resolved,
		array &$issues
	): ?array {
		if ( Binding::SOURCE_LITERAL === $binding->source() ) {
			return $this->literal_type( $binding->value() );
		}
		return $this->resolve_output_binding( $binding, $path, $consumer_order, $steps_by_id, $order_by_id, $resolved, $issues );
	}

	/**
	 * @param array<string,Step>                 $steps_by_id
	 * @param array<string,int>                  $order_by_id
	 * @param array<string,CapabilityDefinition> $resolved
	 * @param ValidationIssue[]                  $issues
	 * @return array{type:string,items:?string,sensitive:bool,semantic_type:?string}|null
	 */
	private function resolve_output_binding(
		Binding $binding,
		string $path,
		int $consumer_order,
		array $steps_by_id,
		array $order_by_id,
		array $resolved,
		array &$issues
	): ?array {
		$source_id = (string) $binding->step_id();
		$field     = (string) $binding->field();

		if ( ! isset( $steps_by_id[ $source_id ] ) ) {
			$issues[] = new ValidationIssue( 'binding.source_missing', $path, [ 'step_id' => $source_id ] );
			return null;
		}
		if ( ! isset( $order_by_id[ $source_id ] ) || $order_by_id[ $source_id ] >= $consumer_order ) {
			$issues[] = new ValidationIssue( 'binding.source_not_available_yet', $path, [ 'step_id' => $source_id ] );
			return null;
		}

		$source_capability = $resolved[ $source_id ] ?? null;
		if ( null === $source_capability ) {
			return null;
		}

		$output = $source_capability->output_schema();
		if ( ! isset( $output[ $field ] ) ) {
			$issues[] = new ValidationIssue( 'binding.field_missing', $path, [ 'step_id' => $source_id, 'field' => $field ] );
			return null;
		}

		return $this->schema_type( $output[ $field ] );
	}

	/** @param array<string,mixed> $schema */
	private function literal_matches_schema( mixed $value, array $schema ): bool {
		$type = (string) ( $schema['type'] ?? '' );
		return match ( $type ) {
			'string'  => is_string( $value ),
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ),
			'boolean' => is_bool( $value ),
			'array'   => $this->literal_array_matches( $value, is_string( $schema['items'] ?? null ) ? $schema['items'] : '' ),
			default   => false,
		};
	}

	private function literal_array_matches( mixed $value, string $items ): bool {
		if ( ! is_array( $value ) || ! array_is_list( $value ) || '' === $items ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! $this->scalar_matches_type( $item, $items ) ) {
				return false;
			}
		}
		return true;
	}

	private function scalar_matches_type( mixed $value, string $type ): bool {
		return match ( $type ) {
			'string'  => is_string( $value ),
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ),
			'boolean' => is_bool( $value ),
			default   => false,
		};
	}

	/**
	 * @param array{type:string,items:?string,sensitive:bool,semantic_type:?string} $source
	 * @param array<string,mixed>                                                   $target
	 */
	private function schemas_compatible( array $source, array $target ): bool {
		$target_semantic = is_string( $target['semantic_type'] ?? null ) && '' !== $target['semantic_type']
			? $target['semantic_type']
			: null;
		if ( ! $this->semantic_types_compatible( $source['semantic_type'], $target_semantic ) ) {
			return false;
		}

		$target_type = (string) ( $target['type'] ?? '' );
		if ( ! $this->scalar_type_compatible( $source['type'], $target_type ) ) {
			return false;
		}
		if ( 'array' !== $target_type ) {
			return true;
		}
		if ( 'array' !== $source['type'] ) {
			return false;
		}
		$target_items = is_string( $target['items'] ?? null ) ? $target['items'] : null;
		return null !== $source['items'] && null !== $target_items && $this->scalar_type_compatible( $source['items'], $target_items );
	}

	private function semantic_types_compatible( ?string $source, ?string $target ): bool {
		return null === $target || ( null !== $source && $source === $target );
	}

	private function scalar_type_compatible( string $source, string $target ): bool {
		return $source === $target || ( 'integer' === $source && 'number' === $target );
	}

	/** @param array<string,mixed> $schema @return array{type:string,items:?string,sensitive:bool,semantic_type:?string} */
	private function schema_type( array $schema ): array {
		return [
			'type'          => (string) ( $schema['type'] ?? '' ),
			'items'         => is_string( $schema['items'] ?? null ) ? $schema['items'] : null,
			'sensitive'     => true === ( $schema['sensitive'] ?? false ),
			'semantic_type' => is_string( $schema['semantic_type'] ?? null ) && '' !== $schema['semantic_type']
				? $schema['semantic_type']
				: null,
		];
	}

	/** @return array{type:string,items:?string,sensitive:bool,semantic_type:?string}|null */
	private function literal_type( mixed $value ): ?array {
		if ( is_string( $value ) ) {
			return [ 'type' => 'string', 'items' => null, 'sensitive' => false, 'semantic_type' => null ];
		}
		if ( is_int( $value ) ) {
			return [ 'type' => 'integer', 'items' => null, 'sensitive' => false, 'semantic_type' => null ];
		}
		if ( is_float( $value ) && is_finite( $value ) ) {
			return [ 'type' => 'number', 'items' => null, 'sensitive' => false, 'semantic_type' => null ];
		}
		if ( is_bool( $value ) ) {
			return [ 'type' => 'boolean', 'items' => null, 'sensitive' => false, 'semantic_type' => null ];
		}
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return null;
		}

		$item_type = null;
		foreach ( $value as $item ) {
			$current = $this->literal_type( $item );
			if ( null === $current || 'array' === $current['type'] ) {
				return null;
			}
			if ( null === $item_type ) {
				$item_type = $current['type'];
			} elseif ( ! $this->scalar_type_compatible( $current['type'], $item_type ) && ! $this->scalar_type_compatible( $item_type, $current['type'] ) ) {
				return [ 'type' => 'array', 'items' => 'mixed', 'sensitive' => false, 'semantic_type' => null ];
			} elseif ( 'number' === $current['type'] || 'number' === $item_type ) {
				$item_type = 'number';
			}
		}
		return [ 'type' => 'array', 'items' => $item_type, 'sensitive' => false, 'semantic_type' => null ];
	}

	/**
	 * @param array{type:string,items:?string,sensitive:bool,semantic_type:?string} $left
	 * @param array{type:string,items:?string,sensitive:bool,semantic_type:?string} $right
	 */
	private function condition_types_compatible( string $operator, array $left, array $right, bool $right_is_literal ): bool {
		if ( ! $right_is_literal && ! $this->semantic_types_compatible( $right['semantic_type'], $left['semantic_type'] ) ) {
			return false;
		}

		if ( in_array( $operator, [ 'greater_than', 'greater_than_or_equal', 'less_than', 'less_than_or_equal' ], true ) ) {
			return in_array( $right['type'], [ 'integer', 'number' ], true );
		}

		if ( in_array( $operator, [ 'contains', 'not_contains' ], true ) ) {
			if ( 'string' === $left['type'] ) {
				return 'string' === $right['type'];
			}
			if ( 'array' === $left['type'] ) {
				return 'array' !== $right['type'] && null !== $left['items'] && 'mixed' !== $left['items']
					&& $this->scalar_type_compatible( $right['type'], $left['items'] );
			}
			return false;
		}

		if ( 'array' === $left['type'] || 'array' === $right['type'] ) {
			if ( 'array' !== $left['type'] || 'array' !== $right['type'] ) {
				return false;
			}

			/* Empty literal arrays carry no item type and are compatible with a typed array. */
			if ( null === $left['items'] || null === $right['items'] ) {
				return true;
			}

			return 'mixed' !== $left['items'] && 'mixed' !== $right['items']
				&& ( $this->scalar_type_compatible( $left['items'], $right['items'] ) || $this->scalar_type_compatible( $right['items'], $left['items'] ) );
		}

		return $this->scalar_type_compatible( $left['type'], $right['type'] ) || $this->scalar_type_compatible( $right['type'], $left['type'] );
	}

	/** @param array<string,CapabilityDefinition> $resolved */
	private function contains_sensitive_paths( Definition $definition, array $resolved ): bool {
		foreach ( array_merge( $definition->states(), $definition->actions() ) as $step ) {
			$target       = $resolved[ $step->step_id() ] ?? null;
			$input_schema = null === $target ? [] : $target->input_schema();

			foreach ( $step->bindings() as $field => $binding ) {
				if ( true === ( $input_schema[ $field ]['sensitive'] ?? false ) || $this->binding_is_sensitive( $binding, $resolved ) ) {
					return true;
				}
			}
		}

		foreach ( $definition->conditions() as $condition ) {
			if ( $this->binding_is_sensitive( $condition->left(), $resolved ) ) {
				return true;
			}
			$right = $condition->right();
			if ( null !== $right && $this->binding_is_sensitive( $right, $resolved ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string,CapabilityDefinition> $resolved */
	private function binding_is_sensitive( Binding $binding, array $resolved ): bool {
		if ( Binding::SOURCE_STEP_OUTPUT !== $binding->source() ) {
			return false;
		}
		$source = $resolved[ (string) $binding->step_id() ] ?? null;
		if ( null === $source ) {
			return false;
		}
		$field  = (string) $binding->field();
		$output = $source->output_schema();
		return isset( $output[ $field ] ) && true === ( $output[ $field ]['sensitive'] ?? false );
	}
}
