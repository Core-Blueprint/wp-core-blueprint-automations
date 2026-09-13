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

use CB\Automations\Binding\Binding;
use CB\Automations\Runtime\BindingResolver;
use CB\Automations\Runtime\RunCursor;
use CB\Automations\Runtime\StepStatus;

$context = [
	'outputs' => [
		'trigger_1' => [ 'user_id' => 13, 'course_id' => 42 ],
		'state_1' => [ 'profile_id' => 99 ],
	],
];
$literal = Binding::literal( 'certificate' );
$bound = Binding::step_output( 'trigger_1', 'user_id' );
if ( null === $literal || null === $bound ) {
	throw new RuntimeException( 'Could not build binding fixtures.' );
}
if ( 'certificate' !== BindingResolver::resolve( $literal, $context ) ) {
	throw new RuntimeException( 'Literal binding changed during runtime resolution.' );
}
if ( 13 !== BindingResolver::resolve( $bound, $context ) ) {
	throw new RuntimeException( 'Step-output binding did not resolve from encrypted runtime context.' );
}
try {
	$missing = Binding::step_output( 'state_9', 'missing' );
	if ( null === $missing ) {
		throw new RuntimeException( 'Could not build missing-output fixture.' );
	}
	BindingResolver::resolve( $missing, $context );
	throw new RuntimeException( 'Missing runtime output was accepted.' );
} catch ( UnexpectedValueException ) {
	/* expected */
}

if ( 0 !== RunCursor::state_index( '', 2 ) ) {
	throw new RuntimeException( 'Initial run cursor must enter the first state.' );
}
if ( 1 !== RunCursor::state_index( 'states:1', 2 ) ) {
	throw new RuntimeException( 'State cursor did not resume at the stored index.' );
}
if ( RunCursor::CONDITIONS !== RunCursor::after_state( 2, 2 ) ) {
	throw new RuntimeException( 'Final state must advance to the Conditions phase.' );
}
if ( null !== RunCursor::state_index( RunCursor::CONDITIONS, 2 ) ) {
	throw new RuntimeException( 'Completed State phase must not replay after Conditions begins.' );
}
if ( ! StepStatus::Interrupted->is_terminal() ) {
	throw new RuntimeException( 'Interrupted state attempt is not first-class observable history.' );
}

$root = dirname( __DIR__, 2 );
$runner = file_get_contents( $root . '/src/Runtime/StatePhaseRunner.php' );
$steps = file_get_contents( $root . '/src/Persistence/RunStepRepository.php' );
$runs = file_get_contents( $root . '/src/Persistence/RunRepository.php' );
$worker = file_get_contents( $root . '/src/Runtime/RuntimeWorker.php' );
$architecture = file_get_contents( $root . '/tests/unit/architecture.php' );
if ( false === $runner || false === $steps || false === $runs || false === $worker || false === $architecture ) {
	throw new RuntimeException( 'Could not read AU2 State runtime sources.' );
}

foreach ( [
	'RunExecutionLease $lease',
	'$lease->renew(',
	'$lease->transaction(',
	'StateInvoker::resolve(',
	'new InvocationContext(',
	"'automations'",
	'RunContextRepository::put(',
	'RunStepRepository::succeed(',
	'RunRepository::advance_cursor(',
] as $needle ) {
	if ( ! str_contains( $runner, $needle ) ) {
		throw new RuntimeException( 'State execution lost governed runtime invariant: ' . $needle );
	}
}
if ( ! str_contains( $steps, 'StepStatus::Interrupted->value' ) || ! str_contains( $steps, "'runtime.worker_interrupted'" ) ) {
	throw new RuntimeException( 'Replay-safe State crashes are not closed as interrupted history.' );
}
if ( ! str_contains( $runs, "hash( 'sha256', \$definition_json )" ) || ! str_contains( $runs, 'DefinitionCodec::decode' ) ) {
	throw new RuntimeException( 'Immutable run definition hash/codec is not verified before execution.' );
}
$persist_pos = strpos( $runner, 'private static function persist_success' );
if ( false === $persist_pos ) {
	throw new RuntimeException( 'State acknowledgement boundary is missing.' );
}
$persist_source = substr( $runner, $persist_pos );
if ( str_contains( $persist_source, 'StateInvoker::resolve' ) ) {
	throw new RuntimeException( 'Provider State execution occurs inside the lease-fenced acknowledgement transaction.' );
}
if ( str_contains( $runner, 'ActionInvoker::invoke' ) || str_contains( $worker, 'StateInvoker::resolve' ) || str_contains( $worker, 'ActionInvoker::invoke' ) ) {
	throw new RuntimeException( 'Runtime worker bypassed controlled phase execution.' );
}
if ( ! str_contains( $architecture, 'CapabilityRegistry::state_resolver' ) || ! str_contains( $architecture, 'CapabilityRegistry::executor' ) ) {
	throw new RuntimeException( 'Private Base execution bypasses are no longer architecture-guarded.' );
}

fwrite( STDOUT, "State execution slice: PASS\n" );
