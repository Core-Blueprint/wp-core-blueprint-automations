<?php
declare(strict_types=1);

namespace CB\Automations\Admin;

use CB\Automations\Validation\ValidationIssue;

defined( 'ABSPATH' ) || exit;

final class ValidationPresenter {
	public static function message( ValidationIssue $issue ): string {
		$context = $issue->context();

		return match ( $issue->code() ) {
			'workflow.trigger_missing' => __( 'Choose a trigger before enabling this automation.', 'core-blueprint-automations' ),
			'workflow.action_missing' => __( 'Add at least one action before enabling this automation.', 'core-blueprint-automations' ),
			'dependency.provider_inactive' => sprintf(
				/* translators: %s: provider id. */
				__( 'Provider %s is installed but inactive.', 'core-blueprint-automations' ),
				(string) ( $context['provider'] ?? '' )
			),
			'dependency.provider_incompatible' => sprintf(
				/* translators: %s: provider id. */
				__( 'Provider %s is not compatible with the active Core Blueprint API.', 'core-blueprint-automations' ),
				(string) ( $context['provider'] ?? '' )
			),
			'dependency.provider_unregistered', 'dependency.provider_unavailable' => sprintf(
				/* translators: %s: provider id. */
				__( 'Provider %s is unavailable.', 'core-blueprint-automations' ),
				(string) ( $context['provider'] ?? '' )
			),
			'capability.missing' => sprintf(
				/* translators: 1: provider id, 2: capability id. */
				__( 'Capability %1$s / %2$s is no longer available.', 'core-blueprint-automations' ),
				(string) ( $context['provider'] ?? '' ),
				(string) ( $context['id'] ?? '' )
			),
			'capability.schema_mismatch' => sprintf(
				/* translators: 1: stored schema version, 2: current schema version. */
				__( 'Capability schema changed from version %1$s to %2$s. Review this step before enabling it again.', 'core-blueprint-automations' ),
				(string) ( $context['stored'] ?? '' ),
				(string) ( $context['current'] ?? '' )
			),
			'capability.kind_mismatch' => __( 'A workflow step uses the wrong capability type.', 'core-blueprint-automations' ),
			'binding.required_missing' => sprintf(
				/* translators: %s: input field. */
				__( 'Required input “%s” is not connected.', 'core-blueprint-automations' ),
				(string) ( $context['field'] ?? '' )
			),
			'binding.input_unknown' => sprintf(
				/* translators: %s: input field. */
				__( 'Input “%s” is no longer declared by this capability.', 'core-blueprint-automations' ),
				(string) ( $context['field'] ?? '' )
			),
			'binding.source_missing' => __( 'A binding points to a workflow step that no longer exists.', 'core-blueprint-automations' ),
			'binding.source_not_available_yet' => __( 'A binding points to a step that runs later in the workflow.', 'core-blueprint-automations' ),
			'binding.field_missing' => __( 'A binding points to an output field that no longer exists.', 'core-blueprint-automations' ),
			'binding.type_mismatch' => __( 'A binding value does not match the declared input type.', 'core-blueprint-automations' ),
			'binding.trigger_has_bindings' => __( 'Triggers cannot declare input bindings.', 'core-blueprint-automations' ),
			'condition.operator_unsupported' => __( 'A condition uses an unsupported operator.', 'core-blueprint-automations' ),
			'condition.arity_mismatch' => __( 'A condition has the wrong number of operands for its operator.', 'core-blueprint-automations' ),
			'condition.type_mismatch' => __( 'A condition compares incompatible value types.', 'core-blueprint-automations' ),
			'privacy.sensitive_literal' => __( 'Sensitive inputs cannot contain persisted literal values. Connect them to a prior workflow output instead.', 'core-blueprint-automations' ),
			default => __( 'This workflow contains a validation issue that requires review.', 'core-blueprint-automations' ),
		};
	}
}
