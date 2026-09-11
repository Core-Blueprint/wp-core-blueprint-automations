<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Automations\Workflow\ActivationState;
use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\DefinitionCodec;
use CB\Automations\Workflow\WorkflowSaveResult;
use CB\Automations\Workflow\WorkflowService;
use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

final class WorkflowController {
	private const MAX_DEFINITION_BYTES = 262144;

	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) {
			return;
		}
		self::$initialized = true;

		add_action( 'admin_post_cb_automations_create_workflow', [ self::class, 'create' ] );
		add_action( 'admin_post_cb_automations_save_workflow', [ self::class, 'save' ] );
	}

	public static function create(): void {
		self::guard( 'cb_automations_create_workflow' );

		$name = isset( $_POST['name'] ) && is_scalar( $_POST['name'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) )
			: '';

		try {
			$definition = Definition::from_values( null, [], [], [] );
			if ( null === $definition ) {
				throw new \RuntimeException( 'Could not create empty workflow definition.' );
			}

			$id = ( new WorkflowService() )->create( $name, $definition, get_current_user_id() );
			self::redirect( [ 'workflow' => $id, 'notice' => 'created' ] );
		} catch ( \Throwable $error ) {
			error_log( '[Core Blueprint Automations] Workflow create failed: ' . $error->getMessage() );
			self::redirect( [ 'notice' => 'failed' ] );
		}
	}

	public static function save(): void {
		self::guard( 'cb_automations_save_workflow' );

		$id       = isset( $_POST['workflow_id'] ) && is_scalar( $_POST['workflow_id'] ) ? absint( wp_unslash( (string) $_POST['workflow_id'] ) ) : 0;
		$revision = isset( $_POST['revision'] ) && is_scalar( $_POST['revision'] ) ? absint( wp_unslash( (string) $_POST['revision'] ) ) : 0;
		$name     = isset( $_POST['name'] ) && is_scalar( $_POST['name'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['name'] ) ) : '';
		$state    = isset( $_POST['activation_state'] ) && is_scalar( $_POST['activation_state'] )
			? ActivationState::tryFrom( sanitize_key( wp_unslash( (string) $_POST['activation_state'] ) ) )
			: null;
		$json     = isset( $_POST['definition_json'] ) && is_string( $_POST['definition_json'] )
			? wp_unslash( $_POST['definition_json'] )
			: '';

		if ( $id < 1 || $revision < 1 || null === $state || '' === $json || strlen( $json ) > self::MAX_DEFINITION_BYTES ) {
			self::redirect( [ 'workflow' => max( 0, $id ), 'notice' => 'invalid' ] );
		}

		try {
			$decoded = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
			if ( ! is_array( $decoded ) ) {
				throw new UnexpectedValueException( 'Workflow definition root must be an object.' );
			}
			$definition = DefinitionCodec::decode( $decoded );
			$result = ( new WorkflowService() )->save(
				$id,
				$revision,
				$name,
				$state,
				$definition,
				get_current_user_id()
			);

			$notice = match ( $result->status() ) {
				WorkflowSaveResult::SAVED               => 'saved',
				WorkflowSaveResult::SAVED_DISABLED      => 'saved_disabled',
				WorkflowSaveResult::PERSISTENCE_BLOCKED => 'persistence_blocked',
				WorkflowSaveResult::CONFLICT            => 'conflict',
				default                                 => 'failed',
			};
			self::redirect( [ 'workflow' => $id, 'notice' => $notice ] );
		} catch ( \Throwable $error ) {
			// Never log the submitted definition: it may contain operator-entered literals.
			error_log( '[Core Blueprint Automations] Workflow save failed: ' . $error->getMessage() );
			self::redirect( [ 'workflow' => $id, 'notice' => 'invalid' ] );
		}
	}

	private static function guard( string $nonce_action ): void {
		if ( ! current_user_can( AutomationsPage::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage Automations.', 'core-blueprint-automations' ), esc_html__( 'Forbidden', 'core-blueprint-automations' ), [ 'response' => 403 ] );
		}

		$nonce = isset( $_POST['_cb_automations_nonce'] ) && is_scalar( $_POST['_cb_automations_nonce'] )
			? sanitize_text_field( wp_unslash( (string) $_POST['_cb_automations_nonce'] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'The request could not be verified. Reload the page and try again.', 'core-blueprint-automations' ), esc_html__( 'Invalid request', 'core-blueprint-automations' ), [ 'response' => 403 ] );
		}
	}

	/** @param array<string,scalar> $query */
	private static function redirect( array $query ): never {
		wp_safe_redirect( AutomationsPage::url( $query ) );
		exit;
	}
}
