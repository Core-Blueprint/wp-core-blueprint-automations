<?php
declare(strict_types=1);

namespace CB\Automations\Discovery;

use CB\Automations\Capability\CapabilityKind;
use CB\Automations\Capability\CapabilityReference;
use CB\Core\Automation\ActionRegistry;
use CB\Core\Automation\StateRegistry;
use CB\Core\Automation\TriggerRegistry;
use CB\Core\ExtensionRegistry;
use RuntimeException;
use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

final class CapabilityCatalog {
	public function is_ready(): bool {
		return did_action( 'init' ) > 0 && ! doing_action( 'init' );
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

	/** Return the provider/id capability currently exposed by Base, regardless of stored schema version. */
	public function current( CapabilityReference $reference ): ?CapabilityDefinition {
		$this->assert_ready();

		$definition = match ( $reference->kind() ) {
			CapabilityKind::TRIGGER => TriggerRegistry::get( $reference->provider(), $reference->id() ),
			CapabilityKind::STATE   => StateRegistry::get( $reference->provider(), $reference->id() ),
			CapabilityKind::ACTION  => ActionRegistry::get( $reference->provider(), $reference->id() ),
			default                 => null,
		};

		if ( null === $definition ) {
			return null;
		}

		$capability = CapabilityDefinition::from_base_definition( $reference->kind(), $definition );
		if ( null === $capability ) {
			throw new UnexpectedValueException( 'Base returned a malformed Automation Foundation capability definition.' );
		}
		return $capability;
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
			if ( null === $capability ) {
				throw new UnexpectedValueException( 'Base returned a malformed Automation Foundation capability definition.' );
			}
			$projected[] = $capability;
		}

		return $projected;
	}

	private function assert_ready(): void {
		if ( $this->is_ready() ) {
			return;
		}

		throw new RuntimeException( 'Automation capability discovery is unavailable until WordPress init has completed.' );
	}
}
