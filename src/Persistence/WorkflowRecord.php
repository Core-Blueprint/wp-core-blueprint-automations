<?php
declare(strict_types=1);

namespace CB\Automations\Persistence;

use CB\Automations\Workflow\ActivationState;
use CB\Automations\Workflow\Definition;

defined( 'ABSPATH' ) || exit;

final readonly class WorkflowRecord {
	public function __construct(
		private int $id,
		private string $name,
		private ActivationState $activation_state,
		private Definition $definition,
		private int $revision,
		private int $created_by,
		private int $updated_by,
		private string $created_at,
		private string $updated_at
	) {}

	public function id(): int { return $this->id; }
	public function name(): string { return $this->name; }
	public function activation_state(): ActivationState { return $this->activation_state; }
	public function definition(): Definition { return $this->definition; }
	public function revision(): int { return $this->revision; }
	public function created_by(): int { return $this->created_by; }
	public function updated_by(): int { return $this->updated_by; }
	public function created_at(): string { return $this->created_at; }
	public function updated_at(): string { return $this->updated_at; }
}
