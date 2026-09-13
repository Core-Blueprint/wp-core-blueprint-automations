<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$files = [
	'intake' => $root . '/src/Runtime/TriggerIntake.php',
	'wakeup' => $root . '/src/Runtime/WorkerWakeup.php',
	'worker' => $root . '/src/Runtime/RuntimeWorker.php',
	'materializer' => $root . '/src/Runtime/EventMaterializer.php',
	'receipts' => $root . '/src/Persistence/EventReceiptRepository.php',
	'runs' => $root . '/src/Persistence/RunRepository.php',
	'jobs' => $root . '/src/Persistence/JobRepository.php',
	'workflows' => $root . '/src/Persistence/WorkflowRepository.php',
	'trigger_key' => $root . '/src/Runtime/TriggerKey.php',
	'plugin' => $root . '/src/Plugin.php',
];

$sources = [];
foreach ( $files as $name => $path ) {
	$content = file_get_contents( $path );
	if ( false === $content ) {
		fwrite( STDERR, "Could not read AU2.0F source: {$name}.\n" );
		exit( 1 );
	}
	$sources[ $name ] = $content;
}

$failures = [];

if ( ! str_contains( $sources['intake'], "cb_core_automation_trigger_emitted" ) ) {
	$failures[] = 'Runtime intake is not subscribed to Base trigger delivery.';
}
if ( str_contains( $sources['intake'], 'START TRANSACTION' ) || str_contains( $sources['intake'], 'COMMIT' ) ) {
	$failures[] = 'Provider-request intake must not own a database transaction.';
}
if ( ! str_contains( $sources['receipts'], 'Vault::seal' ) || ! str_contains( $sources['receipts'], 'ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)' ) ) {
	$failures[] = 'Event receipt persistence is not encrypted and idempotent.';
}
if ( ! str_contains( $sources['receipts'], "hash( 'sha256'" ) ) {
	$failures[] = 'Event receipt dedupe fingerprint is not SHA-256 based.';
}
if ( ! str_contains( $sources['jobs'], 'public static function claim(' ) ) {
	$failures[] = 'Queue cannot claim durable event/run jobs through the canonical worker boundary.';
}
if (
	! str_contains( $sources['worker'], 'JobRepository::claim(' )
	|| ! str_contains( $sources['worker'], 'JobSubjectType::Event === $job->subject_type()' )
	|| ! str_contains( $sources['worker'], 'JobSubjectType::Run === $job->subject_type()' )
	|| ! str_contains( $sources['worker'], 'RunPipeline::execute(' )
) {
	$failures[] = 'Unified AU2 worker does not route durable Event and Run jobs through their canonical paths.';
}
if ( ! str_contains( $sources['materializer'], 'START TRANSACTION' ) || ! str_contains( $sources['materializer'], 'RunRepository::create_snapshot' ) ) {
	$failures[] = 'Background fan-out is not transactionally materializing immutable runs.';
}
if ( ! str_contains( $sources['materializer'], 'RunContextRepository::put' ) || ! str_contains( $sources['materializer'], 'JobSubjectType::Run' ) ) {
	$failures[] = 'Materialized runs do not receive encrypted context and durable run jobs.';
}
if ( ! str_contains( $sources['materializer'], 'matches_receipt' ) ) {
	$failures[] = 'Trigger hash matches are not verified against the real workflow reference.';
}
if ( ! str_contains( $sources['workflows'], 'LIMIT %d OFFSET %d' ) ) {
	$failures[] = 'Runtime workflow fan-out is not paginated.';
}
if ( ! str_contains( $sources['trigger_key'], 'from_parts' ) ) {
	$failures[] = 'Runtime trigger keys cannot be derived directly from TriggerEvent identity.';
}
if ( ! str_contains( $sources['wakeup'], 'wp_schedule_single_event' ) || str_contains( $sources['wakeup'], 'wp_schedule_event' ) ) {
	$failures[] = 'Runtime wake-up must use one-shot WP-Cron only.';
}
if ( ! str_contains( $sources['plugin'], 'RuntimeWorker::init();' ) || ! str_contains( $sources['plugin'], 'TriggerIntake::init();' ) ) {
	$failures[] = 'Request-neutral runtime does not boot intake and worker boundaries.';
}

foreach ( [ $sources['intake'], $sources['worker'], $sources['materializer'] ] as $source ) {
	if ( str_contains( $source, 'ActionInvoker::invoke' ) || str_contains( $source, 'StateInvoker::resolve' ) ) {
		$failures[] = 'Intake/worker/materializer must not bypass the canonical execution pipeline with direct provider invocation.';
		break;
	}
}

$single_event_occurrences = 0;
$iterator = new RecursiveIteratorIterator(
	new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS )
);
foreach ( $iterator as $file ) {
	if ( ! $file instanceof SplFileInfo || ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$content = file_get_contents( $file->getPathname() );
	if ( false === $content ) {
		continue;
	}
	$count = substr_count( $content, 'wp_schedule_single_event' );
	if ( $count > 0 && ! str_ends_with( $file->getPathname(), '/src/Runtime/WorkerWakeup.php' ) ) {
		$failures[] = 'One-shot cron scheduling escaped the WorkerWakeup boundary.';
	}
	$single_event_occurrences += $count;
}
if ( 1 !== $single_event_occurrences ) {
	$failures[] = 'Expected exactly one runtime one-shot scheduling call.';
}

if ( [] !== $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, $failure . PHP_EOL );
	}
	exit( 1 );
}

fwrite( STDOUT, "Trigger intake and fan-out boundary: PASS\n" );
