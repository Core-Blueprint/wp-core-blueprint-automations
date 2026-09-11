<?php
declare(strict_types=1);

require __DIR__ . '/workflow-core.php';

use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityKind;
use CB\Automations\Discovery\ProviderStatus;
use CB\Automations\Validation\WorkflowValidator;

$contracts_work = ( new WorkflowValidator( source() ) )->validate( valid_definition() );
assert_true( $contracts_work->is_valid(), 'Contracts → Work proof workflow must remain valid.' );

$requests = 'core-blueprint-requests';
$crm      = 'core-blueprint-crm';

$request_approved = capability(
	CapabilityKind::TRIGGER,
	$requests,
	'request.approved',
	[],
	[
		'request_id' => field( 'integer', true ),
		'email'      => field( 'string', true ),
		'summary'    => field( 'string', false ),
	]
);

$contact_lookup = capability(
	CapabilityKind::STATE,
	$crm,
	'contact.by_email',
	[ 'email' => field( 'string', true ) ],
	[ 'contact_id' => field( 'integer', true ) ]
);

$add_activity = capability(
	CapabilityKind::ACTION,
	$crm,
	'contact.add_activity',
	[
		'contact_id' => field( 'integer', true ),
		'request_id' => field( 'integer', true ),
		'note'       => field( 'string', false ),
	],
	[ 'activity_id' => field( 'integer', true ) ]
);

$definitions = [
	'trigger:' . $requests . ':request.approved'    => $request_approved,
	'state:' . $crm . ':contact.by_email'           => $contact_lookup,
	'action:' . $crm . ':contact.add_activity'      => $add_activity,
];

$statuses = [
	$requests => ProviderStatus::from_inventory(
		$requests,
		[ 'name' => 'Requests', 'installed' => true, 'active' => true, 'registered' => true, 'compatible' => true ]
	),
	$crm => ProviderStatus::from_inventory(
		$crm,
		[ 'name' => 'CRM', 'installed' => true, 'active' => true, 'registered' => true, 'compatible' => true ]
	),
];

$source = new FakeCapabilitySource( $definitions, $statuses );
$definition = definition(
	step( 'trigger_1', reference( CapabilityKind::TRIGGER, $requests, 'request.approved' ) ),
	[
		step(
			'state_1',
			reference( CapabilityKind::STATE, $crm, 'contact.by_email' ),
			[ 'email' => Binding::step_output( 'trigger_1', 'email' ) ]
		),
	],
	[],
	[
		step(
			'action_1',
			reference( CapabilityKind::ACTION, $crm, 'contact.add_activity' ),
			[
				'contact_id' => Binding::step_output( 'state_1', 'contact_id' ),
				'request_id' => Binding::step_output( 'trigger_1', 'request_id' ),
				'note'       => Binding::step_output( 'trigger_1', 'summary' ),
			]
		),
	]
);

$result = ( new WorkflowValidator( $source ) )->validate( $definition );
assert_true( $result->is_valid(), 'Requests → CRM proof workflow must validate.' );

$encoded = \CB\Automations\Workflow\DefinitionCodec::encode( $definition );
$round_trip = \CB\Automations\Workflow\DefinitionCodec::encode(
	\CB\Automations\Workflow\DefinitionCodec::decode( $encoded )
);
assert_true( $round_trip === $encoded, 'Requests → CRM proof workflow must round-trip without semantic drift.' );

fwrite( STDOUT, "proof-workflows: PASS\n" );
