<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;
defined( 'ABSPATH' ) || exit;

final readonly class JobRecord {
	public function __construct(
		private int $id,
		private JobSubjectType $subject_type,
		private int $subject_id,
		private JobStatus $status,
		private string $available_at,
		private string $lease_token,
		private ?string $lease_expires_at,
		private int $worker_attempts,
		private string $last_error_code,
		private string $created_at,
		private string $updated_at
	) {}

	public function id(): int { return $this->id; }
	public function subject_type(): JobSubjectType { return $this->subject_type; }
	public function subject_id(): int { return $this->subject_id; }
	public function status(): JobStatus { return $this->status; }
	public function available_at(): string { return $this->available_at; }
	public function lease_token(): string { return $this->lease_token; }
	public function lease_expires_at(): ?string { return $this->lease_expires_at; }
	public function worker_attempts(): int { return $this->worker_attempts; }
	public function last_error_code(): string { return $this->last_error_code; }
	public function created_at(): string { return $this->created_at; }
	public function updated_at(): string { return $this->updated_at; }
}
