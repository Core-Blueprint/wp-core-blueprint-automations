<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\Automations\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$file = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );

use CB\Automations\Persistence\JobRepository;

$method = new ReflectionMethod( JobRepository::class, 'normalize_datetime' );

foreach ( [
	'2026-09-12 20:14:31' => '2026-09-12 20:14:31',
	'2026-09-12 20:14:31.000000' => '2026-09-12 20:14:31',
	'2026-09-12 20:14:31.123456' => '2026-09-12 20:14:31',
] as $input => $expected ) {
	$actual = $method->invoke( null, $input );
	if ( $actual !== $expected ) {
		throw new RuntimeException( sprintf( 'Unexpected normalized timestamp for %s: %s', $input, (string) $actual ) );
	}
}

foreach ( [
	'2026-09-12 20:14:31.12345',
	'2026-09-12T20:14:31Z',
	'2026-02-30 20:14:31.000000',
] as $invalid ) {
	try {
		$method->invoke( null, $invalid );
		throw new RuntimeException( 'Invalid timestamp was accepted: ' . $invalid );
	} catch ( ReflectionException $e ) {
		throw $e;
	} catch ( InvalidArgumentException ) {
		/* Expected. */
	}
}

fwrite( STDOUT, "Job datetime contract: PASS\n" );
