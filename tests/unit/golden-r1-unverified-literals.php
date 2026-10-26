<?php
declare(strict_types=1);

/**
 * Golden-R1: unresolved provider contracts must never persist unverified literals.
 * Non-literal references and safe disabled drafts remain persistable.
 */
require __DIR__ . '/workflow-core.php';

use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityKind;
use CB\Automations\Validation\WorkflowValidator;
use CB\Automations\Workflow\PersistencePolicy;

$provider = 'core-blueprint-work';
$known = source();
$missing_action = source( true, false );
$inactive = source( false );

$literal_action = definition( null, [], [], [
	step( 'action_1', reference( CapabilityKind::ACTION, $provider, 'project.create' ), [
		'secret' => Binding::literal( 'operator-provided-value' ),
	] ),
] );
foreach ( [ $missing_action, $inactive ] as $catalog ) {
	$validation = ( new WorkflowValidator( $catalog ) )->validate( $literal_action );
	assert_code( $validation, 'privacy.unverified_literal', 'Unknown action schema must reject literal storage.' );
	assert_true( ! PersistencePolicy::allows( $validation ), 'Unverified action literal may not persist, even while disabled.' );
}
$reference_only = definition(
	step( 'trigger_1', reference( CapabilityKind::TRIGGER, 'core-blueprint-contracts', 'contract.signed' ) ),
	[],
	[],
	[ step( 'action_1', reference( CapabilityKind::ACTION, $provider, 'project.create' ), [
		'secret' => Binding::step_output( 'trigger_1', 'status' ),
	] ) ]
);
$reference_validation = ( new WorkflowValidator( $missing_action ) )->validate( $reference_only );
assert_true( PersistencePolicy::allows( $reference_validation ), 'Missing capability with reference-only bindings must remain a saveable disabled draft.' );

$unknown_input = definition( null, [], [], [
	step( 'action_1', reference( CapabilityKind::ACTION, $provider, 'project.followup' ), [
		'unregistered_private_field' => Binding::literal( 'operator-value' ),
	] ),
] );
$unknown_validation = ( new WorkflowValidator( $known ) )->validate( $unknown_input );
assert_code( $unknown_validation, 'binding.input_unknown', 'Unknown input schema must be indicated.' );
assert_code( $unknown_validation, 'privacy.unverified_literal', 'Unknown literal input must not pass the privacy gate.' );
assert_true( ! PersistencePolicy::allows( $unknown_validation ), 'Unknown input literal was persistable.' );

$condition_with_missing_source = definition(
	step( 'trigger_1', reference( CapabilityKind::TRIGGER, 'core-blueprint-contracts', 'contract.signed', '2' ) ),
	[],
	[ condition( 'condition_1', Binding::step_output( 'trigger_1', 'secret' ), 'equals', Binding::literal( 'potential-secret' ) ) ],
	[]
);
$condition_result = ( new WorkflowValidator( $known ) )->validate( $condition_with_missing_source );
assert_code( $condition_result, 'capability.schema_mismatch', 'Test fixture must have an unresolved trigger.' );
assert_code( $condition_result, 'privacy.unverified_literal', 'Unknown condition operand sensitivity must block adjacent literal.' );
assert_true( ! PersistencePolicy::allows( $condition_result ), 'Unverified condition literal could persist.' );

$safe_literal = definition( null, [], [], [
	step( 'action_1', reference( CapabilityKind::ACTION, $provider, 'project.create' ), [
		'legacy_project_id' => Binding::literal( 123 ),
	] ),
] );
assert_true(
	PersistencePolicy::allows( ( new WorkflowValidator( $known ) )->validate( $safe_literal ) ),
	'A verified non-sensitive literal must remain persistable.'
);

$empty = definition( null, [], [], [] );
assert_true(
	PersistencePolicy::allows( ( new WorkflowValidator( $missing_action ) )->validate( $empty ) ),
	'Empty disabled drafts must remain persistable.'
);

fwrite( STDOUT, "Golden-R1 unverified literal privacy: PASS\n" );
