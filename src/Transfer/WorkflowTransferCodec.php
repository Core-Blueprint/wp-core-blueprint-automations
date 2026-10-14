<?php
declare(strict_types=1);

namespace CB\Automations\Transfer;

use CB\Automations\Persistence\WorkflowRecord;
use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\DefinitionCodec;
use CB\Automations\Workflow\PersistencePolicy;
use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

/** Portable workflow definition, without runtime, authority or run history. */
final class WorkflowTransferCodec {
	public const FORMAT = 'core-blueprint.automations.workflow';
	public const FORMAT_VERSION = 1;
	public const MAX_FILE_BYTES = 262144;

	/** @return array{name:string,definition:Definition} */
	public static function import_json( string $json, string $filename = 'Imported workflow.json' ): array {
		if ( '' === trim( $json ) || strlen( $json ) > self::MAX_FILE_BYTES ) {
			throw new UnexpectedValueException( 'Import must contain a bounded JSON workflow.' );
		}
		try {
			$document = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			throw new UnexpectedValueException( 'Invalid workflow JSON.' );
		}
		if ( ! is_array( $document ) || array_is_list( $document ) ) {
			throw new UnexpectedValueException( 'Invalid workflow document.' );
		}
		$name = self::fallback_name( $filename );
		if ( array_key_exists( 'format', $document ) ) {
			self::assert_exact_keys( $document, [ 'format', 'format_version', 'name', 'definition' ] );
			if ( self::FORMAT !== $document['format'] || self::FORMAT_VERSION !== $document['format_version']
				|| ! is_string( $document['name'] ) || ! is_array( $document['definition'] ) ) {
				throw new UnexpectedValueException( 'Incompatible workflow transfer format.' );
			}
			$name = trim( strip_tags( $document['name'] ) );
			$encoded = $document['definition'];
		} else {
			// Already shared standalone DefinitionCodec JSON test workflows.
			self::assert_exact_keys( $document, [ 'definition_version', 'trigger', 'states', 'conditions', 'actions' ] );
			$encoded = $document;
		}
		if ( '' === $name || strlen( $name ) > 191 ) {
			throw new UnexpectedValueException( 'Workflow name is invalid.' );
		}
		$encoded_json = json_encode( $encoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		if ( ! PersistencePolicy::allows_encoded_definition( $encoded_json ) ) {
			throw new UnexpectedValueException( 'Workflow definition is too large.' );
		}
		return [ 'name' => $name, 'definition' => DefinitionCodec::decode( $encoded ) ];
	}

	public static function export_json( WorkflowRecord $workflow ): string {
		$document = [
			'format' => self::FORMAT,
			'format_version' => self::FORMAT_VERSION,
			'name' => $workflow->name(),
			'definition' => DefinitionCodec::encode( $workflow->definition() ),
		];
		$json = json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
		if ( strlen( $json ) > self::MAX_FILE_BYTES ) {
			throw new UnexpectedValueException( 'Export exceeds maximum JSON workflow size.' );
		}
		return $json . "\n";
	}

	private static function fallback_name( string $filename ): string {
		$name = pathinfo( basename( str_replace( '\\', '/', $filename ) ), PATHINFO_FILENAME );
		$name = trim( strip_tags( preg_replace( '/[\x00-\x1f\x7f]/', '', $name ) ?? '' ) );
		return '' !== $name ? substr( $name, 0, 191 ) : 'Imported workflow';
	}

	/** @param string[] $expected */
	private static function assert_exact_keys( array $document, array $expected ): void {
		$keys = array_keys( $document );
		sort( $keys );
		sort( $expected );
		if ( $keys !== $expected ) {
			throw new UnexpectedValueException( 'Workflow transfer contains unknown or missing fields.' );
		}
	}
}
