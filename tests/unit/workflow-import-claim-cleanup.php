<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['cb_claim_options'] = [];
$GLOBALS['cb_claim_deleted'] = [];
final class FakeAutomationOptionsDb {
	public string $options = 'wp_options';
	public string $sql = '';
	/** @var string[] */
	public array $results = [];
	public function esc_like( string $s ): string { return addcslashes( $s, '_%\\' ); }
	public function prepare( string $sql, mixed ...$args ): string {
		$this->sql = $sql;
		if ( count( $args ) !== 2 || ! str_ends_with( (string) $args[0], '%' ) || ! is_int( $args[1] ) ) {
			throw new \RuntimeException( 'Claim cleanup query must be prefix-scoped and time-bounded.' );
		}
		return $sql;
	}
	/** @return string[] */
	public function get_col( string $sql ): array { return $this->results; }
}
$GLOBALS['wpdb'] = new FakeAutomationOptionsDb();

function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['cb_claim_options'][$key] ?? $default;
}
function delete_option( string $key ): bool {
	$GLOBALS['cb_claim_deleted'][] = $key;
	unset( $GLOBALS['cb_claim_options'][$key] );
	return true;
}

require_once dirname( __DIR__, 2 ) . '/src/Admin/WorkflowTransferController.php';

$expired = 'cb_automations_import_claim_' . str_repeat( 'a', 32 );
$active = 'cb_automations_import_claim_' . str_repeat( 'b', 32 );
$invalid = 'cb_automations_import_claim_' . str_repeat( 'c', 31 ) . '!';
$unrelated = 'another_plugin_maintenance_state';
$GLOBALS['cb_claim_options'] = [
	$expired => time() - 1,
	$active => time() + 600,
	$invalid => time() - 1,
	$unrelated => time() - 1,
];
$GLOBALS['wpdb']->results = [ $expired, $active, $invalid, $unrelated ];
\CB\Automations\Admin\WorkflowTransferController::cleanup_expired_claims();
if ( [ $expired ] !== $GLOBALS['cb_claim_deleted']
	|| ! isset( $GLOBALS['cb_claim_options'][$active] )
	|| ! isset( $GLOBALS['cb_claim_options'][$invalid] )
	|| ! isset( $GLOBALS['cb_claim_options'][$unrelated] ) ) {
	throw new \RuntimeException( 'Maintenance must purge only expired, correctly scoped claims.' );
}
if ( ! str_contains( $GLOBALS['wpdb']->sql, 'LIMIT 200' )
	|| ! str_contains( $GLOBALS['wpdb']->sql, 'CAST(option_value AS UNSIGNED)' ) ) {
	throw new \RuntimeException( 'Maintenance query is not bounded.' );
}
$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/WorkflowTransferController.php' );
$plugin = file_get_contents( dirname( __DIR__, 2 ) . '/src/Plugin.php' );
if ( false === $source || false === $plugin || str_contains( $source, 'wp_schedule_single_event(' )
	|| ! str_contains( $source, "add_action( 'wp_scheduled_delete'" )
	|| ! str_contains( $plugin, 'WorkflowTransferController::init_maintenance();' )
	|| strpos( $plugin, 'WorkflowTransferController::init_maintenance();' ) > strpos( $plugin, 'if ( ! is_admin()' ) ) {
	throw new \RuntimeException( 'Importer must not schedule its own WP-Cron event.' );
}
fwrite( STDOUT, "WordPress scheduled import claim cleanup: PASS\n" );
