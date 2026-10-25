<?php
declare(strict_types=1);

namespace CB\Automations\Persistence {
	final class Schema {
		public static bool $workflow_ready = true;
		public static bool $folders_ready = true;
		public static function authoring_ready(): bool { return self::$workflow_ready; }
		public static function folders_ready(): bool { return self::$folders_ready; }
		public static function table(): string { return 'wp_cb_workflows'; }
		public static function assignments_table(): string { return 'wp_cb_assignments'; }
	}
	final class PersistenceFailure extends \RuntimeException {
		public static function schema(): self { return new self( 'storage unavailable' ); }
		public static function database( string $operation ): self { return new self( $operation ); }
	}
}
namespace CB\Automations\Workflow {
	enum ActivationState: string { case Enabled = 'enabled'; case Disabled = 'disabled'; }
}
namespace {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );

	final class WorkflowDeleteFakeDb {
		public string $last_error = '';
		public array $records = [];
		public array $assignments = [];
		public array $history = [ 100 => 'immutable run snapshot' ];
		public int $deletes = 0;
		public int $fail_on_delete = 0;
		private ?array $snapshot = null;

		public function prepare( string $sql, mixed ...$args ): string {
			foreach ( $args as $value ) {
				$pos = strpos( $sql, '%d' );
				$type = '%d';
				if ( false === $pos ) { $pos = strpos( $sql, '%s' ); $type = '%s'; }
				if ( false === $pos ) { throw new \RuntimeException( 'Invalid test query placeholders.' ); }
				$sql = substr_replace( $sql, '%d' === $type ? (string) $value : "'" . (string) $value . "'", $pos, 2 );
			}
			return $sql;
		}
		public function query( string $sql ): int|false {
			if ( 'START TRANSACTION' === $sql ) {
				$this->snapshot = [ $this->records, $this->assignments ];
				return 0;
			}
			if ( 'ROLLBACK' === $sql ) {
				if ( null !== $this->snapshot ) { [ $this->records, $this->assignments ] = $this->snapshot; }
				$this->snapshot = null;
				return 0;
			}
			if ( 'COMMIT' === $sql ) { $this->snapshot = null; return 0; }
			if ( preg_match( "/^DELETE FROM wp_cb_workflows WHERE id = ([0-9]+) AND revision = ([0-9]+) AND activation_state = 'disabled'$/", $sql, $m ) ) {
				++$this->deletes;
				if ( $this->fail_on_delete === $this->deletes ) { return false; }
				$id = (int) $m[1];
				if ( ! isset( $this->records[ $id ] )
					|| $this->records[ $id ]['revision'] !== (int) $m[2]
					|| 'disabled' !== $this->records[ $id ]['activation_state'] ) { return 0; }
				unset( $this->records[ $id ] );
				return 1;
			}
			throw new \RuntimeException( 'Unexpected SQL: ' . $sql );
		}
		public function get_results( string $sql, string $format ): array {
			if ( ! preg_match( '/WHERE id IN \(([0-9, ]+)\) ORDER BY id ASC FOR UPDATE$/', $sql, $m ) ) {
				throw new \RuntimeException( 'Delete must lock exact target set in stable order: ' . $sql );
			}
			$ids = array_map( 'intval', explode( ',', $m[1] ) );
			$rows = [];
			foreach ( $ids as $id ) {
				if ( isset( $this->records[ $id ] ) ) { $rows[] = [ 'id' => $id ] + $this->records[ $id ]; }
			}
			return $rows;
		}
		public function delete( string $table, array $where, array $format ): int|false {
			if ( 'wp_cb_assignments' !== $table || ! isset( $where['workflow_id'] ) ) {
				throw new \RuntimeException( 'Only folder assignments may be cleaned.' );
			}
			$id = $where['workflow_id'];
			if ( ! isset( $this->assignments[ $id ] ) ) { return 0; }
			unset( $this->assignments[ $id ] );
			return 1;
		}
	}
	require_once dirname( __DIR__, 2 ) . '/src/Persistence/WorkflowRepository.php';
	$repo = \CB\Automations\Persistence\WorkflowRepository::class;
	$assert = static function ( bool $ok, string $message ): void {
		if ( ! $ok ) { throw new \RuntimeException( $message ); }
	};
	$setup = static function (): void {
		$GLOBALS['wpdb'] = new WorkflowDeleteFakeDb();
		$GLOBALS['wpdb']->records = [
			7 => [ 'revision' => 8, 'activation_state' => 'disabled' ],
			8 => [ 'revision' => 11, 'activation_state' => 'disabled' ],
			9 => [ 'revision' => 2, 'activation_state' => 'enabled' ],
		];
		$GLOBALS['wpdb']->assignments = [ 7 => 3, 8 => 3, 9 => 4 ];
		\CB\Automations\Persistence\Schema::$workflow_ready = true;
		\CB\Automations\Persistence\Schema::$folders_ready = true;
	};
	$setup();
	$result = $repo::delete_disabled_batch( [ 8 => 11, 7 => 8 ] );
	$assert( 'deleted' === $result && array_keys( $GLOBALS['wpdb']->records ) === [ 9 ], 'Two disabled workflows were not atomically deleted.' );
	$assert( [ 9 => 4 ] === $GLOBALS['wpdb']->assignments, 'Folder assignments were not cleaned.' );
	$assert( [ 100 => 'immutable run snapshot' ] === $GLOBALS['wpdb']->history, 'Run history was altered.' );
	$setup();
	$assert( 'enabled' === $repo::delete_disabled_batch( [ 7 => 8, 9 => 2 ] ), 'Enabled workflow must block whole selection.' );
	$assert( 0 === $GLOBALS['wpdb']->deletes && 3 === count( $GLOBALS['wpdb']->records ), 'Enabled rejection partially deleted workflows.' );
	$setup();
	$assert( 'conflict' === $repo::delete_disabled_batch( [ 7 => 7, 8 => 11 ] ), 'Stale revision must block deletion.' );
	$assert( 0 === $GLOBALS['wpdb']->deletes, 'Stale selection triggered deletion.' );
	$setup();
	$assert( 'not_found' === $repo::delete_disabled_batch( [ 7 => 8, 1000 => 1 ] ), 'Missing row must block deletion.' );
	$assert( 0 === $GLOBALS['wpdb']->deletes, 'Missing selection partially deleted workflows.' );
	$setup();
	$GLOBALS['wpdb']->fail_on_delete = 2;
	try { $repo::delete_disabled_batch( [ 7 => 8, 8 => 11 ] ); throw new \RuntimeException( 'Expected injected DB failure.' ); }
	catch ( \CB\Automations\Persistence\PersistenceFailure ) {}
	$assert( 3 === count( $GLOBALS['wpdb']->records ) && 3 === count( $GLOBALS['wpdb']->assignments ), 'Transaction did not roll back incomplete deletion.' );
	$setup();
	\CB\Automations\Persistence\Schema::$folders_ready = false;
	try { $repo::delete_disabled_batch( [ 7 => 8 ] ); throw new \RuntimeException( 'Expected missing folder storage refusal.' ); }
	catch ( \CB\Automations\Persistence\PersistenceFailure ) {}
	$assert( 0 === $GLOBALS['wpdb']->deletes, 'Missing assignment storage allowed unsafe deletion.' );
	$setup();
	try { $repo::delete_disabled_batch( array_fill_keys( range( 1, 26 ), 1 ) ); throw new \RuntimeException( 'Expected selection size refusal.' ); }
	catch ( \InvalidArgumentException ) {}
	$assert( 0 === $GLOBALS['wpdb']->deletes, 'Oversized batch reached SQL.' );
	echo "W3.2 transactional workflow delete and history retention: PASS\n";
}
