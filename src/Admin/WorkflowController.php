<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Automations\Persistence\PersistenceFailure;
use CB\Automations\Workflow\ActivationState;
use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\DefinitionCodec;
use CB\Automations\Workflow\PersistencePolicy;
use CB\Automations\Workflow\WorkflowSaveResult;
use CB\Automations\Workflow\WorkflowService;
use InvalidArgumentException;
use JsonException;
use UnexpectedValueException;

defined( 'ABSPATH' ) || exit;

final class WorkflowController {
	private const ASYNC_FIELD = 'cb_automations_builder_async';

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
		} catch ( InvalidArgumentException $error ) {
			self::redirect( [ 'notice' => 'invalid_name' ] );
		} catch ( PersistenceFailure $error ) {
			error_log( '[Core Blueprint Automations] Workflow create persistence failure: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- bounded diagnostic without workflow data.
			self::redirect( [ 'notice' => 'storage_failed' ] );
		} catch ( \Throwable $error ) {
			error_log( '[Core Blueprint Automations] Workflow create failed: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- bounded diagnostic without workflow data.
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

		if ( $id < 1 || $revision < 1 || null === $state || '' === $json || ! PersistencePolicy::allows_encoded_definition( $json ) ) {
			self::reject_or_redirect( [ 'workflow' => max( 0, $id ), 'notice' => 'invalid' ], 400 );
		}

		try {
			$decoded = json_decode( $json, true, 64, JSON_THROW_ON_ERROR );
			if ( ! is_array( $decoded ) ) {
				throw new UnexpectedValueException( 'Workflow definition root must be an object.' );
			}
			$definition = DefinitionCodec::decode( $decoded );
		} catch ( JsonException | UnexpectedValueException $error ) {
			self::reject_or_redirect( [ 'workflow' => $id, 'notice' => 'invalid' ], 400 );
		}

		try {
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

			if ( self::is_async_request() ) {
				self::respond_save_result( $result, $notice, $revision, $state );
			}
			self::redirect( [ 'workflow' => $id, 'notice' => $notice ] );
		} catch ( InvalidArgumentException $error ) {
			self::reject_or_redirect( [ 'workflow' => $id, 'notice' => 'invalid_name' ], 400 );
		} catch ( PersistenceFailure $error ) {
			error_log( '[Core Blueprint Automations] Workflow save persistence failure: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- bounded diagnostic without workflow data.
			self::reject_or_redirect( [ 'workflow' => $id, 'notice' => 'storage_failed' ], 500 );
		} catch ( \Throwable $error ) {
			// Never log the submitted definition: it may contain operator-entered literals.
			error_log( '[Core Blueprint Automations] Workflow save failed: ' . $error->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- bounded diagnostic without workflow data.
			self::reject_or_redirect( [ 'workflow' => $id, 'notice' => 'failed' ], 500 );
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

	private static function is_async_request(): bool {
		$value = isset( $_POST[ self::ASYNC_FIELD ] ) && is_scalar( $_POST[ self::ASYNC_FIELD ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::ASYNC_FIELD ] ) )
			: '';
		return '1' === $value;
	}

	private static function respond_save_result( WorkflowSaveResult $result, string $notice, int $revision, ActivationState $state ): never {
		$issues = [];
		foreach ( $result->validation()->issues() as $issue ) {
			$issues[] = [
				'path'    => $issue->path(),
				'message' => ValidationPresenter::message( $issue ),
			];
		}

		$data = [
			'notice'           => $notice,
			'message'          => self::notice_message( $notice ),
			'revision'         => $result->was_saved() ? $revision + 1 : $revision,
			'activation_state' => WorkflowSaveResult::SAVED_DISABLED === $result->status() ? ActivationState::Disabled->value : $state->value,
			'valid'            => $result->validation()->is_valid(),
			'issues'           => $issues,
		];

		if ( $result->was_saved() ) {
			wp_send_json_success( $data );
			exit;
		}

		$status = WorkflowSaveResult::CONFLICT === $result->status() ? 409 : 400;
		wp_send_json_error( $data, $status );
		exit;
	}

	/** @param array<string,scalar> $query */
	private static function reject_or_redirect( array $query, int $status ): never {
		if ( self::is_async_request() ) {
			$notice = sanitize_key( (string) ( $query['notice'] ?? 'failed' ) );
			wp_send_json_error( [
				'notice'  => $notice,
				'message' => self::notice_message( $notice ),
			], $status );
			exit;
		}
		self::redirect( $query );
	}

	private static function notice_message( string $notice ): string {
		return match ( $notice ) {
			'saved'               => __( 'Automation saved.', 'core-blueprint-automations' ),
			'saved_disabled'      => __( 'Changes saved. The automation remains disabled until the workflow is valid.', 'core-blueprint-automations' ),
			'persistence_blocked' => __( 'The automation was not saved because a sensitive input contains a literal value.', 'core-blueprint-automations' ),
			'conflict'            => __( 'This automation changed in another request. Reload the page before saving again.', 'core-blueprint-automations' ),
			'invalid_name'        => __( 'Enter an automation name between 1 and 191 characters.', 'core-blueprint-automations' ),
			'invalid'             => __( 'The submitted workflow could not be decoded safely. No changes were saved.', 'core-blueprint-automations' ),
			'storage_failed'      => __( 'The automation could not be saved because workflow storage is unavailable.', 'core-blueprint-automations' ),
			default               => __( 'The automation could not be saved. Reload the page before trying again.', 'core-blueprint-automations' ),
		};
	}

	/** @param array<string,scalar> $query */
	private static function redirect( array $query ): never {
		wp_safe_redirect( AutomationsPage::url( $query ) );
		exit;
	}
}
