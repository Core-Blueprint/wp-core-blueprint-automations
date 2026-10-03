<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$plugin = file_get_contents( $root . '/src/Plugin.php' );
$requirements = file_get_contents( $root . '/src/Support/Requirements.php' );
$suite = file_get_contents( $root . '/src/Integration/Suite.php' );
$bootstrap = file_get_contents( $root . '/core-blueprint-automations.php' );

if ( false === $plugin || false === $requirements || false === $suite || false === $bootstrap ) {
	fwrite( STDERR, "Could not read AU2.0A bootstrap sources.\n" );
	exit( 1 );
}

$failures = [];

$runtime_gate = strpos( $plugin, 'Requirements::product_ready()' );
$runtime_boot = strpos( $plugin, 'self::$runtime_booted = true;' );
$admin_gate = strpos( $plugin, "if ( ! is_admin() || ! Requirements::admin_ready() )" );
$admin_init = strpos( $plugin, 'WorkflowController::init();' );

if ( false === $runtime_gate || false === $runtime_boot || false === $admin_gate || false === $admin_init ) {
	$failures[] = 'Plugin boot does not expose the expected runtime/admin split.';
} elseif ( ! ( $runtime_gate < $runtime_boot && $runtime_boot < $admin_gate && $admin_gate < $admin_init ) ) {
	$failures[] = 'Runtime must boot before the admin-only gate and controllers.';
}

if ( str_contains( substr( $plugin, 0, false === $runtime_boot ? strlen( $plugin ) : $runtime_boot ), '! is_admin()' ) ) {
	$failures[] = 'Runtime boot is still blocked by an admin-only early return.';
}

foreach ( [
	'TriggerEvent' => 'CB\\Core\\Automation\\TriggerEvent',
	'InvocationContext' => 'CB\\Core\\Automation\\InvocationContext',
	'ActionInvoker' => 'CB\\Core\\Automation\\ActionInvoker',
	'StateInvoker' => 'CB\\Core\\Automation\\StateInvoker',
] as $basename => $contract ) {
	if ( ! str_contains( $requirements, $basename ) ) {
		$failures[] = 'Missing runtime Base contract requirement: ' . $contract;
	}
}

if ( ! str_contains( $requirements, 'public static function product_ready(): bool' ) ) {
	$failures[] = 'Product runtime readiness is not separated from Bootstrap readiness.';
}
if ( ! str_contains( $requirements, 'public static function admin_ready(): bool' ) ) {
	$failures[] = 'Admin readiness is not separated from product runtime readiness.';
}
if ( ! str_contains( $suite, 'Requirements::admin_ready()' ) ) {
	$failures[] = 'Core Admin page registration is not gated by admin readiness.';
}
if ( ! str_contains( $bootstrap, 'Requirements::admin_ready()' ) ) {
	$failures[] = 'Admin notices are not gated by admin readiness.';
}
if ( str_contains( $plugin, 'core_blueprint_automation_trigger_emitted' ) ) {
	$failures[] = 'AU2.0A must not subscribe to trigger delivery.';
}

if ( [] !== $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, $failure . PHP_EOL );
	}
	exit( 1 );
}

fwrite( STDOUT, "Runtime bootstrap boundary: PASS\n" );
