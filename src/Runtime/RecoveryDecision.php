<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;
defined( 'ABSPATH' ) || exit;

enum RecoveryDecision: string {
	case ReplaySafe = 'replay_safe';
	case Indeterminate = 'indeterminate';
	case None = 'none';
}
