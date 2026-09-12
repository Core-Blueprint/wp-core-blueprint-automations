<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;

use CB\Automations\Workflow\Definition;

defined( 'ABSPATH' ) || exit;

final readonly class RunRecord {
	public function __construct(
		private int $id,
		private string $run_uuid,
		private string $correlation_id,
		private int $event_receipt_id,
		private int $workflow_id,
		private int $workflow_revision,
		private int $execution_principal_user_id,
		private Definition $definition,
		private string $definition_hash,
		private RunStatus $status,
		private string $cursor,
		private string $failure_code
	) {}

	public function id(): int { return $this->id; }
	public function run_uuid(): string { return $this->run_uuid; }
	public function correlation_id(): string { return $this->correlation_id; }
	public function event_receipt_id(): int { return $this->event_receipt_id; }
	public function workflow_id(): int { return $this->workflow_id; }
	public function workflow_revision(): int { return $this->workflow_revision; }
	public function execution_principal_user_id(): int { return $this->execution_principal_user_id; }
	public function definition(): Definition { return $this->definition; }
	public function definition_hash(): string { return $this->definition_hash; }
	public function status(): RunStatus { return $this->status; }
	public function cursor(): string { return $this->cursor; }
	public function failure_code(): string { return $this->failure_code; }
}
