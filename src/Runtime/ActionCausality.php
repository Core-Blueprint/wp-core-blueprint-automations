<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

defined( 'ABSPATH' ) || exit;

/**
 * Internal, bounded causal provenance for automation-originated events.
 *
 * Base TriggerEvent stays immutable and provider schemas are untouched.
 * Only Automations can create trusted lineage while invoking an Action.
 */
final class ActionCausality {
	public const MAX_DEPTH = 12;
	private static ?array $active = null;

	/** @return array{correlation_id:string,workflow_path:list<int>} */
	public static function for_run( int $workflow_id, string $correlation_id, mixed $parent ): array {
		if ( $workflow_id < 1 || ! self::valid_uuid( $correlation_id ) ) {
			throw new \UnexpectedValueException( 'Invalid automation run causal identity.' );
		}
		$path = [];
		if ( null !== $parent ) {
			$prior = self::validate( $parent );
			if ( ! hash_equals( $prior['correlation_id'], $correlation_id ) ) {
				throw new \UnexpectedValueException( 'Automation causal correlation mismatch.' );
			}
			$path = $prior['workflow_path'];
		}
		if ( count( $path ) >= self::MAX_DEPTH || in_array( $workflow_id, $path, true ) ) {
			throw new \UnexpectedValueException( 'Automation action causal loop or depth limit.' );
		}
		$path[] = $workflow_id;
		return [ 'correlation_id' => $correlation_id, 'workflow_path' => $path ];
	}

	/**
	 * Scope is in-request only. The captured origin is persisted separately
	 * with the encrypted event receipt for subsequent WP-Cron jobs.
	 *
	 * @param array<string,mixed> $origin
	 */
	public static function during_action( array $origin, callable $action ): mixed {
		$trusted = self::validate( $origin );
		$previous = self::$active;
		self::$active = $trusted;
		try {
			return $action();
		} finally {
			self::$active = $previous;
		}
	}

	/** @return array{correlation_id:string,workflow_path:list<int>}|null */
	public static function current(): ?array {
		return self::$active;
	}

	/**
	 * @return string Empty means the workflow is safe to materialize.
	 */
	public static function suppression_reason( ?array $parent, int $candidate_workflow_id ): string {
		if ( $candidate_workflow_id < 1 ) {
			throw new \UnexpectedValueException( 'Invalid automation causal target.' );
		}
		if ( null === $parent ) {
			return '';
		}
		$origin = self::validate( $parent );
		if ( in_array( $candidate_workflow_id, $origin['workflow_path'], true ) ) {
			return 'runtime.causal_cycle_prevented';
		}
		if ( count( $origin['workflow_path'] ) >= self::MAX_DEPTH ) {
			return 'runtime.causal_depth_limit';
		}
		return '';
	}

	/**
	 * @return array{correlation_id:string,workflow_path:list<int>}
	 */
	public static function validate( mixed $origin ): array {
		if ( ! is_array( $origin ) || array_keys( $origin ) !== [ 'correlation_id', 'workflow_path' ]
			|| ! is_string( $origin['correlation_id'] ) || ! self::valid_uuid( $origin['correlation_id'] )
			|| ! is_array( $origin['workflow_path'] ) || ! array_is_list( $origin['workflow_path'] )
			|| count( $origin['workflow_path'] ) < 1 || count( $origin['workflow_path'] ) > self::MAX_DEPTH ) {
			throw new \UnexpectedValueException( 'Invalid automation causal provenance.' );
		}
		$seen = [];
		foreach ( $origin['workflow_path'] as $id ) {
			if ( ! is_int( $id ) || $id < 1 || isset( $seen[ $id ] ) ) {
				throw new \UnexpectedValueException( 'Invalid automation workflow ancestry.' );
			}
			$seen[ $id ] = true;
		}
		return $origin;
	}

	private static function valid_uuid( string $uuid ): bool {
		return 1 === preg_match(
			'/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/Di',
			$uuid
		);
	}
}
