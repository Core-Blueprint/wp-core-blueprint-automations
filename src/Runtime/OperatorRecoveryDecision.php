<?php
declare(strict_types=1);

namespace CB\Automations\Runtime;
defined( 'ABSPATH' ) || exit;

enum OperatorRecoveryDecision: string {
	case ConfirmedSucceeded = 'confirmed_succeeded';
	case ConfirmedDidNotOccur = 'confirmed_did_not_occur';
	case AbandonUnresolved = 'abandon_unresolved';
}
