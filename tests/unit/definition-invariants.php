<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\Automations\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$file     = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );

use CB\Automations\Capability\CapabilityReference;
use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\DefinitionCodec;
use CB\Automations\Workflow\Step;

function fail_definition_invariant( string $message ): never {
	fwrite( STDERR, $message . PHP_EOL );
	exit( 1 );
}

try {
	$draft = Definition::from_values( null, [], [], [] );
	if ( ! $draft instanceof Definition ) {
		fail_definition_invariant( 'Empty workflow draft was rejected.' );
	}

	$encoded = DefinitionCodec::encode( $draft );
	if (
		1 !== ( $encoded['definition_version'] ?? null )
		|| null !== ( $encoded['trigger'] ?? 'missing' )
		|| [] !== ( $encoded['states'] ?? null )
		|| [] !== ( $encoded['conditions'] ?? null )
		|| [] !== ( $encoded['actions'] ?? null )
	) {
		fail_definition_invariant( 'Empty workflow draft did not encode canonically.' );
	}

	$reference = CapabilityReference::from_values( 'state', 'core-blueprint-test', 'state.lookup', '1' );
	if ( null === $reference ) {
		fail_definition_invariant( 'Definition invariant fixture reference is malformed.' );
	}
	$step = Step::from_values( 'state_1', $reference, [] );
	if ( null === $step ) {
		fail_definition_invariant( 'Definition invariant fixture step is malformed.' );
	}

	$malformed = Definition::from_values( null, [ $step, new stdClass() ], [], [] );
	if ( null !== $malformed ) {
		fail_definition_invariant( 'Workflow definition accepted a malformed later list member.' );
	}
} catch ( Throwable $error ) {
	fail_definition_invariant( 'Workflow definition invariant threw unexpectedly: ' . $error::class . ': ' . $error->getMessage() );
}

fwrite( STDOUT, "definition-invariants: PASS\n" );
