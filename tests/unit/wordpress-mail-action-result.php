<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Automation {
	// send_mail() does not inspect the invocation context; its exact type
	// contract is preserved without loading Base or a live WordPress site.
	final class InvocationContext {}
}

namespace {
	define( 'ABSPATH', __DIR__ );

	class WP_Error {
		public function __construct( private string $code, private string $message ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}

	$GLOBALS['cb_test_mail_result'] = false;
	$GLOBALS['cb_test_mail_calls'] = 0;

	function sanitize_email( string $value ): string {
		return trim( $value );
	}

	function is_email( string $value ): bool {
		return false !== filter_var( $value, FILTER_VALIDATE_EMAIL );
	}

	function wp_mail( string $to, string $subject, string $body ): bool {
		++$GLOBALS['cb_test_mail_calls'];
		if ( 'test@example.com' !== $to || 'Test subject' !== $subject || 'Test body' !== $body ) {
			throw new \RuntimeException( 'Mail transport received unexpected inputs.' );
		}
		return $GLOBALS['cb_test_mail_result'];
	}

	require_once dirname( __DIR__, 2 ) . '/src/Provider/WordPress/WordPressAction.php';
	$context = new \CoreBlueprint\Core\Automation\InvocationContext();
	$action = \CB\Automations\Provider\WordPress\WordPressAction::class;
	$input = [
		'to'      => 'test@example.com',
		'subject' => 'Test subject',
		'message' => 'Test body',
	];

	$failure = $action::send_mail( $input, $context );
	if ( ! $failure instanceof \WP_Error
		|| 'cb_automations_wordpress_mail_send_failed' !== $failure->get_error_code()
		|| 1 !== $GLOBALS['cb_test_mail_calls'] ) {
		throw new \RuntimeException( 'wp_mail(false) must return a bounded WP_Error, not a successful action result.' );
	}
	if ( str_contains( $failure->get_error_message(), 'test@example.com' ) ) {
		throw new \RuntimeException( 'Mail transport errors must not expose recipient addresses.' );
	}

	$GLOBALS['cb_test_mail_result'] = true;
	$success = $action::send_mail( $input, $context );
	if ( [ 'sent' => true ] !== $success || 2 !== $GLOBALS['cb_test_mail_calls'] ) {
		throw new \RuntimeException( 'wp_mail(true) must retain the declared sent=true output contract.' );
	}

	// Deliberately override the recipient to exercise pre-transport rejection.
	$bad_input = $input;
	$bad_input['to'] = 'not-an-email';
	$invalid = $action::send_mail( $bad_input, $context );
	if ( ! $invalid instanceof \WP_Error
		|| 'cb_automations_wordpress_invalid_input' !== $invalid->get_error_code()
		|| 2 !== $GLOBALS['cb_test_mail_calls'] ) {
		throw new \RuntimeException( 'Invalid recipients must be rejected before any transport invocation.' );
	}

	$runner = file_get_contents( dirname( __DIR__, 2 ) . '/src/Runtime/ActionPhaseRunner.php' );
	if ( false === $runner
		|| ! str_contains( $runner, 'if ( is_wp_error( $result ) )' )
		|| ! str_contains( $runner, 'self::persist_indeterminate( $run_id, $attempt, $error_code, $provider_code, $lease );' ) ) {
		throw new \RuntimeException( 'Mail send failure lost the safe indeterminate Run handling boundary.' );
	}

	fwrite( STDOUT, "WordPress mail Action transport-result semantics: PASS\n" );
}
