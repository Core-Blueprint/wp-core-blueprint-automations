<?php
declare(strict_types=1);

// PHP -> browser -> PHP contract fixture. A machine-readable fixture is used
// by the Node test to exercise the actual emitted JSON and public JS adapter.
$fixture = '1' === getenv( 'CB_AUTOMATIONS_BROWSER_BINDINGS_FIXTURE' );
if ( $fixture ) {
	ob_start();
}
require __DIR__ . '/workflow-core.php';
if ( $fixture ) {
	ob_end_clean();
}

use CB\Automations\Admin\EditorDefinitionProjection;
use CB\Automations\Admin\WorkflowEditorData;
use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityKind;
use CB\Automations\Workflow\DefinitionCodec;

$provider = 'core-blueprint-work';
$trigger = step( 'trigger_1', reference( CapabilityKind::TRIGGER, $provider, 'fixture.trigger' ) );
$state = step( 'state_1', reference( CapabilityKind::STATE, $provider, 'fixture.state' ) );
$action = step( 'action_1', reference( CapabilityKind::ACTION, $provider, 'fixture.action' ), [
	'items' => Binding::literal( [] ),
	'label' => Binding::literal( 'retain-me' ),
] );
$stored = DefinitionCodec::encode( definition( $trigger, [ $state ], [], [ $action ] ) );
$original = $stored;

// This is the privacy-sanitized definition, never raw private storage.
$projection = EditorDefinitionProjection::project( $stored, [] );
assert_true( 0 === $projection['redacted'], 'Ordinary fixture should not be redacted.' );
assert_true( [] === $projection['definition']['trigger']['bindings'], 'RED fixture: PHP encodes empty trigger bindings as a JSON list.' );
assert_true( [] === $projection['definition']['states'][0]['bindings'], 'RED fixture: PHP encodes empty state bindings as a JSON list.' );

$normalize = new ReflectionMethod( WorkflowEditorData::class, 'browser_definition' );
$browser = $normalize->invoke( null, $projection['definition'] );

assert_true( $browser['trigger']['bindings'] instanceof stdClass, 'Empty trigger bindings must be emitted as JSON object.' );
assert_true( $browser['states'][0]['bindings'] instanceof stdClass, 'Empty state bindings must be emitted as JSON object.' );
assert_true( [] === (array) $browser['trigger']['bindings'], 'Empty trigger object must remain empty.' );
assert_true( [] === (array) $browser['states'][0]['bindings'], 'Empty state object must remain empty.' );
assert_true( $browser['actions'][0]['bindings'] === $stored['actions'][0]['bindings'], 'Non-empty bindings and array literals must remain identical.' );
assert_true( [] === $browser['actions'][0]['bindings']['items']['value'], 'Empty literal arrays must NOT become objects.' );
assert_true( $stored === $original, 'Persistent canonical workflow must remain unchanged.' );
assert_true( $projection['definition'] === $stored, 'Privacy projection input must not be mutated.' );

$json = json_encode( $browser, JSON_THROW_ON_ERROR );
assert_true( str_contains( $json, '"bindings":{}' ), 'Emitted browser JSON must contain object-shaped empty bindings.' );
$roundtrip = DefinitionCodec::decode( json_decode( $json, true, 64, JSON_THROW_ON_ERROR ) );
assert_true( DefinitionCodec::encode( $roundtrip ) === $stored, 'Browser JSON must roundtrip to the exact stored PHP Definition.' );

// After sensitive field suppression, a formerly nonempty map can become empty;
// that output must also be object-shaped without restoring the secret.
$sensitive_action = capability( CapabilityKind::ACTION, $provider, 'fixture.action', [
	'secret' => field( 'string', false, true ),
], [] );
$private = DefinitionCodec::encode( definition( null, [], [], [
	step( 'action_1', reference( CapabilityKind::ACTION, $provider, 'fixture.action' ), [
		'secret' => Binding::literal( 'not-for-the-browser' ),
	] ),
] ) );
$redacted = EditorDefinitionProjection::project( $private, [ 'action:' . $provider . ':fixture.action' => $sensitive_action ] );
assert_true( 1 === $redacted['redacted'], 'Sensitive value must be redacted before browser shaping.' );
$private_browser = $normalize->invoke( null, $redacted['definition'] );
$private_json = json_encode( $private_browser, JSON_THROW_ON_ERROR );
assert_true( ! str_contains( $private_json, 'not-for-the-browser' ), 'Sensitive literal must not be exposed.' );
assert_true( $private_browser['actions'][0]['bindings'] instanceof stdClass, 'Redacted empty map must serialize as object.' );
assert_true( 'not-for-the-browser' === $private['actions'][0]['bindings']['secret']['value'], 'Server-side sensitive stored value must remain untouched.' );

// Deliberately malformed nonempty list must remain invalid, not be coerced.
$malformed = $stored;
$malformed['states'][0]['bindings'] = [ [ 'source' => 'literal', 'value' => 'bad' ] ];
$untrusted = $normalize->invoke( null, $malformed );
assert_true( [ [ 'source' => 'literal', 'value' => 'bad' ] ] === $untrusted['states'][0]['bindings'], 'Nonempty malformed lists must fail closed downstream.' );

if ( $fixture ) {
	echo $json;
} else {
	fwrite( STDOUT, "Automations Designer browser bindings PHP roundtrip/privacy: PASS\n" );
}
