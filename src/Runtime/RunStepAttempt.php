<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;
defined( 'ABSPATH' ) || exit;

final readonly class RunStepAttempt {
	public function __construct(
		private int $id,
		private int $attempt
	) {
		if ( $id < 1 || $attempt < 1 ) {
			throw new \InvalidArgumentException( 'Invalid automation run step attempt.' );
		}
	}

	public function id(): int { return $this->id; }
	public function attempt(): int { return $this->attempt; }
}
