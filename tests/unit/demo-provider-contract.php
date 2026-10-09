<?php
declare(strict_types=1);

namespace CoreBlueprint\Core {
	final class ExtensionRegistry {
		/** @var array<int,array<string,mixed>> */
		public static array $definitions = [];

		/** @param array<string,mixed> $definition */
		public static function register( array $definition ): bool {
			self::$definitions[] = $definition;
			return true;
		}
	}
}

namespace CoreBlueprint\Core\Automation {
	final class TriggerRegistry {
		/** @var array<int,array<string,mixed>> */
		public static array $definitions = [];

		/** @param array<string,mixed> $definition */
		public static function register( array $definition ): bool {
			self::$definitions[] = $definition;
			return true;
		}
	}

	final class StateRegistry {
		/** @var array<int,array<string,mixed>> */
		public static array $definitions = [];

		/** @param array<string,mixed> $definition */
		public static function register( array $definition ): bool {
			self::$definitions[] = $definition;
			return true;
		}
	}

	final class ActionRegistry {
		/** @var array<int,array<string,mixed>> */
		public static array $definitions = [];

		/** @param array<string,mixed> $definition */
		public static function register( array $definition ): bool {
			self::$definitions[] = $definition;
			return true;
		}
	}
}

namespace {
	use CoreBlueprint\Core\Automation\ActionRegistry;
	use CoreBlueprint\Core\Automation\StateRegistry;
	use CoreBlueprint\Core\Automation\TriggerRegistry;
	use CoreBlueprint\Core\ExtensionRegistry;

	define( 'ABSPATH', __DIR__ . '/' );

	/** @var array<string,array<int,callable>> $demo_hooks */
	$demo_hooks = [];

	function add_action( string $hook, callable $callback ): bool {
		global $demo_hooks;
		$demo_hooks[ $hook ][] = $callback;
		return true;
	}

	function plugin_basename( string $file ): string {
		return basename( $file );
	}

	function assert_demo( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	}

	require dirname( __DIR__ ) . '/fixtures/core-blueprint-automations-demo-provider.php';

	foreach ( $demo_hooks['core_blueprint_register_extensions'] ?? [] as $callback ) {
		$callback();
	}
	foreach ( $demo_hooks['core_blueprint_register_automation_capabilities'] ?? [] as $callback ) {
		$callback();
	}

	assert_demo( 1 === count( ExtensionRegistry::$definitions ), 'Demo provider must register exactly one extension identity.' );
	assert_demo(
		'cb-automations-fixture' === ( ExtensionRegistry::$definitions[0]['id'] ?? null ),
		'Demo provider extension identity changed unexpectedly.'
	);
	assert_demo( 1 === count( TriggerRegistry::$definitions ), 'Demo provider must register exactly one trigger.' );
	assert_demo( 1 === count( StateRegistry::$definitions ), 'Demo provider must register exactly one state capability.' );
	assert_demo( 1 === count( ActionRegistry::$definitions ), 'Demo provider must register exactly one action.' );

	$id_pattern      = '/^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9]*)+$/D';
	$version_pattern = '/^[1-9][0-9]*$/D';

	foreach (
		[
			'trigger' => TriggerRegistry::$definitions[0],
			'state'   => StateRegistry::$definitions[0],
			'action'  => ActionRegistry::$definitions[0],
		] as $kind => $definition
	) {
		$id      = (string) ( $definition['id'] ?? '' );
		$version = (string) ( $definition['schema_version'] ?? '' );
		assert_demo( 1 === preg_match( $id_pattern, $id ), sprintf( 'Demo %s id does not satisfy the Base Automation Foundation contract: %s', $kind, $id ) );
		assert_demo( 1 === preg_match( $version_pattern, $version ), sprintf( 'Demo %s schema version is invalid: %s', $kind, $version ) );
		assert_demo( 'cb-automations-fixture' === ( $definition['provider'] ?? null ), sprintf( 'Demo %s provider identity is inconsistent.', $kind ) );
	}

	assert_demo(
		'followup.create' === ( ActionRegistry::$definitions[0]['id'] ?? null ),
		'Demo action must retain the Base-valid followup.create capability id.'
	);

	fwrite( STDOUT, "demo-provider-contract: PASS\n" );
}
