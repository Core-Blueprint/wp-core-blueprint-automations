<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Capability\CapabilityKind;
use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\DefinitionCodec;

defined( 'ABSPATH' ) || exit;

final class TriggerKey {
	public static function for_definition( Definition $definition ): string {
		$trigger = $definition->trigger();
		if ( null === $trigger || CapabilityKind::TRIGGER !== $trigger->capability()->kind() ) {
			return '';
		}

		$reference = $trigger->capability();
		return self::from_parts( $reference->provider(), $reference->id(), $reference->schema_version() );
	}

	public static function from_parts( string $provider, string $trigger_id, string $schema_version ): string {
		if (
			1 !== preg_match( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)+$/D', $provider )
			|| 1 !== preg_match( '/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9]*)+$/D', $trigger_id )
			|| 1 !== preg_match( '/^[1-9][0-9]*$/D', $schema_version )
		) {
			return '';
		}

		return hash( 'sha256', implode( "\0", [ $provider, $trigger_id, $schema_version ] ) );
	}

	public static function from_encoded_json( string $json ): string {
		try {
			$decoded = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
			if ( ! is_array( $decoded ) ) {
				return '';
			}
			return self::for_definition( DefinitionCodec::decode( $decoded ) );
		} catch ( \Throwable ) {
			return '';
		}
	}

	public static function is_valid( string $key ): bool {
		return '' === $key || 1 === preg_match( '/^[a-f0-9]{64}$/D', $key );
	}
}
