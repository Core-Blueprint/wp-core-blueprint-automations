<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
define( 'CB_AUTOMATIONS_REQUIRED_API', '1.0' );
define( 'CB_CORE_API_VERSION', '1.0' );

$base_autoload_attempts = 0;
spl_autoload_register(
	static function ( string $class ) use ( &$base_autoload_attempts ): void {
		if ( str_starts_with( $class, 'CB\\Core\\' ) ) {
			++$base_autoload_attempts;
		}
	}
);

require_once dirname( __DIR__, 2 ) . '/src/Support/Requirements.php';

use CB\Automations\Support\Requirements;

if ( ! Requirements::bootstrap_ready() ) {
	fwrite( STDERR, "Bootstrap-safe requirements unexpectedly failed.\n" );
	exit( 1 );
}

if ( 0 !== $base_autoload_attempts ) {
	fwrite( STDERR, "Bootstrap-safe requirements autoloaded Base runtime contracts.\n" );
	exit( 1 );
}

if ( ! Requirements::api_compatible( '1.2', '1.0' ) ) {
	fwrite( STDERR, "Compatible Core API minor version was rejected.\n" );
	exit( 1 );
}

if ( Requirements::api_compatible( '2.0', '1.0' ) ) {
	fwrite( STDERR, "Incompatible Core API major version was accepted.\n" );
	exit( 1 );
}

echo "Bootstrap requirements: PASS\n";
