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
		// Defence in depth: never return an unsafe number in the browser data,
		// even if another caller bypasses the admin page's read-only gate.
		if ( ! \CB\Automations\Workflow\EditorIntegerSafety::is_browser_safe( $record->definition() ) ) {
			throw new \UnexpectedValueException( 'Workflow is unavailable for precision-safe browser editing.' );
		}
		$catalog ??= new CapabilityCatalog();
		$capabilities = [];
		$by_identity  = [];

		foreach ( $catalog->all() as $definition ) {
			$capabilities[] = self::capability( $definition, $catalog );
			$reference      = $definition->reference();
			$by_identity[ self::identity_key( $reference->kind(), $reference->provider(), $reference->id() ) ] = $definition;
		}

		$projection = EditorDefinitionProjection::project(
			DefinitionCodec::encode( $record->definition() ),
			$by_identity
		);

		return [
			'workflow' => [
				'id'               => $record->id(),
				'name'             => $record->name(),
				'activation_state' => $record->activation_state()->value,
				'revision'         => $record->revision(),
				'definition'       => self::browser_definition( $projection['definition'] ),
			],
			'capabilities'                => $capabilities,
			'post_status_options'        => self::post_status_options(),
			'operators'                   => OperatorCatalog::definitions(),
			'validation'                  => $validation->to_array(),
			'redacted_sensitive_literals' => $projection['redacted'],
		];
	}

	/**
	 * Browser-only JSON shape: PHP's empty associative bindings map encodes as
	 * [], whereas the public Automations DesignProject expects an object.
	 *
	 * The private workflow is already projected/redacted above. Change ONLY
	 * exact empty step-binding maps to JSON objects. Never change nonempty
	 * bindings, literal array values, credentials, or persisted DefinitionCodec
	 * data. json_decode(..., true) on Save returns [] as before.
	 *
	 * @param array<string,mixed> $definition Sanitized, authorable projection.
	 * @return array<string,mixed>
	 */
	private static function browser_definition( array $definition ): array {
		if ( is_array( $definition['trigger'] ?? null )
			&& [] === ( $definition['trigger']['bindings'] ?? null ) ) {
			$definition['trigger']['bindings'] = (object) [];
		}

		foreach ( [ 'states', 'actions' ] as $collection ) {
			if ( ! is_array( $definition[ $collection ] ?? null ) ) {
				continue;
			}
			foreach ( $definition[ $collection ] as $index => $step ) {
				if ( is_array( $step ) && [] === ( $step['bindings'] ?? null ) ) {
					$definition[ $collection ][ $index ]['bindings'] = (object) [];
				}
			}
		}
		return $definition;
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
	 * @return list<array{value:string,label:string}>
	 * Supported native WordPress status options for semantic post-status
	 * literals. Custom statuses need an explicit authority contract first.
	 * The picker is a UX hint; WordPressAction independently authorizes.
	 */
	private static function post_status_options(): array {
		$statuses = get_post_stati( [ 'internal' => false ], 'objects' );
		$options = [];
		foreach ( $statuses as $name => $status ) {
			// Match the audited WordPressAction status authority policy.
			// A custom status may require provider-specific permissions.
			if ( ! in_array( $name, [ 'draft', 'pending', 'publish', 'future', 'private' ], true )
				|| ! is_object( $status ) ) {
				continue;
			}
			$label = trim( wp_strip_all_tags( (string) ( $status->label ?? $name ) ) );
			$options[] = [ 'value' => $name, 'label' => '' !== $label ? $label : $name ];
		}
		return $options;
	}

	private static function identity_key( string $kind, string $provider, string $id ): string {
		return $kind . ':' . $provider . ':' . $id;
	}
}
