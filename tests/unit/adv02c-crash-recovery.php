<?php
declare(strict_types=1);

// C6 exercises the actual pre-Vault recovery implementation with a strict
// transactional SQL double. Real InnoDB evidence is separately required.
namespace CB\Automations\Workflow {
	final class GraphDefinition { public const VERSION = 2; }
}
namespace CB\Automations\Persistence {
	final class Schema {
		public static function runs_table(): string { return 'wp_runs'; }
		public static function run_steps_table(): string { return 'wp_steps'; }
	}
	final class PersistenceFailure extends \RuntimeException {
		public static function definition(): self { return new self( 'Invalid snapshot.' ); }
		public static function database( string $message ): self { return new self( $message ); }
	}
	final class RunStepRepository {
		public static function indeterminate( \CB\Automations\Runtime\RunStepAttempt $attempt, string $reason ): void {
			if ( ! $GLOBALS['wpdb']->locked || $reason !== 'runtime.action_outcome_unknown' ) {
				throw new \RuntimeException( 'Unfenced attempt mutation.' );
			}
			if ( $GLOBALS['wpdb']->step['id'] !== $attempt->id() || $GLOBALS['wpdb']->step['attempt'] !== $attempt->attempt() ) {
				throw new \RuntimeException( 'Mismatched attempt.' );
			}
			$GLOBALS['wpdb']->step['status'] = 'indeterminate';
			++$GLOBALS['c6_step_updates'];
		}
	}
	final class RunRepository {
		public static function transition_status( int $run_id, \CB\Automations\Runtime\RunStatus $from, \CB\Automations\Runtime\RunStatus $to, string $code ): bool {
			if ( ! $GLOBALS['wpdb']->locked || $run_id !== 37 || $code !== 'runtime.action_outcome_unknown' ) {
				throw new \RuntimeException( 'Unfenced run mutation.' );
			}
			if ( $GLOBALS['wpdb']->run['status'] !== $from->value ) {
				return false;
			}
			$GLOBALS['wpdb']->run['status'] = $to->value;
			++$GLOBALS['c6_run_updates'];
			return true;
		}
	}
}
namespace CB\Automations\Runtime {
	enum RunStatus: string {
		case Queued = 'queued'; case Running = 'running'; case RetryWait = 'retry_wait';
		case Blocked = 'blocked'; case Indeterminate = 'indeterminate'; case Succeeded = 'succeeded';
		public function halts_automatic_execution(): bool {
			return in_array( $this, [ self::Blocked, self::Indeterminate, self::Succeeded ], true );
		}
	}
	enum StepStatus: string { case Running = 'running'; case Indeterminate = 'indeterminate'; }
	enum NodeType: string { case Action = 'action'; }
	final class RunStepAttempt {
		public function __construct( private int $id, private int $attempt ) {}
		public function id(): int { return $this->id; }
		public function attempt(): int { return $this->attempt; }
	}
	final class RunExecutionLease {
		public bool $active = true;
		public function transaction( callable $callback ): mixed {
			if ( ! $this->active ) throw new \RuntimeException( 'Stale worker lease.' );
			$wpdb = $GLOBALS['wpdb'];
			$wpdb->locked = true;
			$run = $wpdb->run;
			$step = $wpdb->step;
			try {
				return $callback();
			} catch ( \Throwable $e ) {
				$wpdb->run = $run;
				$wpdb->step = $step;
				throw $e;
			} finally {
				$wpdb->locked = false;
			}
		}
	}
}
namespace {
	defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
	defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
	final class C6Sql {
		public string $last_error = '';
		public bool $locked = false;
		public bool $fail_steps = false;
		public ?array $step = null;
		public array $run = [];
		public int $vault_reads = 0;
		public function reset( string $status = 'running', ?string $attempt_status = 'running' ): void {
			$this->run = [ 'id' => 37, 'definition_version' => 2, 'status' => $status ];
			$this->step = null === $attempt_status ? null : [
				'id' => 91, 'run_id' => 37, 'attempt' => 1, 'status' => $attempt_status,
			];
			$this->last_error = '';
			$this->fail_steps = false;
		}
		public function prepare( string $sql, mixed ...$parameters ): string { return $sql; }
		public function get_row( string $sql, string $mode ): ?array {
			if ( ! $this->locked || ! str_contains( $sql, 'FOR UPDATE' ) ) {
				throw new \RuntimeException( 'Recovery attempted an unlocked read.' );
			}
			if ( str_contains( $sql, 'wp_runs' ) ) return $this->run;
			if ( str_contains( $sql, 'wp_steps' ) ) {
				if ( $this->fail_steps ) { $this->last_error = 'sql_lost'; return null; }
				return $this->step;
			}
			throw new \RuntimeException( 'Unexpected SQL read.' );
		}
	}
	$wpdb = new C6Sql();
	require_once dirname( __DIR__, 2 ) . '/src/Runtime/GraphActionRecoveryFence.php';
	use CB\Automations\Runtime\GraphActionRecoveryFence as Fence;
	use CB\Automations\Runtime\RunStatus;
	use CB\Automations\Runtime\RunExecutionLease;
	function c6_assert( bool $ok, string $label ): void { if ( ! $ok ) throw new RuntimeException( $label ); }
	function c6_fails( callable $test, string $label ): void {
		try { $test(); } catch ( \Throwable ) { return; }
		throw new RuntimeException( $label );
	}
	$lease = new RunExecutionLease();
	$GLOBALS['c6_step_updates'] = 0; $GLOBALS['c6_run_updates'] = 0;
	$wpdb->reset( 'running', null );
	c6_assert( null === Fence::before_vault( 37, $lease ), 'Normal execution was not permitted.' );
	c6_assert( $GLOBALS['c6_step_updates'] === 0, 'Normal execution mutated history.' );
	$wpdb->reset();
	c6_assert( RunStatus::Indeterminate === Fence::before_vault( 37, $lease ), 'Orphaned Action was not quarantined.' );
	c6_assert( $wpdb->run['status'] === 'indeterminate' && $wpdb->step['status'] === 'indeterminate', 'Action and run were not settled atomically.' );
	c6_assert( $GLOBALS['c6_step_updates'] === 1 && $GLOBALS['c6_run_updates'] === 1, 'Action was settled more than once.' );
	c6_assert( RunStatus::Indeterminate === Fence::before_vault( 37, $lease ), 'Terminal Action was re-admitted.' );
	c6_assert( $GLOBALS['c6_step_updates'] === 1 && $GLOBALS['c6_run_updates'] === 1, 'Repeated recovery rewrote terminal evidence.' );
	$wpdb->reset( 'running', 'indeterminate' );
	c6_assert( RunStatus::Indeterminate === Fence::before_vault( 37, $lease ), 'Existing uncertain Action was ignored.' );
	c6_assert( $GLOBALS['c6_step_updates'] === 1, 'Indeterminate attempt was double-mutated.' );
	$wpdb->reset( 'queued', 'running' );
	c6_assert( RunStatus::Blocked === Fence::before_vault( 37, $lease ), 'Queued run with orphaned Action was not blocked.' );
	$wpdb->reset( 'succeeded', 'running' );
	c6_assert( RunStatus::Succeeded === Fence::before_vault( 37, $lease ), 'Completed run was overwritten.' );
	$wpdb->reset(); $wpdb->fail_steps = true;
	$prior_run = $wpdb->run; $prior_step = $wpdb->step;
	c6_fails( static fn () => Fence::before_vault( 37, $lease ), 'SQL uncertainty was treated as no Action.' );
	c6_assert( $wpdb->run === $prior_run && $wpdb->step === $prior_step, 'SQL uncertainty mutated state.' );
	$wpdb->reset(); $lease->active = false;
	c6_fails( static fn () => Fence::before_vault( 37, $lease ), 'Expired lease bypassed fence.' );
	c6_assert( $wpdb->run['status'] === 'running', 'Expired lease changed the run.' );
	$lease->active = true;
	$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Runtime/GraphActionRecoveryFence.php' );
	c6_assert( is_string( $source ) && ! str_contains( $source, 'RunContextRepository::get(' )
		&& ! str_contains( $source, 'ActionInvoker::invoke(' )
		&& str_contains( $source, 'FOR UPDATE' ), 'C6 recovery crossed the pre-Vault boundary.' );
	echo "ADV-02C C6 pre-Vault action crash quarantine and lease fencing: PASS\n";
}