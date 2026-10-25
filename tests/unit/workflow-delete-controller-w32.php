<?php
declare(strict_types=1);

namespace CB\Automations\Persistence {
	final class WorkflowRepository {
		public static array $calls = [];
		public static string $outcome = 'deleted';
		public static function delete_disabled_batch( array $expected ): string {
			self::$calls[] = $expected;
			if ( 'throw' === self::$outcome ) { throw new \RuntimeException( 'Uncertain COMMIT' ); }
			return self::$outcome;
		}
	}
}
namespace CB\Automations\Admin {
	final class AutomationsPage {
		public const CAPABILITY = 'manage_options';
		public static function url( array $query = [] ): string { return 'admin.php?notice=' . ( $query['notice'] ?? '' ); }
	}
}
namespace {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
	final class StopWorkflowDelete extends \RuntimeException {
		public function __construct( public int $status, public string $url = '' ) { parent::__construct( $url ); }
	}
	function current_user_can( string $capability ): bool { return (bool) $GLOBALS['allowed']; }
	function wp_verify_nonce( string $nonce, string $action ): bool {
		return 'valid' === $nonce && in_array( $action, [ 'cb_automations_delete_workflow', 'cb_automations_delete_workflows' ], true );
	}
	function sanitize_text_field( string $value ): string { return $value; }
	function wp_unslash( string $value ): string { return $value; }
	function esc_html__( string $message, string $domain ): string { return $message; }
	function wp_die( string $message, string $title = '', array $args = [] ): never {
		throw new StopWorkflowDelete( (int) ( $args['response'] ?? 500 ), 'die' );
	}
	function wp_safe_redirect( string $url ): never { throw new StopWorkflowDelete( 302, $url ); }
	require_once dirname( __DIR__, 2 ) . '/src/Admin/WorkflowDeletionController.php';
	$controller = \CB\Automations\Admin\WorkflowDeletionController::class;
	$repo = \CB\Automations\Persistence\WorkflowRepository::class;
	$base = [
		'_cb_automations_nonce' => 'valid', 'confirm_delete' => '1',
		'workflow_revisions' => [ '7' => '8' ],
	];
	$run = static function ( string $method, array $fields, bool $bulk = false, bool $allowed = true, string $outcome = 'deleted' ) use ( $controller, $repo ): array {
		$_SERVER['REQUEST_METHOD'] = $method;
		$_POST = $fields;
		$GLOBALS['allowed'] = $allowed;
		$repo::$calls = [];
		$repo::$outcome = $outcome;
		try { $bulk ? $controller::bulk() : $controller::single(); }
		catch ( StopWorkflowDelete $stop ) { return [ $stop->status, $stop->url, $repo::$calls ]; }
		throw new \RuntimeException( 'Delete did not stop after redirect or rejection.' );
	};
	$assert = static function ( bool $ok, string $why ): void { if ( ! $ok ) { throw new \RuntimeException( $why ); } };
	foreach ( [ 'GET', 'HEAD', 'PUT', 'DELETE', '' ] as $method ) {
		[ $code,, $calls ] = $run( $method, $base );
		$assert( 405 === $code && [] === $calls, 'Non-POST reached storage.' );
	}
	[ $code,, $calls ] = $run( 'POST', $base, false, false );
	$assert( 403 === $code && [] === $calls, 'Unauthorized actor reached storage.' );
	[ $code,, $calls ] = $run( 'POST', array_replace( $base, [ '_cb_automations_nonce' => 'invalid' ] ) );
	$assert( 403 === $code && [] === $calls, 'Invalid nonce reached storage.' );
	foreach ( [
		[ 'workflow_revisions' => [] ],
		[ 'workflow_revisions' => [ '0' => '8' ] ],
		[ 'workflow_revisions' => [ '07' => '8' ] ],
		[ 'workflow_revisions' => [ '7' => '0' ] ],
		[ 'workflow_revisions' => [ '7' => [ '8' ] ] ],
		[ 'workflow_revisions' => [ '7' => '99999999999999999999' ] ],
		[ 'workflow_revisions' => [ 7 => 8, 8 => 11 ] ],
	] as $invalid ) {
		[ $code, $url, $calls ] = $run( 'POST', array_replace( $base, $invalid ) );
		$assert( 302 === $code && str_contains( $url, 'delete_invalid' ) && [] === $calls, 'Malformed selection reached repository.' );
	}
	[ $code, $url, $calls ] = $run( 'POST', array_replace( $base, [ 'confirm_delete' => '0' ] ) );
	$assert( 302 === $code && str_contains( $url, 'delete_confirmation' ) && [] === $calls, 'Missing explicit confirmation accepted.' );
	[ $code, $url, $calls ] = $run( 'POST', $base );
	$assert( 302 === $code && str_contains( $url, 'notice=deleted' ) && [ [ 7 => 8 ] ] === $calls, 'Single deletion lost revision witness.' );
	[ $code, $url, $calls ] = $run( 'POST', array_replace( $base, [ 'workflow_revisions' => [ 7 => '8', 8 => '11' ] ] ), true );
	$assert( 302 === $code && str_contains( $url, 'deleted_selected' ) && [ [ 7 => 8, 8 => 11 ] ] === $calls, 'Bulk deletion lost exact selection.' );
	[ , $url, $calls ] = $run( 'POST', $base, false, true, 'enabled' );
	$assert( str_contains( $url, 'delete_active' ) && 1 === count( $calls ), 'Active state not reported.' );
	[ , $url ] = $run( 'POST', $base, false, true, 'conflict' );
	$assert( str_contains( $url, 'delete_conflict' ), 'Revision conflict not reported.' );
	[ , $url ] = $run( 'POST', $base, false, true, 'throw' );
	$assert( str_contains( $url, 'delete_unverified' ), 'Uncertain COMMIT falsely reported as failure or success.' );
	[ $code, $url, $calls ] = $run( 'POST', array_replace( $base, [ 'workflow_revisions' => array_fill_keys( range( 1, 26 ), '1' ) ] ), true );
	$assert( 302 === $code && str_contains( $url, 'delete_invalid' ) && [] === $calls, 'Unbounded bulk deletion accepted.' );
	echo "W3.2 workflow delete POST, nonce, permission, selection and uncertainty: PASS\n";
}
