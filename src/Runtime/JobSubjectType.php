<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;
defined( 'ABSPATH' ) || exit;

enum JobSubjectType: string {
	case Event = 'event';
	case Run = 'run';
}
