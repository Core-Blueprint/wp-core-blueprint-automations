<?php
declare(strict_types=1);

/**
 * Golden-R1: never serialize literals whose sensitivity cannot be proven
 * against a current capability schema. A redaction is not a deletion.
 */
require __DIR__ . '/workflow-core.php';

use CB\Automations\Admin\EditorDefinitionProjection;
use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityKind;
use CB\Automations\Workflow\DefinitionCodec;

$provider = 'core-blueprint-work';
$definition = definition(
	step( 'trigger_1', reference( CapabilityKind::TRIGGER, 'core-blueprint-contracts', 'contract.signed' ) ),
	[],
	[ condition( 'condition_1', Binding::step_output( 'trigger_1', 'secret' ), 'equals', Binding::literal( 'private-condition' ) ) ],
	[ step( 'action_1', reference( CapabilityKind::ACTION, $provider, 'project.create' ), [
		'unrecognized' => Binding::literal( 'private-binding' ),
		'project_id' => Binding::step_output( 'trigger_1', 'contract_id' ),
	] ) ]
);
$encoded = DefinitionCodec::encode( $definition );

// With the provider absent even an input field's sensitivity is unknown.
$missing = EditorDefinitionProjection::project( $encoded, [] );
$visible = json_encode( $missing['definition'], JSON_THROW_ON_ERROR );
assert_true( ! str_contains( $visible, 'private-binding' ), 'Missing action schema exposed a literal.' );
assert_true( ! str_contains( $visible, 'private-condition' ), 'Missing output schema exposed a condition literal.' );
assert_true( [] === $missing['definition']['conditions'], 'Unsafe condition must be omitted as a whole.' );
assert_true( $missing['redacted'] >= 2, 'Redactions must be reported so saves can be fenced.' );

// Existing provider with an absent input schema key is also unverified.
$catalog = source();
$trigger = $catalog->current( reference( CapabilityKind::TRIGGER, 'core-blueprint-contracts', 'contract.signed' ) );
$action = $catalog->current( reference( CapabilityKind::ACTION, $provider, 'project.create' ) );
assert_true( null !== $trigger && null !== $action, 'Realistic capability fixtures missing.' );
$known = EditorDefinitionProjection::project( $encoded, [
	'trigger:core-blueprint-contracts:contract.signed' => $trigger,
	'action:' . $provider . ':project.create' => $action,
] );
$known_json = json_encode( $known['definition'], JSON_THROW_ON_ERROR );
assert_true( ! str_contains( $known_json, 'private-binding' ), 'Unknown input field exposed a literal.' );
assert_true( ! str_contains( $known_json, 'private-condition' ), 'Known sensitive output exposed a literal.' );
assert_true( isset( $known['definition']['actions'][0]['bindings']['project_id'] ), 'Non-literal references must be preserved.' );

$safe = definition( null, [], [], [ step(
	'action_1', reference( CapabilityKind::ACTION, $provider, 'project.create' ),
	[ 'legacy_project_id' => Binding::literal( 123 ) ]
) ] );
$projected_safe = EditorDefinitionProjection::project( DefinitionCodec::encode( $safe ), [
	'action:' . $provider . ':project.create' => $action,
] );
assert_true( 0 === $projected_safe['redacted'], 'Verified nonsensitive literal must stay visible.' );
assert_true( 123 === $projected_safe['definition']['actions'][0]['bindings']['legacy_project_id']['value'], 'Verified safe literal changed.' );

fwrite( STDOUT, "Golden-R1 unverified-literal browser projection: PASS\n" );
