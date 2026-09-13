<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;
defined( 'ABSPATH' ) || exit;

final class CrashRecoveryPolicy {
	public static function for_running_step( NodeType $type, StepStatus $status ): RecoveryDecision {
		if ( StepStatus::Running !== $status ) {
			return RecoveryDecision::None;
		}

		return match ( $type ) {
			NodeType::State,
			NodeType::Condition => RecoveryDecision::ReplaySafe,
			NodeType::Action => RecoveryDecision::Indeterminate,
		};
	}
}
