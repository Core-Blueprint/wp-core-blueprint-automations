<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

final class WP_User {
	public function __construct( public int $ID ) {}
}

$GLOBALS['cb_test_users'] = [];
$GLOBALS['cb_test_caps'] = [];

function get_userdata( int $user_id ): WP_User|false {
	return $GLOBALS['cb_test_users'][ $user_id ] ?? false;
}

function user_can( int $user_id, string $capability ): bool {
	return true === ( $GLOBALS['cb_test_caps'][ $user_id ][ $capability ] ?? false );
}

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\Automations\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$file = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );

use CB\Automations\Capability\CapabilityKind;
use CB\Automations\Capability\CapabilityReference;
use CB\Automations\Discovery\CapabilityDefinition;
use CB\Automations\Discovery\CapabilitySource;
use CB\Automations\Discovery\ProviderStatus;
use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\ExecutionAuthorityDecision;
use CB\Automations\Workflow\ExecutionPrincipalPolicy;
use CB\Automations\Workflow\Step;

final class AuthoritySource implements CapabilitySource {
	public function __construct( private ?CapabilityDefinition $definition ) {}

	public function current( CapabilityReference $reference ): ?CapabilityDefinition {
		return $this->definition;
	}

	public function provider_status( string $provider ): ProviderStatus {
		return ProviderStatus::from_inventory( $provider, null );
	}
}

function authority_definition(): Definition {
	$reference = CapabilityReference::from_values(
		CapabilityKind::ACTION,
		'core-blueprint-work',
		'project.create',
		'1'
	);
	if ( null === $reference ) {
		throw new RuntimeException( 'Could not build authority fixture reference.' );
	}
	$step = Step::from_values( 'action_1', $reference );
	if ( null === $step ) {
		throw new RuntimeException( 'Could not build authority fixture step.' );
	}
	$definition = Definition::from_values( null, [], [], [ $step ] );
	if ( null === $definition ) {
		throw new RuntimeException( 'Could not build authority fixture definition.' );
	}
	return $definition;
}

function authority_capability(): CapabilityDefinition {
	$definition = CapabilityDefinition::from_base_definition(
		CapabilityKind::ACTION,
		[
			'provider'            => 'core-blueprint-work',
			'id'                  => 'project.create',
			'label'               => 'Create project',
			'description'         => '',
			'schema_version'      => '1',
			'input_schema'        => [],
			'output_schema'       => [],
			'required_capability' => 'edit_posts',
		]
	);
	if ( null === $definition ) {
		throw new RuntimeException( 'Could not build authority fixture capability.' );
	}
	return $definition;
}

function assert_reason( ExecutionAuthorityDecision $decision, ?string $reason, string $message ): void {
	if ( $decision->reason() !== $reason ) {
		throw new RuntimeException( $message . ' Expected ' . var_export( $reason, true ) . ', got ' . var_export( $decision->reason(), true ) . '.' );
	}
}

$workflow = authority_definition();
$source = new AuthoritySource( authority_capability() );

assert_reason(
	ExecutionPrincipalPolicy::evaluate( $workflow, 0, 20, $source ),
	ExecutionAuthorityDecision::PRINCIPAL_MISSING,
	'Missing principal must fail closed.'
);

$GLOBALS['cb_test_users'][20] = new WP_User( 20 );
$GLOBALS['cb_test_caps'][20]['edit_posts'] = true;
assert_reason(
	ExecutionPrincipalPolicy::evaluate( $workflow, 10, 20, $source ),
	ExecutionAuthorityDecision::PRINCIPAL_INVALID,
	'Unknown principal must fail closed.'
);

$GLOBALS['cb_test_users'][10] = new WP_User( 10 );
assert_reason(
	ExecutionPrincipalPolicy::evaluate( $workflow, 10, 20, $source ),
	ExecutionAuthorityDecision::PRINCIPAL_PERMISSION_DENIED,
	'Principal without provider capability must fail closed.'
);

$GLOBALS['cb_test_caps'][10]['edit_posts'] = true;
$GLOBALS['cb_test_caps'][20]['edit_posts'] = false;
assert_reason(
	ExecutionPrincipalPolicy::evaluate( $workflow, 10, 20, $source ),
	ExecutionAuthorityDecision::OPERATOR_PERMISSION_DENIED,
	'Operator without provider capability must not enable authority held by another user.'
);

$GLOBALS['cb_test_caps'][20]['edit_posts'] = true;
$allowed = ExecutionPrincipalPolicy::evaluate( $workflow, 10, 20, $source );
if ( ! $allowed->is_allowed() || null !== $allowed->reason() ) {
	throw new RuntimeException( 'Authorized principal/operator pair should be accepted.' );
}

assert_reason(
	ExecutionPrincipalPolicy::evaluate( $workflow, 10, 20, new AuthoritySource( null ) ),
	ExecutionAuthorityDecision::CAPABILITY_UNAVAILABLE,
	'Missing live capability metadata must fail closed.'
);

$root = dirname( __DIR__, 2 );
$controller = file_get_contents( $root . '/src/Admin/WorkflowController.php' );
$template = file_get_contents( $root . '/templates/admin/workflow-editor.php' );
$service = file_get_contents( $root . '/src/Workflow/WorkflowService.php' );
if ( false === $controller || false === $template || false === $service ) {
	throw new RuntimeException( 'Could not read execution-authority boundary sources.' );
}

if ( str_contains( $controller, "\$_POST['execution_principal_user_id']" ) ) {
	throw new RuntimeException( 'Controller must never accept a browser-supplied execution principal user ID.' );
}
if ( str_contains( $template, 'name="execution_principal_user_id"' ) ) {
	throw new RuntimeException( 'Builder must never expose an arbitrary Run as user field.' );
}
if ( ! str_contains( $controller, 'rebind_execution_principal' ) || ! str_contains( $controller, 'get_current_user_id()' ) ) {
	throw new RuntimeException( 'Principal rebinding must resolve the authenticated operator server-side.' );
}
if ( ! str_contains( $service, '? $user_id' ) || ! str_contains( $service, ': $current->execution_principal_user_id()' ) ) {
	throw new RuntimeException( 'Workflow service does not bind authority exclusively from server-side operator identity or stored authority.' );
}

fwrite( STDOUT, "Execution authority policy: PASS\n" );
