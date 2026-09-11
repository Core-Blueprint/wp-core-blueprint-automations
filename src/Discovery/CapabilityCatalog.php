<?php
declare(strict_types=1);

namespace CB\Automations\Discovery;

use CB\Core\Automation\ActionRegistry;
use CB\Core\Automation\StateRegistry;
use CB\Core\Automation\TriggerRegistry;
use CB\Core\ExtensionRegistry;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class CapabilityCatalog {
	public function is_ready(): bool {
		return did_action( 'init' ) > 0;
	}

	/** @return CapabilityDefinition[] */
	public function triggers(): array {
		$this->assert_ready();
		return $this->project( CapabilityKind::TRIGGER, TriggerRegistry::all() );
	}

	/** @return CapabilityDefinition[] */
	public function actions(): array {
		$this->assert_ready();
		return $this->project( CapabilityKind::ACTION, ActionRegistry::all() );
	}

	/** @return CapabilityDefinition[] */
	public function states(): array {
		$this->assert_ready();
		return $this->project( CapabilityKind::STATE, StateRegistry::all() );
	}

	/** @return CapabilityDefinition[] */
	public function all(): array {
		$definitions = array_merge( $this->triggers(), $this->states(), $this->actions() );

		usort(
			$definitions,
			static fn ( CapabilityDefinition $left, CapabilityDefinition $right ): int =>
				strcmp( $left->reference()->key(), $right->reference()->key() )
		);

		return $definitions;
	}

	public function provider_status( string $provider ): ProviderStatus {
		$this->assert_ready();

		if ( 'core-blueprint' === $provider ) {
			return ProviderStatus::base();
		}

		return ProviderStatus::from_inventory( $provider, ExtensionRegistry::get( $provider ) );
	}

	/**
	 * @param array<string,array<string,mixed>> $definitions
	 * @return CapabilityDefinition[]
	 */
	private function project( string $kind, array $definitions ): array {
		$projected = [];

		foreach ( $definitions as $definition ) {
			$capability = CapabilityDefinition::from_base_definition( $kind, $definition );
			if ( null !== $capability ) {
				$projected[] = $capability;
			}
		}

		return $projected;
	}

	private function assert_ready(): void {
		if ( $this->is_ready() ) {
			return;
		}

		throw new RuntimeException( 'Automation capability discovery is unavailable before WordPress init completes.' );
	}
}
