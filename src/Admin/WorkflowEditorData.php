<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Automations\Condition\OperatorCatalog;
use CB\Automations\Discovery\CapabilityCatalog;
use CB\Automations\Discovery\CapabilityDefinition;
use CB\Automations\Persistence\WorkflowRecord;
use CB\Automations\Validation\ValidationResult;
use CB\Automations\Workflow\DefinitionCodec;

defined( 'ABSPATH' ) || exit;

final class WorkflowEditorData {
	/** @return array<string,mixed> */
	public static function build( WorkflowRecord $record, ValidationResult $validation, ?CapabilityCatalog $catalog = null ): array {
		$catalog ??= new CapabilityCatalog();
		$capabilities = [];
		$by_identity  = [];

		foreach ( $catalog->all() as $definition ) {
			$capabilities[] = self::capability( $definition, $catalog );
			$reference      = $definition->reference();
			$by_identity[ self::identity_key( $reference->kind(), $reference->provider(), $reference->id() ) ] = $definition;
		}

		$editor_definition = DefinitionCodec::encode( $record->definition() );
		$redacted          = self::redact_sensitive_literals( $editor_definition, $by_identity );

		return [
			'workflow' => [
				'id'               => $record->id(),
				'name'             => $record->name(),
				'activation_state' => $record->activation_state()->value,
				'revision'         => $record->revision(),
				'definition'       => $editor_definition,
			],
			'capabilities'                => $capabilities,
			'operators'                   => OperatorCatalog::definitions(),
			'validation'                  => $validation->to_array(),
			'redacted_sensitive_literals' => $redacted,
		];
	}

	/** @return array<string,mixed> */
	private static function capability( CapabilityDefinition $definition, CapabilityCatalog $catalog ): array {
		$reference = $definition->reference();
		$provider  = $catalog->provider_status( $reference->provider() );

		return [
			'reference'           => $reference->to_array(),
			'label'               => $definition->label(),
			'description'         => $definition->description(),
			'input_schema'        => $definition->input_schema(),
			'output_schema'       => $definition->output_schema(),
			'required_capability' => $definition->required_capability(),
			'provider'            => [
				'id'        => $provider->id(),
				'name'      => $provider->name(),
				'available' => $provider->is_available(),
			],
		];
	}

	/**
	 * Never reflect a persisted sensitive literal back into wp-admin markup.
	 * The validator/persistence policy prevents new sensitive literals; this
	 * scrubber protects operators from legacy/manual database values as well.
	 *
	 * @param array<string,mixed>                         $definition
	 * @param array<string,CapabilityDefinition>          $capabilities
	 */
	private static function redact_sensitive_literals( array &$definition, array $capabilities ): int {
		$redacted = 0;

		foreach ( [ 'states', 'actions' ] as $collection ) {
			if ( ! isset( $definition[ $collection ] ) || ! is_array( $definition[ $collection ] ) ) {
				continue;
			}

			foreach ( $definition[ $collection ] as &$step ) {
				if ( ! is_array( $step ) || ! is_array( $step['capability'] ?? null ) || ! is_array( $step['bindings'] ?? null ) ) {
					continue;
				}

				$reference = $step['capability'];
				$key = self::identity_key(
					is_string( $reference['kind'] ?? null ) ? $reference['kind'] : '',
					is_string( $reference['provider'] ?? null ) ? $reference['provider'] : '',
					is_string( $reference['id'] ?? null ) ? $reference['id'] : ''
				);
				$capability = $capabilities[ $key ] ?? null;
				if ( null === $capability ) {
					continue;
				}

				$schema = $capability->input_schema();
				foreach ( $step['bindings'] as $field => $binding ) {
					if (
						! is_string( $field )
						|| ! is_array( $binding )
						|| 'literal' !== ( $binding['source'] ?? null )
						|| true !== ( $schema[ $field ]['sensitive'] ?? false )
					) {
						continue;
					}

					unset( $step['bindings'][ $field ] );
					++$redacted;
				}
			}
			unset( $step );
		}

		return $redacted;
	}

	private static function identity_key( string $kind, string $provider, string $id ): string {
		return $kind . ':' . $provider . ':' . $id;
	}
}
