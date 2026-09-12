<?php
declare(strict_types=1);

require __DIR__ . '/workflow-core.php';

use CB\Automations\Admin\EditorDefinitionProjection;
use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityKind;
use CB\Automations\Discovery\ProviderStatus;
use CB\Automations\Validation\WorkflowValidator;
use CB\Automations\Workflow\DefinitionCodec;

$lms          = 'core-blueprint-lms';
$certificates = 'core-blueprint-certificates';

$course_completed = capability(
	CapabilityKind::TRIGGER,
	$lms,
	'course.completed',
	[],
	[
		'user_id'   => field( 'integer', true, false, null, 'wp.user_id' ),
		'course_id' => field( 'integer', true, false, null, 'core-blueprint-lms.course_id' ),
	]
);

$course_current = capability(
	CapabilityKind::STATE,
	$lms,
	'course.current',
	[
		'user_id'   => field( 'integer', true, false, null, 'wp.user_id' ),
		'course_id' => field( 'integer', true, false, null, 'core-blueprint-lms.course_id' ),
	],
	[
		'status'                 => field( 'string', true ),
		'percentage'             => field( 'integer', true ),
		'completed'              => field( 'boolean', true ),
		'certificate_profile_id' => field( 'integer', true, false, null, 'core-blueprint-certificates.profile_id' ),
		'completion_date'        => field( 'string', true, false, null, 'core-blueprint.completion_date' ),
		'course_title'           => field( 'string', true, false, null, 'core-blueprint.source_title' ),
		'completion_key'         => field( 'string', true, false, null, 'core-blueprint.idempotency_source_id' ),
	]
);

$certificate_issue = capability(
	CapabilityKind::ACTION,
	$certificates,
	'certificate.issue',
	[
		'profile_id'      => field( 'integer', true, false, null, 'core-blueprint-certificates.profile_id' ),
		'user_id'         => field( 'integer', true, false, null, 'wp.user_id' ),
		'completion_date' => field( 'string', true, false, null, 'core-blueprint.completion_date' ),
		'title'           => field( 'string', false, false, null, 'core-blueprint.source_title' ),
		'source_type'     => field( 'string', true, false, null, 'core-blueprint.idempotency_source_type' ),
		'source_id'       => field( 'string', true, false, null, 'core-blueprint.idempotency_source_id' ),
	],
	[
		'certificate_id'     => field( 'integer', true ),
		'certificate_uuid'   => field( 'string', true ),
		'certificate_number' => field( 'string', true ),
		'verification_url'   => field( 'string', true ),
		'status'             => field( 'string', true ),
		'created'            => field( 'boolean', true ),
	]
);

assert_true( 'wp.user_id' === ( $course_completed->output_schema()['user_id']['semantic_type'] ?? null ), 'Golden LMS trigger user_id semantic identity drifted.' );
assert_true( 'core-blueprint-lms.course_id' === ( $course_completed->output_schema()['course_id']['semantic_type'] ?? null ), 'Golden LMS trigger course_id semantic identity drifted.' );
assert_true( 'core-blueprint-certificates.profile_id' === ( $course_current->output_schema()['certificate_profile_id']['semantic_type'] ?? null ), 'Golden LMS profile semantic identity drifted.' );
assert_true( 'core-blueprint.completion_date' === ( $course_current->output_schema()['completion_date']['semantic_type'] ?? null ), 'Golden completion date semantic identity drifted.' );
assert_true( 'core-blueprint.source_title' === ( $course_current->output_schema()['course_title']['semantic_type'] ?? null ), 'Golden source title semantic identity drifted.' );
assert_true( 'core-blueprint.idempotency_source_id' === ( $course_current->output_schema()['completion_key']['semantic_type'] ?? null ), 'Golden completion key semantic identity drifted.' );
assert_true( 'core-blueprint-certificates.profile_id' === ( $certificate_issue->input_schema()['profile_id']['semantic_type'] ?? null ), 'Golden Certificates profile semantic identity drifted.' );
assert_true( 'wp.user_id' === ( $certificate_issue->input_schema()['user_id']['semantic_type'] ?? null ), 'Golden Certificates user semantic identity drifted.' );
assert_true( 'core-blueprint.completion_date' === ( $certificate_issue->input_schema()['completion_date']['semantic_type'] ?? null ), 'Golden Certificates completion date semantic identity drifted.' );
assert_true( 'core-blueprint.source_title' === ( $certificate_issue->input_schema()['title']['semantic_type'] ?? null ), 'Golden Certificates title semantic identity drifted.' );
assert_true( 'core-blueprint.idempotency_source_type' === ( $certificate_issue->input_schema()['source_type']['semantic_type'] ?? null ), 'Golden Certificates source type semantic identity drifted.' );
assert_true( 'core-blueprint.idempotency_source_id' === ( $certificate_issue->input_schema()['source_id']['semantic_type'] ?? null ), 'Golden Certificates source id semantic identity drifted.' );

