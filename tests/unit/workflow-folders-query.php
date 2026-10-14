<?php
declare(strict_types=1);

// Isolated negative SQL-path test. No WordPress boot, DB, files, or network.
namespace CB\Automations\Organisation {
	final class FolderRepository {
		public static function assignments_table(): string { return 'wp_cb_automations_folder_assignments'; }
	}
}
namespace CB\Automations\Persistence {
	final class Schema {
		public static function table(): string { return 'wp_cb_automations_workflows'; }
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	final class FolderIndexFakeWpdb {
		public string $last_error = '';
		public string $sql = '';
		public array $args = [];
		public function esc_like( string $value ): string { return addcslashes( $value, '\\_%' ); }
		public function prepare( string $sql, mixed ...$args ): string {
			$this->sql = $sql;
			$this->args = $args;
			if ( substr_count( $sql, '%s' ) + substr_count( $sql, '%d' ) !== count( $args ) ) {
				throw new \RuntimeException( 'Prepared query has wrong number of placeholders.' );
			}
			return str_replace( [ '%s', '%d' ], '?', $sql );
		}
		public function get_var( string $sql ): int { $this->sql = $sql; return 0; }
		public function get_results( string $sql, string $type ): array { return []; }
	}
	$GLOBALS['wpdb'] = new FolderIndexFakeWpdb();
	define( 'ARRAY_A', 'ARRAY_A' );
	require_once dirname( __DIR__, 2 ) . '/src/Persistence/WorkflowRepository.php';
	$repo = \CB\Automations\Persistence\WorkflowRepository::class;

	$repo::count_index( 'hello', 'disabled', 4 );
	$sql = $GLOBALS['wpdb']->sql;
	$args = $GLOBALS['wpdb']->args;
	if ( ! str_contains( $sql, 'activation_state = %s' )
		|| ! str_contains( $sql, 'name LIKE %s' )
		|| ! str_contains( $sql, 'f.folder_id = %d' )
		|| $args !== [ 'disabled', 4, '%hello%' ] ) {
		throw new \RuntimeException( 'Folder filter must be combined with prepared search and status before counting.' );
	}

	$repo::count_index( '', 'all', 0 );
	if ( ! str_contains( $GLOBALS['wpdb']->sql, 'NOT EXISTS' ) || [] !== $GLOBALS['wpdb']->args ) {
		throw new \RuntimeException( 'Unfiled filter must select workflows without a folder link.' );
	}

	$repo::query_index( 25, 0, 'safe', 'enabled', 'name_asc', 5 );
	if ( ! str_contains( $GLOBALS['wpdb']->sql, 'ORDER BY name ASC, id ASC LIMIT %d OFFSET %d' )
		|| $GLOBALS['wpdb']->args !== [ 'enabled', 5, '%safe%', 25, 0 ] ) {
		throw new \RuntimeException( 'Paging must remain stable after applying folder and status filters.' );
	}

	$repo::count_index( '', 'all', null );
	if ( str_contains( $GLOBALS['wpdb']->sql, 'folder_assignments' ) ) {
		throw new \RuntimeException( 'Existing unfiltered workspace must not depend on a folder join.' );
	}

	fwrite( STDOUT, "Automations W3.1 folder search/pagination SQL regression: PASS\n" );
}
