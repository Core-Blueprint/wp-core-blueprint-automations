<?php
declare(strict_types=1);

namespace {
	if ( ! defined( 'ABSPATH' ) ) {
		define( 'ABSPATH', __DIR__ . '/' );
	}
	if ( ! function_exists( '__' ) ) {
		function __( string $text, string $domain = 'default' ): string {
			unset( $domain );
			return $text;
		}
	}
}

namespace CB\Core\Automation {
	final class TriggerRegistry {
		public static array $items = [];
		public static function register( array $definition ): bool {
			self::$items[] = $definition;
			return true;
		}
	}
	final class StateRegistry {
		public static array $items = [];
		public static function register( array $definition ): bool {
			self::$items[] = $definition;
			return true;
		}
	}
	final class ActionRegistry {
		public static array $items = [];
		public static function register( array $definition ): bool {
			self::$items[] = $definition;
			return true;
		}
	}
}

namespace {
	$root = dirname( __DIR__, 2 );
	require $root . '/src/Provider/WordPress/CapabilityRegistrar.php';

	\CB\Automations\Provider\WordPress\CapabilityRegistrar::register();

	$failures = [];
	$triggers = \CB\Core\Automation\TriggerRegistry::$items;
	$states = \CB\Core\Automation\StateRegistry::$items;
	$actions = \CB\Core\Automation\ActionRegistry::$items;

	if ( 34 !== count( $triggers ) ) {
		$failures[] = 'Expected 34 native WordPress triggers, got ' . count( $triggers ) . '.';
	}
	if ( 5 !== count( $states ) ) {
		$failures[] = 'Expected 5 native WordPress states, got ' . count( $states ) . '.';
	}
	if ( 18 !== count( $actions ) ) {
		$failures[] = 'Expected 18 native WordPress actions, got ' . count( $actions ) . '.';
	}

	$expected_triggers = [
		'user.registered','user.updated','user.deleted','user.role_changed','user.role_added','user.role_removed',
		'user.logged_in','user.logged_out','user.login_failed','user.password_reset',
		'post.created','post.updated','post.status_changed','post.published','post.trashed','post.restored','post.deleted',
		'comment.created','comment.updated','comment.status_changed','comment.trashed','comment.restored','comment.deleted',
		'attachment.created','attachment.updated','attachment.deleted',
		'term.created','term.updated','term.deleted','object.terms_changed',
		'plugin.activated','plugin.deactivated','theme.switched','update.completed',
	];
	$expected_states = [ 'user.current','post.current','comment.current','attachment.current','term.current' ];
	$expected_actions = [
		'user.update','user.add_role','user.remove_role',
		'post.create','post.update','post.change_status','post.trash','post.restore','post.delete',
		'comment.change_status','comment.trash','comment.restore','comment.delete',
		'term.create','term.update','term.delete','post.set_terms','mail.send',
	];

	$ids = static fn ( array $items ): array => array_values( array_map( static fn ( array $item ): string => (string) ( $item['id'] ?? '' ), $items ) );
	if ( $expected_triggers !== $ids( $triggers ) ) {
		$failures[] = 'Native WordPress trigger identity set drifted.';
	}
	if ( $expected_states !== $ids( $states ) ) {
		$failures[] = 'Native WordPress state identity set drifted.';
	}
	if ( $expected_actions !== $ids( $actions ) ) {
		$failures[] = 'Native WordPress action identity set drifted.';
	}

	foreach ( array_merge( $triggers, $states, $actions ) as $definition ) {
		if ( 'core-blueprint-automations' !== ( $definition['provider'] ?? null ) ) {
			$failures[] = 'WordPress capability provider identity drifted.';
			break;
		}
		if ( '1' !== ( $definition['schema_version'] ?? null ) ) {
			$failures[] = 'WordPress capability schema version drifted.';
			break;
		}
	}

	$by_id = static function ( array $items, string $id ): ?array {
		foreach ( $items as $item ) {
			if ( $id === ( $item['id'] ?? null ) ) {
				return $item;
			}
		}
		return null;
	};

	$user_registered = $by_id( $triggers, 'user.registered' );
	if ( 'wp.user_id' !== ( $user_registered['payload_schema']['user_id']['semantic_type'] ?? null ) ) {
		$failures[] = 'user.registered user_id semantic identity is missing.';
	}
	if ( true !== ( $user_registered['payload_schema']['user_email']['sensitive'] ?? null ) ) {
		$failures[] = 'user.registered email must remain sensitive.';
	}

	$mail = $by_id( $actions, 'mail.send' );
	if ( true !== ( $mail['input_schema']['to']['sensitive'] ?? null ) ) {
		$failures[] = 'mail.send recipient must remain sensitive.';
	}

	$comment_state = $by_id( $states, 'comment.current' );
	if ( 'moderate_comments' !== ( $comment_state['required_capability'] ?? null ) ) {
		$failures[] = 'comment.current must require moderation authority.';
	}

	if ( [] !== $failures ) {
		foreach ( $failures as $failure ) {
			fwrite( STDERR, $failure . PHP_EOL );
		}
		exit( 1 );
	}

	fwrite( STDOUT, "WordPress provider schemas: PASS\n" );
}
