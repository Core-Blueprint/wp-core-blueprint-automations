<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;
defined( 'ABSPATH' ) || exit;

final class RunStateMachine {
	public static function allows( RunStatus $from, RunStatus $to ): bool {
		return match ( $from ) {
			RunStatus::Queued => in_array( $to, [ RunStatus::Running, RunStatus::Blocked, RunStatus::Cancelled ], true ),
			RunStatus::Running => in_array(
				$to,
				[
					RunStatus::RetryWait,
					RunStatus::Blocked,
					RunStatus::Succeeded,
					RunStatus::Skipped,
					RunStatus::Failed,
					RunStatus::Cancelled,
					RunStatus::Indeterminate,
				],
				true
			),
			RunStatus::RetryWait => in_array( $to, [ RunStatus::Queued, RunStatus::Blocked, RunStatus::Cancelled ], true ),
			RunStatus::Blocked => in_array( $to, [ RunStatus::Queued, RunStatus::Cancelled ], true ),
			RunStatus::Indeterminate => in_array( $to, [ RunStatus::Blocked, RunStatus::Succeeded, RunStatus::Failed, RunStatus::Cancelled ], true ),
			RunStatus::Succeeded,
			RunStatus::Skipped,
			RunStatus::Failed,
			RunStatus::Cancelled => false,
		};
	}
}
