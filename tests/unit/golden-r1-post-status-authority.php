<?php
declare(strict_types=1);

/**
 * Golden-R1: actual WordPress action permission decisions, not SOURCE string matching.
 * Isolated WordPress fakes; no database mutations or external requests.
 */
namespace CoreBlueprint\Core\Automation {
	final class InvocationContext {
		public function __construct( private int $principal ) {}
		public function principal_user_id(): int { return $this->principal; }
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	class WP_Error {
		public function __construct( private string $code, private string $message ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
	class WP_Post {
		public function __construct( public int $ID, public string $post_type = 'post' ) {}
	}

	$GLOBALS['r1_caps'] = [ 'edit_posts' => true, 'edit_post' => true, 'publish_posts' => false ];
	$GLOBALS['r1_writes'] = [];
	function user_can( int $user, string $cap, mixed ...$args ): bool {
		unset( $args );
		return 7 === $user && ( $GLOBALS['r1_caps'][ $cap ] ?? false );
	}
	function get_post_type_object( string $type ): ?object {
		return 'post' === $type
			? (object) [ 'cap' => (object) [ 'create_posts' => 'edit_posts', 'publish_posts' => 'publish_posts' ] ]
			: null;
	}
	function get_post( int $id ): ?WP_Post { return 12 === $id ? new WP_Post( 12 ) : null; }
	function get_post_status_object( string $status ): ?object {
		return in_array( $status, [ 'draft', 'pending', 'publish', 'future', 'private', 'trash', 'custom_review' ], true )
			? (object) [ 'name' => $status ] : null;
	}
	function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
	function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $value ) ) ?? ''; }
	function wp_slash( array $value ): array { return $value; }
	function wp_insert_post( array $data, bool $wp_error ): int {
		$GLOBALS['r1_writes'][] = [ 'create', $data, $wp_error ];
		return 12;
	}
	function wp_update_post( array $data, bool $wp_error ): int {
		$GLOBALS['r1_writes'][] = [ 'update', $data, $wp_error ];
		return 12;
	}
	function expect( bool $condition, string $message ): void {
		if ( ! $condition ) { throw new \RuntimeException( $message ); }
	}

	require dirname( __DIR__, 2 ) . '/src/Provider/WordPress/WordPressAction.php';

	$action = \CB\Automations\Provider\WordPress\WordPressAction::class;
	$actor = new \CoreBlueprint\Core\Automation\InvocationContext( 7 );
	$create = static fn ( string $status ) => $action::create_post(
		[ 'post_type' => 'post', 'post_title' => 'Example', 'post_status' => $status ], $actor
	);
	$change = static fn ( string $status ) => $action::change_post_status(
		[ 'post_id' => 12, 'post_status' => $status ], $actor
	);
	$deny_without_write = static function (callable $call, string $code): void {
		$before = count( $GLOBALS['r1_writes'] );
		$result = $call();
		expect( $result instanceof WP_Error && $result->get_error_code() === $code, 'Unexpected authorization result: ' . $code );
		expect( count( $GLOBALS['r1_writes'] ) === $before, 'Denied status unexpectedly mutated a post.' );
	};

	// Normal authoring must continue to work without publish authority.
	expect( isset( $create( 'draft' )['post_id'] ), 'Draft creation must remain available.' );
	expect( [] === $change( 'pending' ), 'Pending status must remain available with edit authority.' );

	// Elevated statuses require the post type's publish capability, for both paths.
	foreach ( [ 'publish', 'future', 'private' ] as $status ) {
		$deny_without_write( static fn () => $create( $status ), 'cb_automations_wordpress_permission_denied' );
		$deny_without_write( static fn () => $change( $status ), 'cb_automations_wordpress_permission_denied' );
	}

	// Internal and third-party statuses lack an audited authority contract.
	foreach ( [ 'trash', 'custom_review', 'unregistered' ] as $status ) {
		$deny_without_write( static fn () => $create( $status ), 'cb_automations_wordpress_invalid_input' );
		$deny_without_write( static fn () => $change( $status ), 'cb_automations_wordpress_invalid_input' );
	}

	$GLOBALS['r1_caps']['publish_posts'] = true;
	foreach ( [ 'publish', 'future', 'private' ] as $status ) {
		expect( isset( $create( $status )['post_id'] ), 'Authorized status creation failed: ' . $status );
		expect( [] === $change( $status ), 'Authorized status change failed: ' . $status );
	}
	$GLOBALS['r1_caps']['edit_post'] = false;
	$deny_without_write( static fn () => $change( 'draft' ), 'cb_automations_wordpress_permission_denied' );
	$GLOBALS['r1_caps']['edit_posts'] = false;
	$deny_without_write( static fn () => $create( 'draft' ), 'cb_automations_wordpress_permission_denied' );

	fwrite( STDOUT, "Golden-R1 WordPress post status authority: PASS\n" );
}
