<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Workflow\GraphDefinition;

defined( 'ABSPATH' ) || exit;

/**
 * ADV-02C C1: strictly checked Advanced checkpoint evidence document.
 *
 * This is an immutable in-memory journal codec, NOT execution authority.
 * No source of durable truth exists until a separately tested lease-fenced
 * persistence writer commits it with the run cursor and step result.
 * The caller must supply trigger outputs from trusted encrypted run context.
 * Never accept a journal supplied by an admin request for runtime admission.
 */
final readonly class GraphCheckpointJournal {
	private const VERSION = 1;
	private const MAX_ENTRIES = 512;
	private const MAX_BYTES = 262144;
	private const NODE_ID = '/^[a-z][a-z0-9_]{0,63}$/D';

	/** @param list<array<string,mixed>> $entries */
	private function __construct( private array $entries ) {}

	public static function empty(): self {
		return new self( [] );
	}

	/** @param array<string,mixed> $document */
	public static function from_array( array $document ): self {
		if ( array_keys( $document ) !== [ 'version', 'entries' ]
			|| self::VERSION !== $document['version']
			|| ! is_array( $document['entries'] )
			|| ! array_is_list( $document['entries'] )
			|| count( $document['entries'] ) > self::MAX_ENTRIES ) {
			throw new \UnexpectedValueException( 'Malformed Advanced checkpoint journal.' );
		}

		$seen = [];
		foreach ( $document['entries'] as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['kind'], $entry['node_id'] )
				|| ! self::id( $entry['node_id'] ) || isset( $seen[ $entry['node_id'] ] ) ) {
				throw new \UnexpectedValueException( 'Invalid or duplicate Advanced checkpoint entry.' );
			}
			$kind = $entry['kind'];
			if ( in_array( $kind, [ 'state', 'action' ], true ) ) {
				if ( array_keys( $entry ) !== [ 'kind', 'node_id', 'output' ] || ! is_array( $entry['output'] ) ) {
					throw new \UnexpectedValueException( 'Invalid Advanced completion evidence.' );
				}
			} elseif ( 'if_else' === $kind ) {
				if ( array_keys( $entry ) !== [ 'kind', 'node_id', 'decision' ] || ! is_bool( $entry['decision'] ) ) {
					throw new \UnexpectedValueException( 'Invalid Advanced branch evidence.' );
				}
			} else {
				throw new \UnexpectedValueException( 'Unsupported Advanced checkpoint evidence kind.' );
			}
			$seen[ $entry['node_id'] ] = true;
		}

		try {
			$json = json_encode( $document, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			throw new \UnexpectedValueException( 'Advanced checkpoint cannot be serialized safely.' );
		}
		if ( strlen( $json ) > self::MAX_BYTES ) {
			throw new \UnexpectedValueException( 'Advanced checkpoint exceeds its size limit.' );
		}
		return new self( $document['entries'] );
	}

	/** @return array{version:int,entries:list<array<string,mixed>>} */
	public function to_array(): array {
		return [ 'version' => self::VERSION, 'entries' => $this->entries ];
	}

	/**
	 * Revalidate the entire acknowledgement prefix against immutable graph
	 * routing and previously supplied trusted outputs. Nothing is executed.
	 *
	 * @param array<string,array<string,mixed>> $trigger_outputs
	 * @return array{status:string,route:list<string>,decisions:array<string,bool>,intent:?array,pending_branch:?array{node_id:string,decision:bool}}
	 */
	public function replay(
		GraphDefinition $definition,
		GraphCheckpointPlanner $planner,
		array $trigger_outputs = []
	): array {
		$trigger = null;
		foreach ( $definition->graph()['nodes'] as $node ) {
			if ( 'trigger' === $node['type'] ) {
				$trigger = $node['id'];
				break;
			}
		}
		if ( null === $trigger ) {
			throw new \UnexpectedValueException( 'Advanced checkpoint has no trigger.' );
		}
		foreach ( $trigger_outputs as $id => $value ) {
			if ( $id !== $trigger || ! is_array( $value ) ) {
				throw new \UnexpectedValueException( 'Unexpected Advanced trigger output source.' );
			}
		}

		$acknowledged = [];
		$outputs = $trigger_outputs;
		$decisions = [];
		foreach ( $this->entries as $entry ) {
			$projection = $planner->project( $definition, $acknowledged, $outputs );
			if ( 'if_else' === $entry['kind'] ) {
				$next = self::next_decision( $projection['decisions'], $decisions );
				if ( null === $next || $next['node_id'] !== $entry['node_id']
					|| $next['decision'] !== $entry['decision'] ) {
					throw new \UnexpectedValueException( 'Advanced branch evidence differs from the chosen route.' );
				}
				$decisions[ $entry['node_id'] ] = $entry['decision'];
				continue;
			}
			if ( null !== self::next_decision( $projection['decisions'], $decisions )
				|| 'pending' !== $projection['status']
				|| null === $projection['intent']
				|| $projection['intent']['node_id'] !== $entry['node_id']
				|| $projection['intent']['kind'] !== $entry['kind'] ) {
				throw new \UnexpectedValueException( 'Advanced step acknowledgement is not the next executable node.' );
			}
			$acknowledged[] = $entry['node_id'];
			$outputs[ $entry['node_id'] ] = $entry['output'];
		}

		$projection = $planner->project( $definition, $acknowledged, $outputs );
		$projection['pending_branch'] = self::next_decision( $projection['decisions'], $decisions );
		return $projection;
	}

	/**
	 * The branch choice comes exclusively from the existing deterministic
	 * evaluator. An external caller cannot choose the branch by passing a bool.
	 *
	 * @param array<string,array<string,mixed>> $trigger_outputs
	 */
	public function record_next_branch(
		GraphDefinition $definition,
		GraphCheckpointPlanner $planner,
		array $trigger_outputs = []
	): self {
		$next = $this->replay( $definition, $planner, $trigger_outputs )['pending_branch'];
		if ( null === $next ) {
			throw new \UnexpectedValueException( 'Advanced checkpoint has no branch choice to acknowledge.' );
		}
		return self::from_array( [
			'version' => self::VERSION,
			'entries' => [ ...$this->entries, [
				'kind' => 'if_else',
				'node_id' => $next['node_id'],
				'decision' => $next['decision'],
			] ],
		] );
	}

	/**
	 * Records a proposed State/Action result only. A future executor MUST
	 * prove the provider result and lease inside the same transaction as this
	 * journal's durable append; this method cannot independently prove either.
	 *
	 * @param array<string,mixed> $output
	 * @param array<string,array<string,mixed>> $trigger_outputs
	 */
	public function record_completion(
		GraphDefinition $definition,
		GraphCheckpointPlanner $planner,
		string $node_id,
		string $kind,
		array $output,
		array $trigger_outputs = []
	): self {
		if ( ! self::id( $node_id ) || ! in_array( $kind, [ 'state', 'action' ], true ) ) {
			throw new \UnexpectedValueException( 'Invalid Advanced completion identity.' );
		}
		$projection = $this->replay( $definition, $planner, $trigger_outputs );
		if ( null !== $projection['pending_branch']
			|| 'pending' !== $projection['status']
			|| null === $projection['intent']
			|| $projection['intent']['node_id'] !== $node_id
			|| $projection['intent']['kind'] !== $kind ) {
			throw new \UnexpectedValueException( 'Advanced completion does not match the next pending node.' );
		}
		return self::from_array( [
			'version' => self::VERSION,
			'entries' => [ ...$this->entries, [
				'kind' => $kind,
				'node_id' => $node_id,
				'output' => $output,
			] ],
		] );
	}

	/** @param array<string,bool> $route @param array<string,bool> $saved
	 * @return array{node_id:string,decision:bool}|null */
	private static function next_decision( array $route, array $saved ): ?array {
		$position = 0;
		foreach ( $route as $node_id => $decision ) {
			if ( $position >= count( $saved ) ) {
				return [ 'node_id' => $node_id, 'decision' => $decision ];
			}
			$ids = array_keys( $saved );
			if ( $ids[ $position ] !== $node_id || $saved[ $node_id ] !== $decision ) {
				throw new \UnexpectedValueException( 'Advanced checkpoint recorded a different branch history.' );
			}
			++$position;
		}
		if ( count( $saved ) !== $position ) {
			throw new \UnexpectedValueException( 'Advanced checkpoint has branch history beyond the current route.' );
		}
		return null;
	}

	private static function id( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( self::NODE_ID, $value );
	}
}
