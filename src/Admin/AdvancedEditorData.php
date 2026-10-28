<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Automations\Discovery\CapabilityCatalog;
use CB\Automations\Discovery\CapabilityDefinition;
use CB\Automations\Persistence\WorkflowRecord;
use CB\Automations\Workflow\GraphDefinition;
use CB\Automations\Workflow\GraphDefinitionCodec;

// The v2 authoring projection is separate from the Standard B1 Domain Session.
defined( 'ABSPATH' ) || exit;

final class AdvancedEditorData {
	/** @return array<string,mixed> */
	public static function build( WorkflowRecord $record, ?CapabilityCatalog $catalog = null ): array {
		$definition = $record->definition();
		if ( ! $definition instanceof GraphDefinition ) {
			throw new \UnexpectedValueException( 'Only a v2 draft can open Advanced Editor.' );
		}
		if ( ! \CB\Automations\Workflow\EditorIntegerSafety::is_browser_safe( $definition ) ) {
			throw new \UnexpectedValueException( 'Unsafe numeric data in Advanced draft.' );
		}
		$catalog ??= new CapabilityCatalog();
		$available = [];
		$capabilities = [];
		foreach ( $catalog->all() as $capability ) {
			$ref = $capability->reference();
			$key = $ref->key();
			$available[ $key ] = $capability;
			$capabilities[] = [
				'reference' => $ref->to_array(),
				'label' => $capability->label(),
				'description' => $capability->description(),
				'input_schema' => $capability->input_schema(),
				'output_schema' => $capability->output_schema(),
				'available' => $catalog->provider_status( $ref->provider() )->is_available(),
			];
		}
		// Do not leak a formerly non-sensitive stored literal when its provider's
		// schema changes or vanishes. Freeze such a draft until it is repaired.
		foreach ( $definition->graph()['nodes'] as $node ) {
			$config = $node['config'];
			if ( ! in_array( $node['type'], [ 'trigger', 'state', 'action' ], true ) || [] === $config ) {
				continue;
			}
			$ref = $config['capability'];
			// Compare exact references, not a stale textual provider alias.
			$cap = null;
			foreach ( $available as $candidate ) {
				if ( $candidate instanceof CapabilityDefinition && ( $candidate->reference()->kind() === $ref['kind'] && $candidate->reference()->provider() === $ref['provider'] && $candidate->reference()->id() === $ref['id'] && $candidate->reference()->schema_version() === $ref['schema_version'] ) ) {
					$cap = $candidate;
					break;
				}
			}
			foreach ( $config['bindings'] as $field => $binding ) {
				if ( 'literal' !== ( $binding['source'] ?? null ) ) {
					continue;
				}
				$schema = $cap instanceof CapabilityDefinition ? ( $cap->input_schema()[ $field ] ?? null ) : null;
				if ( ! is_array( $schema ) || ! empty( $schema['sensitive'] ) ) {
					throw new \UnexpectedValueException( 'Advanced literal cannot be shown after capability schema change.' );
				}
			}
		}
		return [
			'definition' => GraphDefinitionCodec::encode( $definition ),
			'capabilities' => $capabilities,
		];
	}
}