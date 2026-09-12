<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;
defined( 'ABSPATH' ) || exit;

enum JobStatus: string {
	case Queued = 'queued';
	case Leased = 'leased';
	case Complete = 'complete';
	case Failed = 'failed';
	case Cancelled = 'cancelled';

	public function is_terminal(): bool {
		return in_array( $this, [ self::Complete, self::Failed, self::Cancelled ], true );
	}
}
