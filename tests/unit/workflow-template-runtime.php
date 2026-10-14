<?php
declare(strict_types=1);

// Exercise the real StarterTemplates + WorkflowTemplateRegistry lifecycle
// without WordPress boot. This catches silent template-ID rejection.
namespace CoreBlueprint\\Core {
	final class ExtensionRegistry {
		public static function is_valid_id( string $id ): bool {
			return 1 === preg_match( '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)+$/D', $id );
		}
		public static function definition( string $id ): ?array {
			return 'core-blueprint-automations' === $id ? [ 'id' => $id ] : null;
		}
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['cb_automations_test_hooks'] = [];
	$GLOBALS['cb_automations_test_current_hook'] = '';
	function __( string $value, string $domain = '' ): string { return $value; }
	function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); }
	function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( $value ) ); }
	function add_action( string $hook, callable $callback ): void {
		$GLOBALS['cb_automations_test_hooks'][ $hook ][] = $callback;
	}
	function doing_action( string $hook ): bool {
		return $GLOBALS['cb_automations_test_current_hook'] === $hook;
	}
	function did_action( string $hook ): int { return 'init' === $hook ? 1 : 0; }
	function do_action( string $hook ): void {
		$previous = $GLOBALS['cb_automations_test_current_hook'];
		$GLOBALS['cb_automations_test_current_hook'] = $hook;
		try {
			foreach ( $GLOBALS['cb_automations_test_hooks'][ $hook ] ?? [] as $callback ) {
				$callback();
			}
		} finally {
			$GLOBALS['cb_automations_test_current_hook'] = $previous;
		}
	}
	spl_autoload_register( static function ( string $class ): void {
		$prefix = 'CB\\\\Automations\\\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$path = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $path ) ) {
			require_once $path;
		}
	} );
	\\CB\\Automations\\Template\\StarterTemplates::init();
	$templates = \\CB\\Automations\\Template\\WorkflowTemplateRegistry::all();
	$actual = array_map( static fn ( $template ): string => $template->id(), $templates );
	$expected = [ 'wordpress.welcome.user', 'wordpress.notify.post.author', 'wordpress.acknowledge.comment' ];
	sort( $actual );
	sort( $expected );
	if ( $actual !== $expected ) {
		fwrite( STDERR, 'Starter template registration failed: ' . json_encode( $actual ) . PHP_EOL );
		exit( 1 );
	}
	foreach ( $templates as $template ) {
		$definition = $template->definition()->to_array();
		if ( null === $definition['trigger'] || count( $definition['actions'] ) < 1 ) {
			fwrite( STDERR, 'Invalid starter template workflow: ' . $template->id() . PHP_EOL );
			exit( 1 );
		}
		$lookup = \\CB\\Automations\\Template\\WorkflowTemplateRegistry::get( $template->provider(), $template->id() );
		if ( $lookup !== $template ) {
			fwrite( STDERR, 'Starter template not retrievable: ' . $template->id() . PHP_EOL );
			exit( 1 );
		}
	}
	fwrite( STDOUT, 'WordPress starter template runtime: PASS (3 registered, decoded and retrievable)' . PHP_EOL );
}
