<?php
declare(strict_types=1);

require __DIR__ . '/workflow-core.php';

use CB\Automations\Admin\EditorDefinitionProjection;
use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityKind;
use CB\Automations\Workflow\DefinitionCodec;

$provider = 'core-blueprint-work';
$trigger_capability = capability(
	CapabilityKind::TRIGGER,
	$provider,
	'privacy.trigger',
	[],
	[
		'secret' => field( 'string', true, true ),
		'public' => field( 'string', false, false ),
	]
);
$action_capability = capability(
	CapabilityKind::ACTION,
	$provider,
	'privacy.action',
	[
		'api_key' => field( 'string', false, true ),
		'label'   => field( 'string', false, false ),
	],
	[]
);

$definition = definition(
	step( 'trigger_1', reference( CapabilityKind::TRIGGER, $provider, 'privacy.trigger' ) ),
	[],
	[
		condition(
			'condition_1',
			Binding::step_output( 'trigger_1', 'secret' ),
			'equals',
			Binding::literal( 'stored-comparison-secret' )
		),
	],
	[
		step(
			'action_1',
			reference( CapabilityKind::ACTION, $provider, 'privacy.action' ),
			[
				'api_key' => Binding::literal( 'stored-api-key' ),
				'label'   => Binding::literal( 'keep-me-visible' ),
			]
		),
	]
);

$stored = DefinitionCodec::encode( $definition );
$original = $stored;
$projection = EditorDefinitionProjection::project(
	$stored,
	[
		'trigger:' . $provider . ':privacy.trigger' => $trigger_capability,
		'action:' . $provider . ':privacy.action'   => $action_capability,
	]
);

assert_true( 2 === $projection['redacted'], 'Editor projection should redact both sensitive literal paths.' );
assert_true( $stored === $original, 'Editor projection must not mutate the stored definition argument.' );
assert_true( [] === $projection['definition']['conditions'], 'Sensitive literal comparison condition must not be reflected to the browser.' );
assert_true( ! isset( $projection['definition']['actions'][0]['bindings']['api_key'] ), 'Sensitive literal action input must not be reflected to the browser.' );
assert_true(
	'keep-me-visible' === ( $projection['definition']['actions'][0]['bindings']['label']['value'] ?? null ),
	'Non-sensitive literal bindings must remain in the editor projection.'
);

fwrite( STDOUT, "editor-projection: PASS\n" );
