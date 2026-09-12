<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;
defined( 'ABSPATH' ) || exit;

enum NodeType: string {
	case State = 'state';
	case Condition = 'condition';
	case Action = 'action';
}
