<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$scan_roots = [
	$root . '/src',
	$root . '/templates',
	$root . '/assets',
];

$forbidden = [
	'CB\\Core\\Automation\\Internal' => 'Private Base Automation Foundation internals must never be consumed.',
	'CapabilityRegistry::executor' => 'AU1 must never invoke action executors.',
	'CapabilityRegistry::state_resolver' => 'AU1 must never invoke state resolvers.',
	'cb_core_automation_trigger_emitted' => 'AU1 must not subscribe to runtime trigger emission.',
	'wp_set_current_user' => 'Workflow execution must never impersonate the configuring user.',
	'wp_schedule_event' => 'AU1 must not introduce cron execution.',
	'wp_schedule_single_event' => 'AU1 must not introduce cron execution.',
	'as_enqueue_async_action' => 'AU1 must not introduce Action Scheduler execution.',
	'as_schedule_single_action' => 'AU1 must not introduce Action Scheduler execution.',
	'CB\\Core\\Admin\\PageBase' => 'Extensions implement the public Page interface directly; PageBase is internal.',
];

$forbidden_dirs = [ 'Execution', 'Queue', 'Runner', 'Retry', 'Scheduler' ];
$failures = [];

foreach ( $forbidden_dirs as $directory ) {
	if ( is_dir( $root . '/src/' . $directory ) ) {
		$failures[] = 'Forbidden AU1 runtime directory exists: src/' . $directory;
	}
}

foreach ( $scan_roots as $scan_root ) {
	if ( ! is_dir( $scan_root ) ) {
		continue;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $scan_root, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $file ) {
		if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
			continue;
		}
		$extension = strtolower( $file->getExtension() );
		if ( ! in_array( $extension, [ 'php', 'js' ], true ) ) {
			continue;
		}

		$content = file_get_contents( $file->getPathname() );
		if ( false === $content ) {
			$failures[] = 'Could not read ' . $file->getPathname();
			continue;
		}

		foreach ( $forbidden as $needle => $message ) {
			if ( str_contains( $content, $needle ) ) {
				$failures[] = $message . ' Found in ' . str_replace( $root . '/', '', $file->getPathname() );
			}
		}
	}
}

if ( [] !== $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, $failure . PHP_EOL );
	}
	exit( 1 );
}

fwrite( STDOUT, "architecture: PASS\n" );
