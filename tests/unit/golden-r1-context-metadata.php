<?php
declare(strict_types=1);

/**
 * Golden-R1: Designer context list is metadata-only, ordered, bounded and
 * independent of corrupt workflow JSON. No WordPress runtime or real database.
 */
namespace CB\Automations\Persistence {
	final class Schema {
		public static function table(): string { return 'wp_cb_automations_workflows'; }
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );

	final class GoldenR1ContextWpdb {
		public string $last_error = '';
		public string $sql = '';
		public array $parameters = [];
		public int $read_count = 0;
		public function prepare( string $sql, mixed ...$parameters ): string {
			$this->sql = $sql;
			$this->parameters = $parameters;
			if ( substr_count( $sql, '%d' ) + substr_count( $sql, '%s' ) !== count( $parameters ) ) {
				throw new \RuntimeException( 'Prepared placeholder mismatch.' );
			}
			return $sql;
		}
		public function get_var( string $sql ): int {
			$this->sql = $sql;
			return 2;
		}
		public function get_results( string $sql, string $mode ): array {
			if ( ARRAY_A !== $mode || str_contains( $sql, 'definition_json' )
				|| ! preg_match( '/SELECT id, name\s+FROM wp_cb_automations_workflows\s+ORDER BY updated_at DESC, id DESC\s+LIMIT %d OFFSET %d/s', $sql ) ) {
				throw new \RuntimeException( 'Context list must select stable metadata only.' );
			}
			++$this->read_count;
			// The stored definitions could be corrupt; they must never be hydrated.
			return [
				[ 'id' => '12', 'name' => 'First', 'definition_json' => '{broken' ],
				[ 'id' => '11', 'name' => 'Second', 'definition_json' => '{also broken' ],
			];
		}
	}
	$GLOBALS['wpdb'] = new GoldenR1ContextWpdb();

	require dirname( __DIR__, 2 ) . '/src/Persistence/WorkflowRepository.php';
	require dirname( __DIR__, 2 ) . '/src/Admin/WorkflowAdminReadService.php';

	$repository = \CB\Automations\Persistence\WorkflowRepository::class;
	$rows = $repository::list_context_metadata( 500, -4 );
	if ( [ [ 'id' => 12, 'name' => 'First' ], [ 'id' => 11, 'name' => 'Second' ] ] !== $rows ) {
		throw new \RuntimeException( 'Context metadata projection or ordering changed.' );
	}
	if ( [ 100, 0 ] !== $GLOBALS['wpdb']->parameters ) {
		throw new \RuntimeException( 'Context list pagination must be bounded and use placeholders.' );
	}

	$reader = ( new \ReflectionClass( \CB\Automations\Admin\WorkflowAdminReadService::class ) )->newInstanceWithoutConstructor();
	$items = $reader->context_items();
	if ( $items !== $rows || 2 !== $GLOBALS['wpdb']->read_count ) {
		throw new \RuntimeException( 'Designer context list rehydrated workflow definitions or changed identity.' );
	}

	fwrite( STDOUT, "Golden-R1 Designer metadata-only context: PASS\n" );
}