$definitions = [
	'trigger:' . $lms . ':course.completed'         => $course_completed,
	'state:' . $lms . ':course.current'              => $course_current,
	'action:' . $certificates . ':certificate.issue' => $certificate_issue,
];

$statuses = [
	$lms => ProviderStatus::from_inventory(
		$lms,
		[ 'name' => 'LMS', 'installed' => true, 'active' => true, 'registered' => true, 'compatible' => true ]
	),
	$certificates => ProviderStatus::from_inventory(
		$certificates,
		[ 'name' => 'Certificates', 'installed' => true, 'active' => true, 'registered' => true, 'compatible' => true ]
	),
];

$source = new FakeCapabilitySource( $definitions, $statuses );
$definition = definition(
	step( 'trigger_1', reference( CapabilityKind::TRIGGER, $lms, 'course.completed' ) ),
	[
		step(
			'state_1',
			reference( CapabilityKind::STATE, $lms, 'course.current' ),
			[
				'user_id'   => Binding::step_output( 'trigger_1', 'user_id' ),
				'course_id' => Binding::step_output( 'trigger_1', 'course_id' ),
			]
		),
	],
	[
		condition(
			'condition_1',
			Binding::step_output( 'state_1', 'certificate_profile_id' ),
			'greater_than',
			Binding::literal( 0 )
		),
	],
	[
		step(
			'action_1',
			reference( CapabilityKind::ACTION, $certificates, 'certificate.issue' ),
			[
				'profile_id'      => Binding::step_output( 'state_1', 'certificate_profile_id' ),
				'user_id'         => Binding::step_output( 'trigger_1', 'user_id' ),
				'completion_date' => Binding::step_output( 'state_1', 'completion_date' ),
				'title'           => Binding::step_output( 'state_1', 'course_title' ),
				'source_type'     => Binding::literal( 'cb_lms' ),
				'source_id'       => Binding::step_output( 'state_1', 'completion_key' ),
			]
		),
	]
);

$result = ( new WorkflowValidator( $source ) )->validate( $definition );
assert_true( $result->is_valid(), 'LMS → Certificates golden provider workflow must validate.' );
assert_true( ! $result->contains_sensitive_paths(), 'Golden provider workflow should not persist sensitive paths.' );

$encoded = DefinitionCodec::encode( $definition );
$round_trip = DefinitionCodec::encode( DefinitionCodec::decode( $encoded ) );
assert_true( $round_trip === $encoded, 'Golden provider workflow must round-trip without semantic drift.' );

$projection = EditorDefinitionProjection::project( $encoded, $definitions );
assert_true( 0 === $projection['redacted'], 'Golden provider workflow must reopen without editor redaction.' );
assert_true( $encoded === $projection['definition'], 'Golden provider workflow must reach the editor without semantic drift.' );

$browser_json = json_encode( $projection['definition'], JSON_THROW_ON_ERROR );
$rehydrated = json_decode( $browser_json, true, 512, JSON_THROW_ON_ERROR );
assert_true( is_array( $rehydrated ) && $rehydrated === $encoded, 'Golden provider workflow must survive the server-to-browser JSON handoff exactly.' );
assert_true(
	DefinitionCodec::encode( DefinitionCodec::decode( $rehydrated ) ) === $encoded,
	'Golden provider workflow must survive save → reopen → editor rehydrate without semantic drift.'
);

assert_true(
	'core-blueprint-lms' === ( $encoded['trigger']['capability']['provider'] ?? null )
	&& 'course.completed' === ( $encoded['trigger']['capability']['id'] ?? null ),
	'Golden workflow must persist the canonical LMS trigger reference.'
);
assert_true(
	'core-blueprint-certificates' === ( $encoded['actions'][0]['capability']['provider'] ?? null )
	&& 'certificate.issue' === ( $encoded['actions'][0]['capability']['id'] ?? null ),
	'Golden workflow must persist the canonical Certificates action reference.'
);
assert_true(
	'cb_lms' === ( $encoded['actions'][0]['bindings']['source_type']['value'] ?? null ),
	'Golden workflow must preserve the native LMS certificate source type so issuance stays idempotent.'
);

fwrite( STDOUT, "golden-provider-set: PASS\n" );
