<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;
defined( 'ABSPATH' ) || exit;

enum StepStatus: string {
	case Running = 'running';
	case Succeeded = 'succeeded';
	case Skipped = 'skipped';
	case Failed = 'failed';
	case Blocked = 'blocked';
	case Cancelled = 'cancelled';
	case Interrupted = 'interrupted';
	case Indeterminate = 'indeterminate';

	public function is_terminal(): bool {
		return self::Running !== $this;
	}
}
