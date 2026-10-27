<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;

defined( 'ABSPATH' ) || exit;

/**
 * Immutable Advanced v2 definition. The graph determines execution order;
 * layout is presentation-only. This class grants no execution authority.
 */
final readonly class GraphDefinition {
	public const VERSION = 2;

	/** @param array<string,mixed> $graph @param array<string,mixed> $layout */
	private function __construct(
		private array $graph,
		private array $layout
	) {}

	/** @param array<string,mixed> $graph @param array<string,mixed> $layout */
	public static function from_values( array $graph, array $layout ): self {
		return GraphDefinitionCodec::decode( [
			'definition_version' => self::VERSION,
			'mode'               => 'advanced',
			'graph'              => $graph,
			'layout'             => $layout,
		] );
	}

	/** Internal canonical factory; only the strict codec may call this. */
	public static function from_validated( array $graph, array $layout ): self {
		return new self( $graph, $layout );
	}

	public function definition_version(): int { return self::VERSION; }

	/** @return array<string,mixed> */
	public function graph(): array { return $this->graph; }

	/** @return array<string,mixed> */
	public function layout(): array { return $this->layout; }

	/** @return array<string,mixed> */
	public function to_array(): array {
		return [
			'definition_version' => self::VERSION,
			'mode'               => 'advanced',
			'graph'              => $this->graph,
			'layout'             => $this->layout,
		];
	}
}
