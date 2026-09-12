<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$schema = file_get_contents( $root . '/src/Persistence/Schema.php' );
$runs = file_get_contents( $root . '/src/Persistence/RunRepository.php' );
$context = file_get_contents( $root . '/src/Persistence/RunContextRepository.php' );
$vault = file_get_contents( $root . '/src/Runtime/Vault.php' );
$worker = file_get_contents( $root . '/src/Runtime/RuntimeWorker.php' );
$pipeline = file_get_contents( $root . '/src/Runtime/RunPipeline.php' );
$state = file_get_contents( $root . '/src/Runtime/StatePhaseRunner.php' );
$action = file_get_contents( $root . '/src/Runtime/ActionPhaseRunner.php' );
$lease = file_get_contents( $root . '/src/Persistence/RunLeaseRepository.php' );
$architecture = file_get_contents( $root . '/tests/unit/architecture.php' );
if ( in_array( false, [ $schema, $runs, $context, $vault, $worker, $pipeline, $state, $action, $lease, $architecture ], true ) ) {
	throw new RuntimeException( 'Could not read G2 adversarial sources.' );
}

foreach ( [
	'UNIQUE KEY event_fingerprint (event_fingerprint)',
	'UNIQUE KEY event_workflow (event_receipt_id, workflow_id)',
	'UNIQUE KEY subject (subject_type, subject_id)',
] as $invariant ) {
	if ( ! str_contains( $schema, $invariant ) ) { throw new RuntimeException( 'Concurrency invariant disappeared: ' . $invariant ); }
}
if ( ! str_contains( $runs, 'definition_hash' ) || ! str_contains( $runs, 'hash_equals(' ) || ! str_contains( $runs, 'DefinitionCodec::decode' ) ) {
	throw new RuntimeException( 'Immutable run snapshot is no longer hash-verified before execution.' );
}
if ( str_contains( $pipeline, 'WorkflowRepository' ) ) {
	throw new RuntimeException( 'Runtime rereads mutable workflow configuration instead of its immutable snapshot.' );
}
foreach ( [ "'vault_version'", "'cipher'", "'nonce'", "'ciphertext'", 'sodium_crypto_aead_xchacha20poly1305_ietf_decrypt' ] as $needle ) {
	if ( ! str_contains( $vault, $needle ) ) { throw new RuntimeException( 'Versioned authenticated Vault invariant disappeared: ' . $needle ); }
}
if ( ! str_contains( $context, "return 'run-context:' . \$run_id;" ) ) {
	throw new RuntimeException( 'Run context ciphertext is no longer AAD-bound to its run identity.' );
}
if ( ! str_contains( $worker, 'catch ( VaultFailure )' ) || ! str_contains( $worker, "'runtime.context_unavailable'" ) ) {
	throw new RuntimeException( 'Vault corruption is not handled fail-closed with a safe machine code.' );
}
if ( ! str_contains( $worker, 'running_action_attempt(' ) || ! str_contains( $worker, 'RunStatus::Indeterminate' ) || ! str_contains( $worker, "'runtime.action_outcome_unknown'" ) ) {
	throw new RuntimeException( 'Vault loss can hide an already-started Action outcome.' );
}
$lingering = strpos( $action, 'RunStepRepository::find_running_attempt(' );
$context_open = strpos( $action, 'RunContextRepository::get(' );
if ( false === $lingering || false === $context_open || $lingering >= $context_open ) {
	throw new RuntimeException( 'Action recovery opens Vault before checking an unknown prior mutation outcome.' );
}
if ( ! str_contains( $lease, 'FOR UPDATE' ) || ! str_contains( $lease, 'lease_expires_at > %s' ) ) {
	throw new RuntimeException( 'Stale workers are not fenced from runtime acknowledgements.' );
}
foreach ( [ $state, $action ] as $runner ) {
	if ( ! str_contains( $runner, 'execution_principal_user_id()' ) || ! str_contains( $runner, 'new InvocationContext(' ) ) {
		throw new RuntimeException( 'Execution no longer propagates the immutable stored principal to Base.' );
	}
}
if ( ! str_contains( $architecture, "'wp_set_current_user'" ) ) {
	throw new RuntimeException( 'Runtime impersonation guard disappeared.' );
}

$hits = [];
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	if ( ! $file instanceof SplFileInfo || ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) { continue; }
	$source = file_get_contents( $file->getPathname() );
	if ( false !== $source && str_contains( $source, 'ActionInvoker::invoke(' ) ) { $hits[] = str_replace( $root . '/', '', $file->getPathname() ); }
}
if ( [ 'src/Runtime/ActionPhaseRunner.php' ] !== $hits ) {
	throw new RuntimeException( 'Mutating Action has more than one execution route: ' . implode( ', ', $hits ) );
}

fwrite( STDOUT, "G2 adversarial runtime matrix: PASS\n" );
