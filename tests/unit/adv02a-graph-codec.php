<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\Automations\\';
	if ( ! str_starts_with( $class, $prefix ) ) return;
	$file = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
	if ( is_file( $file ) ) require_once $file;
} );

use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\DefinitionCodec;
use CB\Automations\Workflow\DefinitionDocumentCodec;
use CB\Automations\Workflow\GraphDefinition;
use CB\Automations\Workflow\GraphDefinitionCodec;

function check_graph( bool $result, string $why ): void {
	if ( ! $result ) throw new RuntimeException( $why );
}
function must_reject_graph( array $data, string $why ): void {
	try {
		DefinitionDocumentCodec::decode( $data );
	} catch ( UnexpectedValueException $expected ) {
		return;
	}
	throw new RuntimeException( $why );
}

$v1 = Definition::from_values( null, [], [], [] );
check_graph( $v1 instanceof Definition, 'Standard v1 fixture.' );
check_graph( DefinitionDocumentCodec::encode( $v1 ) === DefinitionCodec::encode( $v1 ), 'V1 bytes changed.' );
check_graph( DefinitionDocumentCodec::decode( DefinitionCodec::encode( $v1 ) ) instanceof Definition, 'V1 dispatch changed.' );
$v2 = [
	'definition_version' => 2, 'mode' => 'advanced',
	'graph' => [ 'nodes' => [], 'edges' => [] ],
	'layout' => [ 'nodes' => [] ],
];
$decoded = DefinitionDocumentCodec::decode( $v2 );
check_graph( $decoded instanceof GraphDefinition, 'V2 class mismatch.' );
check_graph( DefinitionDocumentCodec::encode( $decoded ) === $v2, 'V2 canonical roundtrip.' );
check_graph( GraphDefinitionCodec::decode( $v2 ) instanceof GraphDefinition, 'Strict codec missing.' );

$bad = $v2;
$bad['definition_version'] = 3;
must_reject_graph( $bad, 'Version 3 accepted.' );
$bad = $v2;
$bad['states'] = [];
must_reject_graph( $bad, 'Unexpected v1 field accepted.' );
$bad = $v2;
$bad['graph']['nodes'] = [ [ 'id' => 'start', 'type' => 'trigger', 'config' => [] ], [ 'id' => 'start', 'type' => 'end', 'config' => [] ] ];
must_reject_graph( $bad, 'Duplicate node accepted.' );
$bad = $v2;
$bad['graph']['nodes'] = [ [ 'id' => 'n1', 'type' => 'end', 'config' => [] ] ];
$bad['layout']['nodes'] = [ 'n1' => [ 'x' => INF, 'y' => 1 ] ];
must_reject_graph( $bad, 'Infinite layout accepted.' );

echo "ADV-02A graph definition codec: PASS\n";
