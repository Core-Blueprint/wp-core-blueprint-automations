<?php
declare(strict_types=1);

/**
 * ADV-02A: real WorkflowService/Repository/SaveReceipt with fake wpdb.
 * No WordPress installation or database is touched.
 */
defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\Automations\\';
	if ( ! str_starts_with( $class, $prefix ) ) return;
	$file = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( is_file( $file ) ) require_once $file;
} );

use CB\Automations\Capability\CapabilityReference;
use CB\Automations\Discovery\CapabilityDefinition;
use CB\Automations\Discovery\CapabilitySource;
use CB\Automations\Discovery\ProviderStatus;
use CB\Automations\Persistence\WorkflowRepository;
use CB\Automations\Runtime\TriggerKey;
use CB\Automations\Workflow\ActivationState;
use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\GraphDefinition;
use CB\Automations\Workflow\WorkflowService;

function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); }
function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
function current_time( string $format, bool $gmt = false ): string { return '2026-10-27 12:00:00'; }

final class Adv02aDb {
	public int $insert_id = 0;
	public string $last_error = '';
	public array $rows = [];
	public array $last_args = [];
	public int $updates = 0;

	public function insert( string $table, array $data, array $formats ): int {
		if ( 'wp_adv_workflows' !== $table ) throw new RuntimeException( 'Unexpected table.' );
		$this->insert_id++;
		$this->rows[ $this->insert_id ] = [ 'id' => $this->insert_id, 'revision' => 1 ] + $data;
		return 1;
	}
	public function prepare( string $sql, mixed ...$args ): string {
		$this->last_args = $args;
		// Real SQL stays inside the fixture; only strictly selected SQL patterns
		// are interpreted below, never evaluated as executable code.
		foreach ( $args as $value ) {
			if ( ! preg_match( '/%[sd]/', $sql, $matches, PREG_OFFSET_CAPTURE ) ) break;
			$replacement = '%d' === $matches[0][0] ? (string) (int) $value : "'" . str_replace( "'", "''", (string) $value ) . "'";
			$sql = substr_replace( $sql, $replacement, $matches[0][1], 2 );
		}
		return $sql;
	}
	public function get_row( string $sql, string $mode ): ?array {
		if ( ! preg_match( '/WHERE id = ([0-9]+) LIMIT 1/', $sql, $match ) ) throw new RuntimeException( 'Unexpected read query.' );
		return $this->rows[ (int) $match[1] ] ?? null;
	}
	public function get_results( string $sql, string $mode ): array {
		if ( str_contains( $sql, 'WHERE activation_state' ) && str_contains( $sql, 'definition_version = 1' ) ) return [];
		if ( str_contains( $sql, 'ORDER BY updated_at' ) ) return array_values( $this->rows );
		throw new RuntimeException( 'Unexpected list query.' );
	}
	public function query( string $sql ): int|false {
		if ( ! preg_match( '/WHERE id = ([0-9]+) AND revision = ([0-9]+) AND definition_version = ([0-9]+)/', $sql, $match ) ) {
			throw new RuntimeException( 'Missing revision/version CAS.' );
		}
		$id = (int) $match[1];
		$revision = (int) $match[2];
		$version = (int) $match[3];
		if ( ! isset( $this->rows[ $id ] ) || $this->rows[ $id ]['revision'] !== $revision || $this->rows[ $id ]['definition_version'] !== $version ) {
			return 0;
		}
		$values = $this->last_args;
		// [name,state,version,json,principal,trigger,updated_by,time,id,revision,version]
		$this->rows[ $id ]['name'] = $values[0];
		$this->rows[ $id ]['activation_state'] = $values[1];
		$this->rows[ $id ]['definition_version'] = $values[2];
		$this->rows[ $id ]['definition_json'] = $values[3];
		$this->rows[ $id ]['execution_principal_user_id'] = $values[4];
		$this->rows[ $id ]['trigger_key'] = $values[5];
		$this->rows[ $id ]['updated_by'] = $values[6];
		$this->rows[ $id ]['updated_at'] = $values[7];
		$this->rows[ $id ]['revision']++;
		$this->updates++;
		return 1;
	}
}

final class Adv02aSource implements CapabilitySource {
	public function current( CapabilityReference $reference ): ?CapabilityDefinition { return null; }
	public function provider_status( string $provider ): ProviderStatus {
		return ProviderStatus::from_inventory( $provider, null );
	}
}

// Stub only canonical storage readiness; Repository, Service and codecs are real.
class_alias( Adv02aSchema::class, 'CB\\Automations\\Persistence\\Schema' );
final class Adv02aSchema {
	public static function authoring_ready(): bool { return true; }
	public static function table(): string { return 'wp_adv_workflows'; }
}

$GLOBALS['wpdb'] = new Adv02aDb();
$db = $GLOBALS['wpdb'];
$source = new Adv02aSource();
$service = new WorkflowService( $source );
$v2 = GraphDefinition::from_values(
	[ 'nodes' => [], 'edges' => [] ],
	[ 'nodes' => [] ]
);
$id = $service->create( 'Advanced draft', $v2, 41 );
if ( 1 !== $id || 2 !== $db->rows[ $id ]['definition_version'] ||
	'disabled' !== $db->rows[ $id ]['activation_state'] || '' !== $db->rows[ $id ]['trigger_key'] ) {
	throw new RuntimeException( 'Advanced draft create did not stay disabled.' );
}
$found = $service->find( $id );
if ( null === $found || ! $found->definition() instanceof GraphDefinition
	|| $found->definition()->to_array() !== $v2->to_array() ) {
	throw new RuntimeException( 'Advanced draft hydrate/readback failed.' );
}
$enable = $service->save( $id, 1, 'Advanced draft', ActivationState::Enabled, $v2, 41 );
if ( $enable->was_saved() || $db->updates !== 0 ) {
	throw new RuntimeException( 'Advanced activation was accepted.' );
}
$saved = $service->save( $id, 1, 'Advanced draft edited', ActivationState::Disabled, $v2, 41 );
if ( ! $saved->was_saved() || null === $saved->persisted_receipt()
	|| '2' !== $saved->persisted_receipt()['revision'] || 1 !== $db->updates ) {
	throw new RuntimeException( 'V2 draft Save/receipt did not complete.' );
}
$stale = $service->save( $id, 1, 'Stale edit', ActivationState::Disabled, $v2, 41 );
if ( $stale->was_saved() || $db->updates !== 1 ) {
	throw new RuntimeException( 'V2 CAS conflict was ignored.' );
}
$v1 = Definition::from_values( null, [], [], [] );
if ( ! $v1 instanceof Definition ) throw new RuntimeException( 'V1 fixture failed.' );
$cross = $service->save( $id, 2, 'Unsafe switch', ActivationState::Disabled, $v1, 41 );
if ( $cross->was_saved() || $db->updates !== 1 ) {
	throw new RuntimeException( 'V1/V2 Save switch was accepted.' );
}
if ( [] !== WorkflowRepository::list_enabled_for_trigger( str_repeat( 'a', 64 ) ) ) {
	throw new RuntimeException( 'Advanced draft reached Standard trigger query.' );
}
try {
	WorkflowRepository::update( $id, 2, 'Bad', ActivationState::Enabled, $v2, 0, 41 );
	throw new RuntimeException( 'Direct v2 Repository activation unexpectedly succeeded.' );
} catch ( InvalidArgumentException ) {
	// Expected: repository defense-in-depth.
}
echo "ADV-02A draft repository, CAS, receipt and activation isolation: PASS\n";
