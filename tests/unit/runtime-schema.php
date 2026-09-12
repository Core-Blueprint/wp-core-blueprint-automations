<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$schema = file_get_contents( $root . '/src/Persistence/Schema.php' );
$repository = file_get_contents( $root . '/src/Persistence/WorkflowRepository.php' );
$record = file_get_contents( $root . '/src/Persistence/WorkflowRecord.php' );
$trigger_key = file_get_contents( $root . '/src/Runtime/TriggerKey.php' );
$bootstrap = file_get_contents( $root . '/core-blueprint-automations.php' );

if ( false === $schema || false === $repository || false === $record || false === $trigger_key || false === $bootstrap ) {
	fwrite( STDERR, "Could not read AU2 runtime schema sources.\n" );
	exit( 1 );
}

$failures = [];

if ( ! str_contains( $bootstrap, "define( 'CB_AUTOMATIONS_DB_VERSION', '2' );" ) ) {
	$failures[] = 'AU2 runtime persistence must use one schema upgrade from v1 to v2.';
}

foreach ( [
	'cb_automations_workflows',
	'cb_automations_event_receipts',
	'cb_automations_runs',
	'cb_automations_run_steps',
	'cb_automations_jobs',
	'cb_automations_run_context',
	'cb_automations_run_recoveries',
] as $table_suffix ) {
	if ( ! str_contains( $schema, $table_suffix ) ) {
		$failures[] = 'Missing AU2 persistence table: ' . $table_suffix;
	}
}

foreach ( [
	'execution_principal_user_id',
	'trigger_key',
	'UNIQUE KEY event_fingerprint',
	'UNIQUE KEY event_workflow',
	'UNIQUE KEY node_attempt',
	'UNIQUE KEY subject',
	'UNIQUE KEY run_node_attempt',
	'payload_envelope',
	'context_envelope',
] as $needle ) {
	if ( ! str_contains( $schema, $needle ) ) {
		$failures[] = 'Missing AU2 persistence invariant: ' . $needle;
	}
}

if ( ! str_contains( $schema, "'2' === CB_AUTOMATIONS_DB_VERSION && '2' !== \$current" ) || ! str_contains( $schema, 'migrate_pre_v2_to_v2' ) ) {
	$failures[] = 'Schema does not fail-safe every pre-v2 dataset into the single v2 runtime schema.';
}
if ( ! str_contains( $schema, "'activation_state' => 'disabled'" ) || ! str_contains( $schema, "'execution_principal_user_id' => 0" ) ) {
	$failures[] = 'Existing workflows are not fail-safe disabled with principal 0 during migration to v2.';
}
if ( str_contains( $schema, "created_by' => execution_principal" ) || str_contains( $schema, "updated_by' => execution_principal" ) ) {
	$failures[] = 'Execution authority must never be inferred from creator/updater metadata.';
}
if ( ! str_contains( $repository, 'list_enabled_for_trigger' ) ) {
	$failures[] = 'Runtime workflow matching has no indexed repository boundary.';
}
if ( ! str_contains( $record, 'execution_principal_user_id()' ) || ! str_contains( $record, 'trigger_key()' ) ) {
	$failures[] = 'Workflow records do not expose runtime authority/index metadata.';
}
if ( ! str_contains( $trigger_key, 'hash(' ) || ! str_contains( $trigger_key, "'sha256'" ) ) {
	$failures[] = 'TriggerKey is not a canonical SHA-256 runtime index.';
}

if ( [] !== $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, $failure . PHP_EOL );
	}
	exit( 1 );
}

fwrite( STDOUT, "Runtime schema v2: PASS\n" );
