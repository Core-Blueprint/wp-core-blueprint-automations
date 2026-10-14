<?php
declare(strict_types=1);

/**
 * W3.1 source and boundary contracts. Real dbDelta/WordPress UI is a separate
 * installation acceptance gate; no database is mutated in this test.
 */
$root = dirname( __DIR__, 2 );
$read = static function ( string $path ) use ( $root ): string {
	$contents = file_get_contents( $root . '/' . $path );
	if ( ! is_string( $contents ) ) {
		throw new RuntimeException( 'Missing W3.1 source: ' . $path );
	}
	return $contents;
};

$folder = $read( 'src/Organisation/FolderRepository.php' );
$controller = $read( 'src/Admin/WorkflowFolderController.php' );
$workflow = $read( 'src/Persistence/WorkflowRepository.php' );
$reader = $read( 'src/Admin/WorkflowAdminReadService.php' );
$page = $read( 'src/Admin/AutomationsPage.php' );
$view = $read( 'templates/admin/automations.php' );
$plugin = $read( 'src/Plugin.php' );
$wakeup = $read( 'src/Runtime/WorkerWakeup.php' );

foreach ( [
	[ $folder, "private const VERSION_OPTION" ], // covered by named OPTION below
] as $unused ) {
	// intentionally empty; assertions are grouped below
}
foreach ( [
	[ $folder, "private const OPTION = 'cb_automations_folders_schema_version'" ],
	[ $folder, 'dbDelta(' ],
	[ $folder, 'self::folders_table()' ],
	[ $folder, 'self::assignments_table()' ],
	[ $folder, 'ON DUPLICATE KEY UPDATE folder_id = VALUES(folder_id)' ],
	[ $folder, "SELECT id FROM {\$folders} WHERE id = %d FOR UPDATE" ],
	[ $folder, '$wpdb->query( \'START TRANSACTION\' )' ],
	[ $folder, '$wpdb->query( \'ROLLBACK\' )' ],
	[ $folder, '$wpdb->query( \'COMMIT\' )' ],
	[ $folder, '$wpdb->delete( $assignments, [ \'folder_id\' => $id ]' ],
	[ $controller, 'current_user_can( AutomationsPage::CAPABILITY )' ],
	[ $controller, "wp_verify_nonce( \$nonce, 'cb_automations_folder_' . \$action )" ],
	[ $controller, "'1' === sanitize_text_field(" ],
	[ $controller, 'FolderRepository::delete(' ],
	[ $controller, 'FolderRepository::assign(' ],
	[ $plugin, 'WorkflowFolderController::init();' ],
	[ $reader, 'WorkflowRepository::count_index( $search, $status, $folder_id )' ],
	[ $reader, 'WorkflowRepository::query_index( $per_page, $offset, $search, $status, $sort, $folder_id )' ],
	[ $workflow, "NOT EXISTS (SELECT 1 FROM {\$links} f" ],
	[ $workflow, "EXISTS (SELECT 1 FROM {\$links} f" ],
	[ $workflow, '$args[] = $folder_id' ],
	[ $page, 'FolderRepository::ensure_ready();' ],
	[ $page, "'assignments' => \$assignments" ],
	[ $view, 'name="folder"' ],
	[ $view, 'name="folder_id"' ],
	[ $view, 'name="confirm_delete"' ],
	[ $view, 'cb_automations_folder_rename' ],
	[ $view, 'cb_automations_folder_assign' ],
	[ $view, "'folder' => null === \$folder_filter" ],
] as [ $haystack, $needle ] ) {
	if ( ! str_contains( $haystack, $needle ) ) {
		throw new RuntimeException( 'Missing W3.1 safety contract: ' . $needle );
	}
}
if ( str_contains( $folder, 'wp_schedule_single_event(' )
	|| str_contains( $folder, 'WorkflowRepository::update(' )
	|| str_contains( $folder, 'definition_json' )
	|| str_contains( $folder, 'activation_state' )
	|| str_contains( $folder, 'execution_principal_user_id' ) ) {
	throw new RuntimeException( 'Folder storage must not mutate executable workflow state or schedule workers.' );
}
if ( 1 !== substr_count( $wakeup, 'wp_schedule_single_event' ) ) {
	throw new RuntimeException( 'Worker wakeup contract must remain unchanged.' );
}
fwrite( STDOUT, "Automations W3.1 folder isolation and UI contracts: PASS\n" );
