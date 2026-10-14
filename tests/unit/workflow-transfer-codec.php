<?php
declare(strict_types=1);

require __DIR__ . '/workflow-core.php';

use CB\Automations\Capability\CapabilityKind;
use CB\Automations\Transfer\WorkflowTransferCodec;
use CB\Automations\Workflow\DefinitionCodec;

$definition = definition(
	step( 'trigger_1', reference( CapabilityKind::TRIGGER, 'core-blueprint-automations', 'post.created' ) ),
	[],
	[],
	[
		step( 'action_1', reference( CapabilityKind::ACTION, 'core-blueprint-automations', 'post.create' ), [
			'post_type' => \CB\Automations\Binding\Binding::literal( 'post' ),
			'post_title' => \CB\Automations\Binding\Binding::literal( 'Test ghost' ),
		] ),
	]
);
$raw = json_encode( DefinitionCodec::encode( $definition ), JSON_THROW_ON_ERROR );
$loaded = WorkflowTransferCodec::import_json( $raw, 'cb-indirect-loop-A.json' );
assert_true( 'cb-indirect-loop-A' === $loaded['name'], 'Standalone JSON import must derive its local name.' );
assert_true( $definition->to_array() === $loaded['definition']->to_array(), 'Standalone imports must decode the canonical workflow definition.' );

$wrapped = json_encode( [
	'format' => WorkflowTransferCodec::FORMAT, 'format_version' => WorkflowTransferCodec::FORMAT_VERSION,
	'name' => 'Safe transfer', 'definition' => $definition->to_array(),
], JSON_THROW_ON_ERROR );
assert_true( 'Safe transfer' === WorkflowTransferCodec::import_json( $wrapped )['name'], 'Portable export manifest must round-trip.' );
$invalid = [
	$wrapped . ' garbage',
	json_encode( [ 'format' => WorkflowTransferCodec::FORMAT, 'format_version' => 2, 'name' => 'No', 'definition' => $definition->to_array() ], JSON_THROW_ON_ERROR ),
	json_encode( [ 'format' => WorkflowTransferCodec::FORMAT, 'format_version' => 1, 'name' => 'Bad', 'definition' => $definition->to_array(), 'principal' => 1 ], JSON_THROW_ON_ERROR ),
	json_encode( [ 'format' => WorkflowTransferCodec::FORMAT, 'format_version' => 1, 'name' => '   ', 'definition' => $definition->to_array() ], JSON_THROW_ON_ERROR ),
	str_repeat( 'a', WorkflowTransferCodec::MAX_FILE_BYTES + 1 ),
];
foreach ( $invalid as $file ) {
	try { WorkflowTransferCodec::import_json( $file ); } catch ( \Throwable ) { continue; }
	throw new \RuntimeException( 'Hostile or incompatible transfer format was accepted.' );
}

$root = dirname( __DIR__, 2 );
$controller = file_get_contents( $root . '/src/Admin/WorkflowTransferController.php' );
$plugin = file_get_contents( $root . '/src/Plugin.php' );
$view = file_get_contents( $root . '/templates/admin/automations.php' );
if ( ! is_string( $controller ) || ! is_string( $plugin ) || ! is_string( $view ) ) {
	throw new \RuntimeException( 'Missing transfer integrations.' );
}
foreach ( [
	[ $controller, 'Vault::seal(' ],
	[ $controller, 'Vault::open(' ],
	[ $controller, 'is_uploaded_file(' ],
	[ $controller, 'current_user_can(' ],
	[ $controller, 'wp_verify_nonce(' ],
	[ $controller, 'delete_transient(' ],
	[ $controller, "WorkflowTransferCodec::export_json" ],
	[ $controller, "cb_automations_import_confirm" ],
	[ $plugin, 'WorkflowTransferController::init();' ],
	[ $view, 'Create disabled workflow' ],
	[ $view, 'Export JSON' ],
] as [ $content, $needle ] ) {
	if ( ! str_contains( $content, $needle ) ) {
		throw new \RuntimeException( 'Transfer guard integration missing: ' . $needle );
	}
}
fwrite( STDOUT, "Offline workflow JSON Import / Export contracts: PASS\n" );
