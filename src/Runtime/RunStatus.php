<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;
defined( 'ABSPATH' ) || exit;

enum RunStatus: string {
	case Queued = 'queued';
	case Running = 'running';
	case RetryWait = 'retry_wait';
	case Blocked = 'blocked';
	case Succeeded = 'succeeded';
	case Skipped = 'skipped';
	case Failed = 'failed';
	case Cancelled = 'cancelled';
	case Indeterminate = 'indeterminate';

	public function is_terminal(): bool {
		return in_array(
			$this,
			[ self::Succeeded, self::Skipped, self::Failed, self::Cancelled ],
			true
		);
	}

	public function halts_automatic_execution(): bool {
		return $this->is_terminal() || in_array( $this, [ self::Blocked, self::Indeterminate ], true );
	}
}
