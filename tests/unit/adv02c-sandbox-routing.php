<?php
declare(strict_types=1);

// C5 sandbox admission gate and worker path must remain fail-closed.
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );

define( 'CB_AUTOMATIONS_ADVANCED_SANDBOX', true );
define( 'WP_CLI', true );
$GLOBALS['c5_env'] = 'production';
$GLOBALS['c5_actor'] = 1;
function wp_get_environment_type(): string { return $GLOBALS['c5_env']; }
function get_current_user_id(): int { return $GLOBALS['c5_actor']; }
function user_can( int $user, string $cap ): bool { return $user === 1 && $cap === 'manage_options'; }
require_once dirname( __DIR__, 2 ) . '/src/Runtime/GraphRuntimeGate.php';

use CB\Automations\Runtime\GraphRuntimeGate;

function c5_gate( bool $value, string $label ): void {
	if ( ! $value ) throw new RuntimeException( $label );
}
function c5_refuse( callable $action, string $label ): void {
	try { $action(); } catch ( RuntimeException ) { return; }
	throw new RuntimeException( $label );
}
c5_gate( ! GraphRuntimeGate::enabled(), 'Advanced sandbox could run on production.' );
c5_refuse( static fn () => GraphRuntimeGate::require_cli(), 'Production admitted an Advanced CLI run.' );
$GLOBALS['c5_env'] = 'local';
c5_gate( GraphRuntimeGate::enabled(), 'Local explicit opt-in was unexpectedly unavailable.' );
GraphRuntimeGate::require_cli();
$GLOBALS['c5_actor'] = 2;
c5_refuse( static fn () => GraphRuntimeGate::require_cli(), 'Unprivileged operator admitted a run.' );
$GLOBALS['c5_actor'] = 1;

$root = dirname( __DIR__, 2 );
$worker = file_get_contents( $root . '/src/Runtime/RuntimeWorker.php' );
$pipeline = file_get_contents( $root . '/src/Runtime/GraphRunPipeline.php' );
$admission = file_get_contents( $root . '/src/Runtime/GraphSandboxAdmission.php' );
$standard = file_get_contents( $root . '/src/Runtime/RunPipeline.php' );
$workflow = file_get_contents( $root . '/src/Persistence/WorkflowRepository.php' );
if ( ! is_string( $worker ) || ! is_string( $pipeline ) || ! is_string( $admission )
	|| ! is_string( $standard ) || ! is_string( $workflow ) ) {
	throw new RuntimeException( 'C5 gate could not inspect runtime sources.' );
}
foreach ( [ 'GraphRunPipeline::is_advanced_run(', 'GraphRunPipeline::execute(', 'GraphRunPipeline::handle_failure(',
	'RunPipeline::execute( $job->subject_id(), $job )' ] as $needle ) {
	c5_gate( str_contains( $worker, $needle ), 'Runtime worker lost governed v1/v2 dispatch: ' . $needle );
}
c5_gate( ! str_contains( $standard, 'GraphRunPipeline' ), 'Standard v1 RunPipeline was modified for Advanced.' );
c5_gate( str_contains( $pipeline, "'cli_sandbox_v1'" )
	&& str_contains( $pipeline, 'GraphRuntimeGate::enabled()' )
	&& str_contains( $pipeline, 'GraphCheckpointStore::commit_branch(' )
	&& str_contains( $pipeline, 'GraphProviderExecutor::execute(' )
	&& str_contains( $pipeline, 'RunStatus::Succeeded' ), 'Advanced worker lost its admission/provenance/route gates.' );
c5_gate( strpos( $pipeline, 'StepStatus::Running->value' ) < strpos( $pipeline, 'RunContextRepository::get(' ),
	'Advanced worker opened Vault before checking an unresolved Action after failure.' );
c5_gate( str_contains( $admission, 'GraphRuntimeGate::require_cli()' )
	&& str_contains( $admission, 'GraphValidator(' )
	&& str_contains( $admission, 'GraphCheckpointJournal::empty()' )
	&& str_contains( $admission, "'graph:0'" )
	&& str_contains( $admission, 'JobRepository::enqueue( JobSubjectType::Run' )
	&& str_contains( $admission, 'event_receipt_id = %d AND workflow_id = %d')
	&& str_contains( $admission, "'START TRANSACTION'" )
	&& str_contains( $admission, "'COMMIT'" )
	&& str_contains( $admission, "'ROLLBACK'" ), 'Sandbox admission lost its idempotent atomic run job path.' );
c5_gate( str_contains( $workflow, "Advanced draft cannot be activated." )
	&& str_contains( $workflow, 'Definition::VERSION,' ), 'Ordinary workflow activation isolation changed.' );
c5_gate( ! str_contains( $worker, 'ActionInvoker::invoke(' )
	&& ! str_contains( $worker, 'StateInvoker::resolve(' ), 'Worker bypasses Base provider boundary.' );
echo "ADV-02C C5 CLI sandbox gate and worker routing invariants: PASS\n";