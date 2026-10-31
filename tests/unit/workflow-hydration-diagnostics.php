<?php
declare(strict_types=1);

/**
 * Read-only actual repository hydration behavior. No WordPress boot or DB.
 * Do not log or render stored definition JSON or exception messages.
 */
define( 'ABSPATH', __DIR__ . '/' );

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\Automations\\';
	if ( ! str_starts_with( $class, $prefix ) ) { return; }
	$file = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( is_file( $file ) ) { require_once $file; }
} );

function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); }

use CB\Automations\Persistence\PersistenceFailure;
use CB\Automations\Persistence\WorkflowRecord;
use CB\Automations\Persistence\WorkflowRepository;
use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\DefinitionCodec;
use CB\Automations\Workflow\PersistencePolicy;

$definition = Definition::from_values( null, [], [], [] );
if ( null === $definition ) { throw new \RuntimeException( 'Canonical disabled draft missing.' ); }
$row = [
	'id' => 1,
	'name' => 'Safe draft',
	'activation_state' => 'disabled',
	'definition_version' => 1,
	'definition_json' => json_encode( DefinitionCodec::encode( $definition ), JSON_THROW_ON_ERROR ),
	'revision' => 1,
	'execution_principal_user_id' => 0,
	'trigger_key' => '',
	'created_by' => 1,
	'updated_by' => 1,
	'created_at' => '2026-10-31 12:00:00.000000',
	'updated_at' => '2026-10-31 12:00:00.000000',
];

$hydrate = new ReflectionMethod( WorkflowRepository::class, 'hydrate' );
$healthy = $hydrate->invoke( null, $row );
if ( ! $healthy instanceof WorkflowRecord || 1 !== $healthy->id() ) {
	throw new \RuntimeException( 'Canonical disabled draft could not hydrate.' );
}

$cases = [
	'ROW_METADATA_INVALID' => static fn( array $row ): array => array_replace( $row, [ 'revision' => 0 ] ),
	'ENCODED_LIMIT_EXCEEDED' => static fn( array $row ): array => array_replace( $row, [
		'definition_json' => str_repeat( 'x', PersistencePolicy::MAX_DEFINITION_BYTES + 1 ),
	] ),
	'JSON_MALFORMED' => static fn( array $row ): array => array_replace( $row, [ 'definition_json' => '{broken' ] ),
	'CODEC_REJECTED' => static fn( array $row ): array => array_replace( $row, [
		'definition_json' => '{"definition_version":1,"trigger":null}',
	] ),
	'TRIGGER_KEY_MISMATCH' => static fn( array $row ): array => array_replace( $row, [ 'trigger_key' => str_repeat( 'a', 64 ) ] ),
];
foreach ( $cases as $reason => $mutation ) {
	try {
		$hydrate->invoke( null, $mutation( $row ) );
		throw new \RuntimeException( 'Invalid workflow was admitted: ' . $reason );
	} catch ( PersistenceFailure $error ) {
		if ( ! $error->is_definition_failure() || $reason !== $error->definition_reason() ) {
			throw new \RuntimeException( 'Incorrect safe hydration reason: ' . $reason );
		}
	}
}
$generic = PersistenceFailure::definition();
if ( ! $generic->is_definition_failure() || '' !== $generic->definition_reason() ) {
	throw new \RuntimeException( 'Generic definition diagnostic compatibility changed.' );
}
$schema = PersistenceFailure::schema();
if ( $schema->is_definition_failure() || '' !== $schema->definition_reason() ) {
	throw new \RuntimeException( 'Non-definition persistence failure misclassified.' );
}
echo "Automations hydration diagnostic contract: PASS (valid draft + 5 exact fail-closed classifications)\n";
