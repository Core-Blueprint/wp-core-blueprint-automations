<?php
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/' );

require dirname( __DIR__, 2 ) . '/src/Runtime/ActionCausality.php';
use CB\Automations\Runtime\ActionCausality as Guard;

$correlation = '123e4567-e89b-42d3-a456-426614174000';
$other = '223e4567-e89b-42d3-a456-426614174000';
$fail = static function ( callable $operation ): void {
	try { $operation(); } catch ( \UnexpectedValueException ) { return; }
	throw new \RuntimeException( 'Invalid causal data must fail closed.' );
};

$root = Guard::for_run( 10, $correlation, null );
if ( [ 'correlation_id' => $correlation, 'workflow_path' => [ 10 ] ] !== $root ) {
	throw new \RuntimeException( 'Root action was not attributed to its workflow.' );
}
if ( null !== Guard::current() ) { throw new \RuntimeException( 'Causal scope leaked before invocation.' ); }

Guard::during_action( $root, static function () use ( $root, $correlation ): void {
	if ( $root !== Guard::current() ) { throw new \RuntimeException( 'Action context was not scoped.' ); }
	if ( 'runtime.causal_cycle_prevented' !== Guard::suppression_reason( $root, 10 ) ) {
		throw new \RuntimeException( 'A -> A was not blocked.' );
	}
	if ( '' !== Guard::suppression_reason( $root, 20 ) ) {
		throw new \RuntimeException( 'Legitimate A -> B was blocked.' );
	}
	// Mimic encrypted event receipt + run context serialization across WP-Cron jobs.
	$persisted = json_decode( json_encode( Guard::current(), JSON_THROW_ON_ERROR ), true, 8, JSON_THROW_ON_ERROR );
	$next = Guard::for_run( 20, $correlation, $persisted );
	Guard::during_action( $next, static function () use ( $next ): void {
		if ( 'runtime.causal_cycle_prevented' !== Guard::suppression_reason( $next, 10 )
			|| 'runtime.causal_cycle_prevented' !== Guard::suppression_reason( $next, 20 )
			|| '' !== Guard::suppression_reason( $next, 30 ) ) {
			throw new \RuntimeException( 'A -> B -> A/B cycle incorrectly allowed.' );
		}
	} );
	if ( Guard::current() !== $root ) { throw new \RuntimeException( 'Nested context did not restore its parent.' ); }
} );
if ( null !== Guard::current() ) { throw new \RuntimeException( 'Causal context was not released.' ); }

try {
	Guard::during_action( $root, static function (): void { throw new \RuntimeException( 'provider failure' ); } );
} catch ( \RuntimeException ) {}
if ( null !== Guard::current() ) { throw new \RuntimeException( 'A failing action leaked its causal context.' ); }
$independent = Guard::for_run( 10, $other, null );
if ( [ 10 ] !== $independent['workflow_path'] || $other !== $independent['correlation_id'] ) {
	throw new \RuntimeException( 'Independent human-originated events must be allowed.' );
}

$at_limit = [ 'correlation_id' => $correlation, 'workflow_path' => range( 1, Guard::MAX_DEPTH ) ];
if ( 'runtime.causal_depth_limit' !== Guard::suppression_reason( $at_limit, 99 ) ) {
	throw new \RuntimeException( 'Maximum event chain depth is not bounded.' );
}
$fail( static fn () => Guard::for_run( 99, $correlation, $at_limit ) );
$fail( static fn () => Guard::for_run( 10, $correlation, $root ) );
$fail( static fn () => Guard::for_run( 20, $other, $root ) );
$fail( static fn () => Guard::validate( [ 'correlation_id' => $correlation, 'workflow_path' => [ 10, 10 ] ] ) );
$fail( static fn () => Guard::validate( [ 'correlation_id' => $correlation, 'workflow_path' => [ '10' ] ] ) );
$fail( static fn () => Guard::validate( [ 'correlation_id' => $correlation, 'workflow_path' => [] ] ) );
$fail( static fn () => Guard::validate( [ 'correlation_id' => $correlation, 'workflow_path' => [ 10 ], 'untrusted' => 1 ] ) );

// Boundary checks ensure no workarounds skip the authenticated event/run envelope.
$base = dirname( __DIR__, 2 );
$action = file_get_contents( $base . '/src/Runtime/ActionPhaseRunner.php' );
$intake = file_get_contents( $base . '/src/Runtime/TriggerIntake.php' );
$receipt = file_get_contents( $base . '/src/Persistence/EventReceiptRepository.php' );
$materializer = file_get_contents( $base . '/src/Runtime/EventMaterializer.php' );
$machine = file_get_contents( $base . '/src/Runtime/RunStateMachine.php' );
$run_repo = file_get_contents( $base . '/src/Persistence/RunRepository.php' );
foreach ( [
	[ $action, 'ActionCausality::during_action(' ],
	[ $action, 'ActionCausality::for_run(' ],
	[ $intake, 'ActionCausality::current()' ],
	[ $receipt, 'Vault::seal( $content' ],
	[ $receipt, 'ActionCausality::validate(' ],
	[ $materializer, 'EventReceiptRepository::open_event(' ],
	[ $materializer, 'ActionCausality::suppression_reason(' ],
	[ $materializer, 'RunStatus::Skipped, $reason' ],
	[ $machine, 'RunStatus::Cancelled, RunStatus::Skipped' ],
	[ $run_repo, 'RunStatus::Succeeded ], true' ],
] as $expect ) {
	if ( ! is_string( $expect[0] ) || ! str_contains( $expect[0], $expect[1] ) ) {
		throw new \RuntimeException( 'Missing runtime causality integration: ' . $expect[1] );
	}
}

fwrite( STDOUT, "Runtime causal loop prevention: PASS\n" );
