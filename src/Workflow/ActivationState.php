<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;
defined( 'ABSPATH' ) || exit;

enum ActivationState: string {
	case Enabled  = 'enabled';
	case Disabled = 'disabled';
}
