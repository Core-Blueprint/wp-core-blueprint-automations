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

use CB\Automations\Condition\OperatorCatalog;
use CB\Automations\Runtime\RunCursor;

$cases = [
	[ 'equals', 1, 1.0, true ],
	[ 'not_equals', 'a', 'b', true ],
	[ 'contains', 'core blueprint', 'blue', true ],
	[ 'not_contains', 'core blueprint', 'other', true ],
	[ 'contains', [ 1, 2, 3 ], 2.0, true ],
	[ 'greater_than', 4, 3.5, true ],
	[ 'greater_than_or_equal', 4, 4.0, true ],
	[ 'less_than', 3.5, 4, true ],
	[ 'less_than_or_equal', 4, 4, true ],
	[ 'is_empty', [], null, true ],
	[ 'is_not_empty', 'x', null, true ],
	[ 'equals', [ 1, 2.0 ], [ 1.0, 2 ], true ],
];
foreach ( $cases as [ $operator, $left, $right, $expected ] ) {
	if ( OperatorCatalog::evaluate( $operator, $left, $right ) !== $expected ) {
		throw new RuntimeException( 'Condition evaluator changed canonical semantics for ' . $operator . '.' );
	}
}
try {
	OperatorCatalog::evaluate( 'contains', 12, 2 );
	throw new RuntimeException( 'Condition evaluator accepted an invalid left operand type.' );
} catch ( UnexpectedValueException ) {
	/* expected */
}

if ( 0 !== RunCursor::condition_index( RunCursor::CONDITIONS, 2 ) ) {
	throw new RuntimeException( 'Conditions phase did not start at index zero.' );
}
if ( 1 !== RunCursor::condition_index( 'conditions:1', 2 ) ) {
	throw new RuntimeException( 'Condition cursor did not resume at the stored index.' );
}
if ( 'actions:0' !== RunCursor::after_condition( 2, 2 ) ) {
	throw new RuntimeException( 'Final matching condition must advance to Actions.' );
}
if ( null !== RunCursor::state_index( 'conditions:1', 2 ) ) {
	throw new RuntimeException( 'State phase can replay after Conditions has already advanced.' );
}

$root = dirname( __DIR__, 2 );
$runner = file_get_contents( $root . '/src/Runtime/ConditionPhaseRunner.php' );
$catalog = file_get_contents( $root . '/src/Condition/OperatorCatalog.php' );
$validator = file_get_contents( $root . '/src/Validation/WorkflowValidator.php' );
$worker = file_get_contents( $root . '/src/Runtime/RuntimeWorker.php' );
$architecture = file_get_contents( $root . '/tests/unit/architecture.php' );
if ( false === $runner || false === $catalog || false === $validator || false === $worker || false === $architecture ) {
	throw new RuntimeException( 'Could not read AU2 Condition runtime sources.' );
}
if ( ! str_contains( $validator, 'OperatorCatalog::get(' ) || ! str_contains( $runner, 'OperatorCatalog::evaluate(' ) ) {
	throw new RuntimeException( 'Builder validation and runtime do not share the canonical OperatorCatalog authority.' );
}
foreach ( [
	'RunExecutionLease $lease',
	'$lease->renew(',
	'$lease->transaction(',
	'BindingResolver::resolve(',
	'RunStepRepository::skip(',
	'RunStepRepository::succeed(',
	'RunRepository::advance_cursor(',
] as $needle ) {
	if ( ! str_contains( $runner, $needle ) ) {
		throw new RuntimeException( 'Condition execution lost runtime invariant: ' . $needle );
	}
}
if ( str_contains( $runner, 'ActionInvoker::invoke' ) || str_contains( $worker, 'StateInvoker::resolve' ) || str_contains( $worker, 'ActionInvoker::invoke' ) ) {
	throw new RuntimeException( 'Condition/worker bypassed orchestration boundaries.' );
}
if ( ! str_contains( $architecture, 'CapabilityRegistry::state_resolver' ) || ! str_contains( $architecture, 'CapabilityRegistry::executor' ) ) {
	throw new RuntimeException( 'Private Base execution bypasses are no longer architecture-guarded.' );
}

fwrite( STDOUT, "Condition execution slice: PASS\n" );
