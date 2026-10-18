<?php
declare(strict_types=1);
require __DIR__ . '/workflow-core.php';

use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityKind;
use CB\Automations\Template\TemplateSafety;
use CB\Automations\Workflow\DefinitionCodec;
use CB\Automations\Workflow\ActivationState;

// Standalone safe domain test: no WP database, plugin activation or fixtures.
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $data ): string|false { return json_encode( $data ); }
}
$unsafe = definition(
	step( 'trigger_1', reference( CapabilityKind::TRIGGER, 'core-blueprint-automations', 'user.registered' ) ),
	[],
	[ condition( 'condition_1', Binding::step_output( 'trigger_1', 'user_email' ), 'equals', Binding::literal( 'customer@example.com' ) ) ],
	[
		step( 'action_1', reference( CapabilityKind::ACTION, 'core-blueprint-automations', 'mail.send' ), [
			'to' => Binding::literal( 'private@example.com' ),
			'subject' => Binding::literal( 'secret token' ),
			'message' => Binding::step_output( 'trigger_1', 'user_email' ),
		] ),
	]
);
$before = DefinitionCodec::encode( $unsafe );
$after = DefinitionCodec::encode( TemplateSafety::scrub( $unsafe ) );
assert_true( isset( $before['actions'][0]['bindings']['to'] ), 'Fixture must include sensitive literal.' );
assert_true( ! isset( $after['actions'][0]['bindings']['to'] ), 'Recipient literal must be removed.' );
assert_true( ! isset( $after['actions'][0]['bindings']['subject'] ), 'Subject literal must be removed.' );
assert_true( isset( $after['actions'][0]['bindings']['message'] ), 'Step-output references remain usable.' );
assert_true( [] === $after['conditions'], 'Literal-driven conditions are dropped, not leaked.' );
assert_true( false === str_contains( (string) wp_json_encode( $after ), 'private@example.com' ), 'Export must contain no recipient.' );
assert_true( false === str_contains( (string) wp_json_encode( $after ), 'secret token' ), 'Export must contain no secret.' );
assert_true( DefinitionCodec::encode( TemplateSafety::scrub( TemplateSafety::scrub( $unsafe ) ) ) === $after, 'Scrubbing must be idempotent.' );

$root = dirname( __DIR__, 2 );
$files = [
	'store' => 'src/Template/StoredTemplateRepository.php',
	'bridge' => 'src/Template/TemplateLibraryBridge.php',
	'source' => 'src/Template/TemplateSourceAdapter.php',
	'controller' => 'src/Admin/TemplateManagementController.php',
	'worker' => 'src/Admin/WorkflowController.php',
	'trigger' => 'src/Provider/WordPress/TriggerListeners.php',
	'plugin' => 'src/Plugin.php',
];
$code = [];
foreach ( $files as $id => $path ) {
	$code[ $id ] = (string) file_get_contents( $root . '/' . $path );
	assert_true( '' !== $code[ $id ], "Missing source: $path" );
}
assert_true( str_contains( $code['store'], "'show_in_rest' => false" ) && str_contains( $code['store'], "'public' => false" ), 'Template CPT must be private and REST-inert.' );
assert_true( str_contains( $code['store'], 'wp_slash( $data )' ), 'JSON must be slashed before insertion.' );
assert_true( str_contains( $code['store'], "'post_status' => 'private'" ), 'Stored template must never be published.' );
assert_true( str_contains( $code['store'], '$workflow->created_by() !== $actor' ), 'Personal saving must enforce source ownership.' );
assert_true( str_contains( $code['store'], "'personal'" ) && str_contains( $code['store'], "'site'" ), 'Both scopes must be explicit.' );
assert_true( str_contains( $code['controller'], 'wp_verify_nonce' ) && str_contains( $code['controller'], 'current_user_can' ), 'All mutations require capability and nonce.' );
assert_true( str_contains( $code['controller'], 'TemplateSafety' ) === false && str_contains( $code['controller'], 'StoredTemplateRepository::definition' ), 'Export must load a scrubbed copy.' );
assert_true( str_contains( $code['trigger'], 'StoredTemplateRepository::TYPE' ), 'Internal template post events must not trigger automation workflows.' );
assert_true( str_contains( $code['worker'], 'TemplateSourceAdapter::SOURCE_ID === $source' ), 'Selected metadata must not override source identity.' );
assert_true( str_contains( $code['worker'], 'StoredTemplateRepository::definition(' ), 'Create must re-read authenticated stored templates.' );
assert_true( str_contains( $code['bridge'], 'TemplateSourceRegistry::register( new TemplateSourceAdapter() )' ), 'Browser provider must use Base registry.' );
assert_true( str_contains( $code['source'], 'TemplateCategorySourceInterface' ), 'Provider must implement optional Base category facets.' );
assert_true( str_contains( $code['source'], 'StoredTemplateRepository::search( $user_id' ), 'Saved custom category must be visible to the requesting actor.' );
assert_true( str_contains( $code['source'], '$this->can_browse( $user_id )' ), 'Category facets must enforce browse authority.' );
assert_true( str_contains( $code['plugin'], 'StoredTemplateRepository::register();' ), 'Private storage must register.' );
assert_true( str_contains( $code['plugin'], 'TemplateLibraryBridge::init();' ), 'Provider must be booted before Base collects its sources.' );
assert_true( str_contains( $rootfile = (string) file_get_contents( $root . '/src/Persistence/WorkflowRepository.php' ), 'ActivationState::Disabled->value' ), 'Instantiated workflows must default to DISABLED.' );
assert_true( str_contains( $rootfile, "'execution_principal_user_id' => 0" ), 'New workflow must not inherit a principal.' );
fwrite( STDOUT, "Automations Template Library T3B source/privacy: PASS\n" );
