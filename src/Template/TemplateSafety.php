<?php
declare(strict_types=1);
namespace CB\Automations\Template;

use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\DefinitionCodec;
use CB\Automations\Workflow\PersistencePolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Data-only transfer boundary. Never copy literal recipient, token, password,
 * subject, private data, execution authority or a live execution context.
 * Saved templates intentionally require review/re-binding before enabling.
 */
final class TemplateSafety {
	public static function scrub( Definition $definition ): Definition {
		$data = DefinitionCodec::encode( $definition );
		foreach ( [ 'trigger', 'states', 'actions' ] as $stage ) {
			$steps = 'trigger' === $stage ? [ $data['trigger'] ] : $data[ $stage ];
			$clean = [];
			foreach ( $steps as $step ) {
				if ( ! is_array( $step ) ) {
					continue;
				}
				foreach ( $step['bindings'] as $field => $binding ) {
					// Even non-sensitive literal values could contain customer data.
					// Removing the entire binding avoids false assumptions about type.
					if ( ! is_array( $binding ) || ( $binding['source'] ?? '' ) !== 'step_output' ) {
						unset( $step['bindings'][ $field ] );
					}
				}
				$clean[] = $step;
			}
			$data[ $stage ] = 'trigger' === $stage ? ( $clean[0] ?? null ) : $clean;
		}
		$conditions = [];
		foreach ( $data['conditions'] as $condition ) {
			// Conditions with literal operands can embed private/customer values.
			// They are omitted, never serialized into transferable templates.
			if ( ! is_array( $condition )
				|| ( $condition['left']['source'] ?? '' ) !== 'step_output'
				|| ( null !== $condition['right'] && ( $condition['right']['source'] ?? '' ) !== 'step_output' ) ) {
				continue;
			}
			$conditions[] = $condition;
		}
		$data['conditions'] = $conditions;
		$json = wp_json_encode( $data );
		if ( ! is_string( $json ) || ! PersistencePolicy::allows_encoded_definition( $json ) ) {
			throw new \DomainException( 'Template exceeds safe data limits.' );
		}
		return DefinitionCodec::decode( $data );
	}

	private function __construct() {}
}
