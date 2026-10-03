<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$self = realpath( __FILE__ );
$fail = [];

$legacy = [
	'cb_core_booted',
	'cb_core_register_extensions',
	'cb_core_register_settings',
	'cb_core_register_interoperability_implementations',
	'cb_core_module_status_definitions',
	'cb_core_dashboard_register_cards',
	'cb_core_capability_catalog',
	'cb_core_register_pages',
	'cb_hud_register_items',
	'cb_core_register_automation_capabilities',
	'cb_core_automation_trigger_emitted',
	"CB\\Core\\",
	"CB\\\\Core\\\\",
];

$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS )
);

foreach ( $iterator as $file ) {
	if ( ! $file->isFile() ) {
		continue;
	}

	$pathname = $file->getPathname();
	if ( false !== $self && realpath( $pathname ) === $self ) {
		continue;
	}

	$relative = ltrim( str_replace( '\\', '/', substr( $pathname, strlen( $root ) ) ), '/' );
	$in_scope = 'core-blueprint-automations.php' === $relative
		|| str_starts_with( $relative, 'src/' )
		|| str_starts_with( $relative, 'tests/' )
		|| str_starts_with( $relative, 'tools/' );

	if ( ! $in_scope ) {
		continue;
	}

	$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
	if ( '' !== $extension && ! in_array( $extension, [ 'php', 'sh', 'js', 'mjs', 'json', 'txt', 'md' ], true ) ) {
		continue;
	}

	$content = (string) file_get_contents( $pathname );
	foreach ( $legacy as $needle ) {
		if ( str_contains( $content, $needle ) ) {
			$fail[] = "{$relative}: {$needle}";
		}
	}
}

if ( [] !== $fail ) {
	foreach ( array_values( array_unique( $fail ) ) as $message ) {
		fwrite( STDERR, "Automations Base v1 contract regression failed: legacy contract found: {$message}\n" );
	}
	exit( 1 );
}

fwrite( STDOUT, "Automations Base v1 contract regression PASS\n" );
