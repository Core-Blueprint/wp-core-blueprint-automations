<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Automations\Capability\CapabilityReference;
use CB\Automations\Discovery\CapabilityCatalog;
use CB\Automations\Discovery\CapabilityDefinition;
use CB\Automations\Persistence\WorkflowRepository;
use CB\Automations\Runtime\GraphRoutePlanner;
use CB\Automations\Validation\GraphValidator;
use CB\Automations\Workflow\ActivationState;
use CB\Automations\Workflow\GraphDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * ADV-03B: read-only route simulation of an already saved, disabled v2 draft.
 * This NEVER admits a run, invokes a capability, persists fixtures or enters
 * the worker. It cannot be used to enable or execute Advanced workflows.
 */
final class AdvancedRoutePreviewController {
	private static bool $initialized = false;

	public static function init(): void {
		if ( self::$initialized ) return;
		self::$initialized = true;
		add_action( 'admin_post_cb_automations_preview_advanced', [ self::class, 'preview' ] );
	}

	public static function preview(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? null ) ) {
			self::reject( 'Method not allowed.', 405 );
		}
		if ( ! current_user_can( AutomationsPage::CAPABILITY ) ) {
			self::reject( 'Not permitted.', 403 );
		}
		$nonce = isset( $_POST['_cb_automations_nonce'] ) && is_string( $_POST['_cb_automations_nonce'] )
			? sanitize_text_field( wp_unslash( $_POST['_cb_automations_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'cb_automations_preview_advanced' ) ) {
			self::reject( 'Invalid request. Reload the workflow.', 403 );
		}
		$id_raw = isset( $_POST['workflow_id'] ) && is_string( $_POST['workflow_id'] )
			? wp_unslash( $_POST['workflow_id'] ) : '';
		$revision = isset( $_POST['revision'] ) && is_string( $_POST['revision'] )
			? wp_unslash( $_POST['revision'] ) : '';
		$fixture = isset( $_POST['fixture_json'] ) && is_string( $_POST['fixture_json'] )
			? wp_unslash( $_POST['fixture_json'] ) : '';
		if ( 1 !== preg_match( '/^[1-9][0-9]{0,17}$/D', $id_raw )
			|| 1 !== preg_match( '/^[1-9][0-9]{0,17}$/D', $revision )
			|| '' === $fixture || strlen( $fixture ) > 8192 ) {
			self::reject( 'Invalid saved workflow or sample output data.', 400 );
		}
		try {
			$record = WorkflowRepository::find( (int) $id_raw );
			if ( null === $record || ! $record->definition() instanceof GraphDefinition
				|| ActivationState::Disabled !== $record->activation_state()
				|| ! hash_equals( (string) $record->revision(), $revision ) ) {
				self::reject( 'Workflow changed. Save and reopen before testing.', 409 );
			}
			$data = json_decode( $fixture, true, 32, JSON_THROW_ON_ERROR );
			if ( ! is_array( $data ) || ( array_is_list( $data ) && [] !== $data ) || count( $data ) > 512 ) {
				self::reject( 'Sample outputs must be a JSON object.', 400 );
			}
			$catalog = new CapabilityCatalog();
			$definition = $record->definition();
			if ( ! ( new GraphValidator( $catalog ) )->validate( $definition )->is_valid() ) {
				self::reject( 'Configure and save a valid workflow before testing.', 422 );
			}
			$nodes = [];
			foreach ( $definition->graph()['nodes'] as $node ) $nodes[ $node['id'] ] = $node;
			foreach ( $data as $node_id => $outputs ) {
				if ( ! is_string( $node_id ) || ! isset( $nodes[ $node_id ] )
					|| ! in_array( $nodes[ $node_id ]['type'], [ 'trigger', 'state', 'action' ], true )
					|| ! is_array( $outputs ) || ( array_is_list( $outputs ) && [] !== $outputs ) ) {
					self::reject( 'Unknown sample output node.', 400 );
				}
				$ref = $nodes[ $node_id ]['config']['capability'];
				$identity = CapabilityReference::from_values( $ref['kind'], $ref['provider'], $ref['id'], $ref['schema_version'] );
				$cap = null === $identity ? null : $catalog->current( $identity );
				if ( ! $cap instanceof CapabilityDefinition || $cap->reference()->key() !== $identity->key() ) {
					self::reject( 'Sample output capability is unavailable.', 422 );
				}
				$schema = $cap->output_schema();
				foreach ( $outputs as $field => $value ) {
					$spec = is_string( $field ) ? ( $schema[ $field ] ?? null ) : null;
					if ( ! is_array( $spec ) || ! empty( $spec['sensitive'] ) || ! self::type_matches( $value, $spec ) ) {
						self::reject( 'Invalid or sensitive sample output field.', 400 );
					}
				}
			}
			$trace = ( new GraphRoutePlanner( $catalog ) )->trace( $definition, $data );
			// Return ONLY graph route identities and boolean branch decisions.
			// Never reflect fixture values, literal bindings or provider output.
			wp_send_json_success( [ 'nodes' => $trace['nodes'], 'decisions' => $trace['decisions'] ] );
		} catch ( \Throwable ) {
			self::reject( 'Route simulation could not be completed with these sample outputs.', 422 );
		}
	}

	/** @param array<string,mixed> $schema */
	private static function type_matches( mixed $value, array $schema ): bool {
		return match ( $schema['type'] ?? null ) {
			'string' => is_string( $value ) && strlen( $value ) <= 512,
			'integer' => is_int( $value ) && abs( $value ) <= 9007199254740991,
			'number' => ( is_int( $value ) || is_float( $value ) )
				&& is_finite( (float) $value ),
			'boolean' => is_bool( $value ),
			'array' => is_array( $value ) && array_is_list( $value ) && count( $value ) <= 32
				&& isset( $schema['items'] ) && is_string( $schema['items'] )
				&& array_reduce( $value, static fn ( bool $valid, mixed $item ): bool =>
					$valid && self::type_matches( $item, [ 'type' => $schema['items'] ] ), true ),
			default => false,
		};
	}

	private static function reject( string $message, int $status ): never {
		wp_send_json_error( [ 'message' => $message ], $status );
		exit;
	}
}
