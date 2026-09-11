<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;

use CB\Automations\Condition\Condition;

defined( 'ABSPATH' ) || exit;

final readonly class Definition {
	public const VERSION = 1;

	/**
	 * @param Step[]      $states
	 * @param Condition[] $conditions
	 * @param Step[]      $actions
	 */
	private function __construct(
		private ?Step $trigger,
		private array $states,
		private array $conditions,
		private array $actions
	) {}

	/**
	 * @param Step[]      $states
	 * @param Condition[] $conditions
	 * @param Step[]      $actions
	 */
	public static function from_values( ?Step $trigger, array $states, array $conditions, array $actions ): ?self {
		if ( ! self::contains_only( $states, Step::class ) || ! self::contains_only( $conditions, Condition::class ) || ! self::contains_only( $actions, Step::class ) ) {
			return null;
		}

		$step_ids = [];
		foreach ( self::merge_steps( $trigger, $states, $actions ) as $step ) {
			if ( isset( $step_ids[ $step->step_id() ] ) ) {
				return null;
			}
			$step_ids[ $step->step_id() ] = true;
		}

		$condition_ids = [];
		foreach ( $conditions as $condition ) {
			if ( isset( $condition_ids[ $condition->condition_id() ] ) ) {
				return null;
			}
			$condition_ids[ $condition->condition_id() ] = true;
		}

		return new self( $trigger, array_values( $states ), array_values( $conditions ), array_values( $actions ) );
	}

	public function definition_version(): int {
		return self::VERSION;
	}

	public function trigger(): ?Step {
		return $this->trigger;
	}

	/** @return Step[] */
	public function states(): array {
		return $this->states;
	}

	/** @return Condition[] */
	public function conditions(): array {
		return $this->conditions;
	}

	/** @return Step[] */
	public function actions(): array {
		return $this->actions;
	}

	/** @return Step[] */
	public function steps(): array {
		return self::merge_steps( $this->trigger, $this->states, $this->actions );
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'definition_version' => self::VERSION,
			'trigger'            => null === $this->trigger ? null : $this->trigger->to_array(),
			'states'             => array_map( static fn ( Step $step ): array => $step->to_array(), $this->states ),
			'conditions'         => array_map( static fn ( Condition $condition ): array => $condition->to_array(), $this->conditions ),
			'actions'            => array_map( static fn ( Step $step ): array => $step->to_array(), $this->actions ),
		];
	}

	/** @return Step[] */
	private static function merge_steps( ?Step $trigger, array $states, array $actions ): array {
		$steps = [];
		if ( null !== $trigger ) {
			$steps[] = $trigger;
		}
		return array_merge( $steps, $states, $actions );
	}

	private static function contains_only( array $values, string $class ): bool {
		if ( ! array_is_list( $values ) ) {
			return false;
		}
		foreach ( $values as $value ) {
			if ( ! $value instanceof $class ) {
				return false;
			}
		}
		return true;
	}
}
