<?php
declare(strict_types=1);

require __DIR__ . '/workflow-core.php';

use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityKind;
use CB\Automations\Discovery\ProviderStatus;
use CB\Automations\Validation\ValidationState;
use CB\Automations\Validation\WorkflowValidator;
use CB\Automations\Workflow\ActivationPolicy;
use CB\Automations\Workflow\ActivationState;
use CB\Automations\Workflow\PersistencePolicy;

$provider = 'core-blueprint-work';

$trigger = capability(
	CapabilityKind::TRIGGER,
	$provider,
	'privacy.trigger',
	[],
	[ 'value' => field( 'string', true ) ]
);

$action = capability(
	CapabilityKind::ACTION,
	$provider,
	'privacy.action',
	[ 'secret' => field( 'string', true, true ) ],
	[]
);

$source = new FakeCapabilitySource(
	[
		$trigger->reference()->kind() . ':' . $provider . ':privacy.trigger' => $trigger,
		$action->reference()->kind() . ':' . $provider . ':privacy.action'   => $action,
	],
	[
		$provider => ProviderStatus::from_inventory(
			$provider,
			[
				'name'       => 'Work',
				'installed'  => true,
				'active'     => true,
				'registered' => true,
				'compatible' => true,
			]
		),
	]
);

$literal_definition = definition(
	step( 'trigger_1', reference( CapabilityKind::TRIGGER, $provider, 'privacy.trigger' ) ),
	[],
	[],
	[
		step(
			'action_1',
			reference( CapabilityKind::ACTION, $provider, 'privacy.action' ),
			[ 'secret' => Binding::literal( 'must-not-be-persisted' ) ]
		),
	]
);

$literal_result = ( new WorkflowValidator( $source ) )->validate( $literal_definition );
assert_code( $literal_result, 'privacy.sensitive_literal', 'Sensitive literal input must be rejected.' );
assert_true( ValidationState::Invalid === ValidationState::from_result( $literal_result ), 'Sensitive literal must make live validation invalid.' );
assert_true( ! PersistencePolicy::allows( $literal_result ), 'Sensitive literal must be blocked from persistence.' );
assert_true( ! ActivationPolicy::allows( ActivationState::Enabled, $literal_result ), 'Sensitive literal must block activation.' );
assert_true( ActivationPolicy::allows( ActivationState::Disabled, $literal_result ), 'Activation policy alone may still permit disabled drafts; persistence policy is the privacy gate.' );

$reference_definition = definition(
	step( 'trigger_1', reference( CapabilityKind::TRIGGER, $provider, 'privacy.trigger' ) ),
	[],
	[],
	[
		step(
			'action_1',
			reference( CapabilityKind::ACTION, $provider, 'privacy.action' ),
			[ 'secret' => Binding::step_output( 'trigger_1', 'value' ) ]
		),
	]
);

$reference_result = ( new WorkflowValidator( $source ) )->validate( $reference_definition );
assert_true( $reference_result->is_valid(), 'Reference binding into a sensitive input should remain structurally valid.' );
assert_true( PersistencePolicy::allows( $reference_result ), 'Reference binding must remain persistable because no secret value is stored.' );

fwrite( STDOUT, "privacy-policy: PASS\n" );
