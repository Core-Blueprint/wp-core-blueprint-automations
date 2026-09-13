<?php
declare(strict_types=1);
define( 'ABSPATH', __DIR__ . '/' );
spl_autoload_register( static function ( string $class ): void { $prefix = 'CB\\Automations\\'; if ( ! str_starts_with( $class, $prefix ) ) { return; } $file = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php'; if ( is_file( $file ) ) { require_once $file; } } );
use CB\Automations\Runtime\RunCursor;
use CB\Automations\Runtime\RunStateMachine;
use CB\Automations\Runtime\RunStatus;
if ( 0 !== RunCursor::action_index( 'actions:0', 2 ) || 1 !== RunCursor::action_index( 'actions:1', 2 ) || RunCursor::COMPLETE !== RunCursor::after_action( 2, 2 ) || null !== RunCursor::action_index( RunCursor::COMPLETE, 2 ) ) { throw new RuntimeException( 'Action cursor contract changed.' ); }
if ( ! RunStateMachine::allows( RunStatus::Running, RunStatus::Indeterminate ) ) { throw new RuntimeException( 'Action outcome cannot become indeterminate.' ); }
$root = dirname( __DIR__, 2 );
$runner = file_get_contents( $root . '/src/Runtime/ActionPhaseRunner.php' ); $steps = file_get_contents( $root . '/src/Persistence/RunStepRepository.php' ); $worker = file_get_contents( $root . '/src/Runtime/RuntimeWorker.php' ); $architecture = file_get_contents( $root . '/tests/unit/architecture.php' );
if ( false === $runner || false === $steps || false === $worker || false === $architecture ) { throw new RuntimeException( 'Could not read Action sources.' ); }
foreach ( [ 'RunExecutionLease $lease', '$lease->renew(', '$lease->transaction(', 'ActionInvoker::invoke(', 'new InvocationContext(', 'BindingResolver::resolve_all(', 'RunStepRepository::find_running_attempt(', 'RunStepRepository::indeterminate(', 'RunContextRepository::put(', 'RunRepository::transition_status(' ] as $needle ) { if ( ! str_contains( $runner, $needle ) ) { throw new RuntimeException( 'Action execution lost invariant: ' . $needle ); } }
if ( str_contains( $runner, 'interrupt_running_attempts(' ) ) { throw new RuntimeException( 'Mutating Action uses replay-safe interruption recovery.' ); }
if ( ! str_contains( $steps, 'StepStatus::Indeterminate' ) || ! str_contains( $runner, 'RunStatus::Indeterminate' ) || ! str_contains( $runner, 'RunStepRepository::indeterminate(' ) ) { throw new RuntimeException( 'Indeterminate persistence route disappeared.' ); }
$persist_pos = strpos( $runner, 'private static function persist_success' ); if ( false === $persist_pos || str_contains( substr( $runner, $persist_pos ), 'ActionInvoker::invoke' ) ) { throw new RuntimeException( 'Provider Action execution crossed acknowledgement transaction.' ); }
if ( str_contains( $worker, 'ActionInvoker::invoke' ) || ! str_contains( $worker, 'RunPipeline::execute(' ) ) { throw new RuntimeException( 'Worker bypasses canonical run pipeline.' ); }
if ( ! str_contains( $architecture, 'CapabilityRegistry::executor' ) ) { throw new RuntimeException( 'Private Base executor bypass guard disappeared.' ); }
$hits = [];
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) { if ( ! $file instanceof SplFileInfo || ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) { continue; } $content = file_get_contents( $file->getPathname() ); if ( false !== $content && str_contains( $content, 'ActionInvoker::invoke(' ) ) { $hits[] = str_replace( $root . '/', '', $file->getPathname() ); } }
if ( [ 'src/Runtime/ActionPhaseRunner.php' ] !== $hits ) { throw new RuntimeException( 'ActionInvoker has more than one mutation route: ' . implode( ', ', $hits ) ); }
fwrite( STDOUT, "Action execution slice: PASS\n" );
