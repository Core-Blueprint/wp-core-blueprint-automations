<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$paths = [
	'page' => 'src/Admin/AutomationsPage.php',
	'create' => 'src/Admin/WorkflowController.php',
	'workspace' => 'templates/admin/automations.php',
	'asset' => 'src/Admin/DesignerAssets.php',
	'overview_assets' => 'src/Admin/AdminAssets.php',
	'advanced' => 'src/Admin/AdvancedEditorData.php',
	'template' => 'templates/admin/advanced-workflow-editor.php',
	'js' => 'assets/js/admin-advanced-editor.js',
	'standard' => 'src/Workflow/WorkflowService.php',
	'layout' => 'assets/css/admin-advanced-editor.css',
];
$code = [];
foreach ( $paths as $key => $path ) {
	$source = file_get_contents( $root . '/' . $path );
	if ( ! is_string( $source ) ) {
		throw new RuntimeException( 'Missing ADV-03A source: ' . $path );
	}
	$code[ $key ] = $source;
}
$required = [
	'page' => [ 'advanced-workflow-editor.php', 'AdvancedEditorData::build(', 'workflow-editor.php' ],
	'create' => [ "'mode'", "'advanced' === \$mode", 'GraphDefinition::from_values(', 'GraphDefinitionCodec::decode(', 'self::guard( \'cb_automations_save_workflow\' )' ],
	'workspace' => [ '<option value="standard">', '<option value="advanced">', 'name="mode"', "'Edit', 'core-blueprint-automations'" ],
	'asset' => [ 'admin-advanced-editor.css', 'admin-advanced-editor.js', 'instanceof \\CB\\Automations\\Workflow\\GraphDefinition' ],
	'overview_assets' => [ 'Advanced UI has its own typed authoring surface', 'instanceof \\CB\\Automations\\Workflow\\GraphDefinition' ],
	'advanced' => [ 'EditorIntegerSafety::is_browser_safe(', "'literal'", "'sensitive'", 'GraphDefinitionCodec::encode(' ],
	'template' => [ 'data-cb-advanced-form', 'data-cb-advanced-definition', 'name="activation_state" value="disabled"', 'data-cb-advanced-scene', 'wp_nonce_field( \'cb_automations_save_workflow\'', 'JSON_HEX_TAG', 'Workflow health' ],
	'js' => [ 'crypto.randomUUID()', 'data-cb-advanced-add', 'documentField.value = JSON.stringify(draft)', 'canConnect(', 'setPointerCapture', 'changed && !saving' ],
	'standard' => [ 'get_class( $definition ) !== get_class( $current->definition() )', 'ActivationState::Disabled !== $activation_state' ],
];
foreach ( $required as $file => $needles ) {
	foreach ( $needles as $needle ) {
		if ( ! str_contains( $code[ $file ], $needle ) ) {
			throw new RuntimeException( 'ADV-03A invariant missing: ' . $file . ':' . $needle );
		}
	}
}
if ( str_contains( $code['js'], 'GraphSandboxAdmission' )
	|| str_contains( $code['js'], 'wp-config.php' )
	|| str_contains( $code['template'], 'name="rebind_execution_principal"' )
	|| str_contains( $code['template'], 'value="enabled"' ) ) {
	throw new RuntimeException( 'Advanced UI crossed the runtime activation boundary.' );
}
if ( strpos( $code['page'], 'AdvancedEditorData::build(' ) > strpos( $code['page'], 'WorkflowEditorData::build(' ) ) {
	throw new RuntimeException( 'Advanced authoring dispatch occurs after Standard.' );
}
echo "ADV-03A native WordPress create/save/reopen and v1/v2 isolation: PASS\n";