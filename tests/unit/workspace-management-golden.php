<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$repository = file_get_contents( $root . '/src/Persistence/WorkflowRepository.php' );
$reader = file_get_contents( $root . '/src/Admin/WorkflowAdminReadService.php' );
$page = file_get_contents( $root . '/src/Admin/AutomationsPage.php' );
$view = file_get_contents( $root . '/templates/admin/automations.php' );
$style = file_get_contents( $root . '/assets/css/admin-overview.css' );
foreach ( [ $repository, $reader, $page, $view, $style ] as $file ) {
	if ( ! is_string( $file ) ) { throw new RuntimeException( 'Workspace source missing.' ); }
}
foreach ( [
	[ $repository, 'public static function query_index(' ],
	[ $repository, 'public static function count_index(' ],
	[ $repository, '$wpdb->esc_like(' ],
	[ $repository, 'name LIKE %s' ],
	[ $repository, "'name_asc' => 'name ASC, id ASC'" ],
	[ $repository, "'enabled', 'disabled'" ],
	[ $reader, 'WorkflowRepository::count_index(' ],
	[ $reader, 'WorkflowRepository::query_index(' ],
	[ $page, "'workflows', 'templates', 'create'" ],
	[ $view, 'name="s"' ],
	[ $view, 'name="sort"' ],
	[ $view, 'name="status"' ],
	[ $view, 'class="widefat striped cb-automations-workspace__table"' ],
	[ $view, 'WorkflowActivationController::nonce_action(' ],
	[ $view, "esc_html_e( 'Use template'" ],
	[ $style, '.cb-automations-workspace__table-scroll' ],
] as [ $content, $expected ] ) {
	if ( ! str_contains( $content, $expected ) ) {
		throw new RuntimeException( 'Workspace contract absent: ' . $expected );
	}
}
if ( str_contains( $view, 'cb-automations-workflow-card__main' )
	|| str_contains( $repository, 'ORDER BY {$sort}' ) ) {
	throw new RuntimeException( 'Workspace retained large stacked cards or an unsafe sort interpolation.' );
}
fwrite( STDOUT, "Automations Workflows Workspace Golden: PASS\n" );
