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
				'definition'       => $projection['definition'],
			],
			'capabilities'                => $capabilities,
			'post_status_options'        => self::post_status_options(),
			'operators'                   => OperatorCatalog::definitions(),
			'validation'                  => $validation->to_array(),
			'redacted_sensitive_literals' => $projection['redacted'],
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
	 * @return list<array{value:string,label:string}>
	 * Registered non-internal WordPress status options for semantic post-status
	 * literal inputs. This remains a UX hint, not an execution authorization.
	 */
	private static function post_status_options(): array {
		$statuses = get_post_stati( [ 'internal' => false ], 'objects' );
		$options = [];
		foreach ( $statuses as $name => $status ) {
			if ( ! is_string( $name ) || ! preg_match( '/^[a-z][a-z0-9_-]{0,31}$/D', $name )
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
