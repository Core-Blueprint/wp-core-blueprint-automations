<?php
declare(strict_types=1);

namespace CB\Automations\Workflow;

use CB\Automations\Discovery\CapabilitySource;

defined( 'ABSPATH' ) || exit;

final class ExecutionPrincipalPolicy {
	public static function evaluate(
		Definition $definition,
		int $principal_user_id,
		int $operator_user_id,
		CapabilitySource $source
	): ExecutionAuthorityDecision {
		if ( $principal_user_id <= 0 ) {
			return ExecutionAuthorityDecision::deny( ExecutionAuthorityDecision::PRINCIPAL_MISSING );
		}

		$principal = get_userdata( $principal_user_id );
		if ( ! $principal instanceof \WP_User ) {
			return ExecutionAuthorityDecision::deny( ExecutionAuthorityDecision::PRINCIPAL_INVALID );
		}

		$operator = get_userdata( $operator_user_id );
		if ( ! $operator instanceof \WP_User ) {
			return ExecutionAuthorityDecision::deny( ExecutionAuthorityDecision::OPERATOR_PERMISSION_DENIED );
		}

		foreach ( array_merge( $definition->states(), $definition->actions() ) as $step ) {
			$capability = $source->current( $step->capability() );
			if ( null === $capability ) {
				return ExecutionAuthorityDecision::deny( ExecutionAuthorityDecision::CAPABILITY_UNAVAILABLE );
			}

			$required = $capability->required_capability();
			if ( null === $required || '' === $required ) {
				return ExecutionAuthorityDecision::deny( ExecutionAuthorityDecision::CAPABILITY_UNAVAILABLE );
			}

			if ( ! user_can( $principal_user_id, $required ) ) {
				return ExecutionAuthorityDecision::deny( ExecutionAuthorityDecision::PRINCIPAL_PERMISSION_DENIED );
			}
			if ( ! user_can( $operator_user_id, $required ) ) {
				return ExecutionAuthorityDecision::deny( ExecutionAuthorityDecision::OPERATOR_PERMISSION_DENIED );
			}
		}

		return ExecutionAuthorityDecision::allow();
	}
}
