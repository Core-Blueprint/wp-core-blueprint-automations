<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;

use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

/** Small explicit v1/v2 document dispatch; Standard DefinitionCodec stays v1-only. */
final class DefinitionDocumentCodec {
	public static function decode( array $data ): Definition|GraphDefinition {
		return match ( $data['definition_version'] ?? null ) {
			Definition::VERSION => DefinitionCodec::decode( $data ),
			GraphDefinition::VERSION => GraphDefinitionCodec::decode( $data ),
			default => throw new UnexpectedValueException( 'Unsupported workflow definition version.' ),
		};
	}

	/** @return array<string,mixed> */
	public static function encode( Definition|GraphDefinition $definition ): array {
		if ( $definition instanceof Definition ) return DefinitionCodec::encode( $definition );
		// Revalidate even when the instance was constructed by a caller rather than the codec.
		GraphDefinitionCodec::decode( $definition->to_array() );
		return GraphDefinitionCodec::encode( $definition );
	}
}
