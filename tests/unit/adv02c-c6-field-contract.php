<?php
declare(strict_types=1);

// C6 source audit: the real InnoDB probe must remain a restricted, isolated
// non-provider operation and the new recovery check must precede Vault.
$root = dirname( __DIR__, 2 );
$probe = file_get_contents( $root . '/tools/adv02c-c6-field-audit.php' );
$runtime = file_get_contents( $root . '/src/Runtime/GraphRunPipeline.php' );
$fence = file_get_contents( $root . '/src/Runtime/GraphActionRecoveryFence.php' );
$standard = file_get_contents( $root . '/src/Runtime/RunPipeline.php' );
if ( ! is_string( $probe ) || ! is_string( $runtime ) || ! is_string( $fence ) || ! is_string( $standard ) ) {
	throw new RuntimeException( 'C6 sources are missing.' );
}
foreach ( [
	'GraphRuntimeGate::require_cli()',
	'WP_CLI::success(',
	'Schema::ready()',
	"'START TRANSACTION'",
	"'ROLLBACK'",
	"'running' === \$read_status( \$steps, \$step_id )",
	'GraphActionRecoveryFence::before_vault(',
	'RunExecutionLease::from_job(',
	'finally {',
	"\$wpdb->delete( \$table, [ 'id' => \$id ], [ '%d' ] )",
] as $required ) {
	if ( ! str_contains( $probe, $required ) ) {
		throw new RuntimeException( 'C6 FIELD probe lost safety invariant: ' . $required );
	}
}
foreach ( [
	'ActionInvoker::invoke(', 'StateInvoker::resolve(',
	'GraphProviderExecutor::execute(',
	'RuntimeWorker::run(',
	'JobRepository::enqueue(',
	'wp_schedule_single_event',
	'Schema::workflows_table(',
] as $forbidden ) {
	if ( str_contains( $probe, $forbidden ) ) {
		throw new RuntimeException( 'C6 FIELD probe crossed provider/production boundary: ' . $forbidden );
	}
}
$preflight = strpos( $runtime, 'GraphActionRecoveryFence::before_vault(' );
$projection = strpos( $runtime, 'self::projection( $run_id, $lease, $catalog )' );
$provider = strpos( $runtime, 'GraphProviderExecutor::execute(' );
if ( false === $preflight || false === $projection || false === $provider
	|| $preflight >= $projection || $projection >= $provider
	|| str_contains( $standard, 'GraphActionRecoveryFence' ) ) {
	throw new RuntimeException( 'C6 recovery is not before Vault and isolated from Standard.' );
}
if ( str_contains( $fence, 'RunContextRepository::get(' )
	|| str_contains( $fence, 'ActionInvoker::invoke(' )
	|| ! str_contains( $fence, 'RunExecutionLease $lease' )
	|| ! str_contains( $fence, 'RunStepRepository::indeterminate(' )
	|| ! str_contains( $fence, 'RunStatus::Indeterminate' )
	|| ! str_contains( $fence, 'FOR UPDATE' ) ) {
	throw new RuntimeException( 'C6 recovery lost lease/Vault/no-replay boundaries.' );
}
echo "ADV-02C C6 InnoDB FIELD fixture isolation and pre-Vault ordering: PASS\n";