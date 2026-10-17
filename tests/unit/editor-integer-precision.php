<?php
declare(strict_types=1);

require __DIR__ . '/workflow-core.php';

use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityKind;
use CB\Automations\Workflow\DefinitionCodec;
use CB\Automations\Workflow\EditorIntegerSafety;

$provider = 'core-blueprint-work';
$make = static function ( mixed $value, bool $condition = false ) use ( $provider ): \CB\Automations\Workflow\Definition {
    $trigger = step( 'trigger_1', reference( CapabilityKind::TRIGGER, $provider, 'demo.trigger' ) );
    $action = step(
        'action_1',
        reference( CapabilityKind::ACTION, $provider, 'demo.action' ),
        [ 'input' => Binding::literal( $value ) ]
    );
    $conditions = $condition
        ? [ \condition( 'condition_1', Binding::step_output( 'trigger_1', 'id' ), 'equals', Binding::literal( $value ) ) ]
        : [];
    return definition( $trigger, [], $conditions, [ $action ] );
};
$int_max = 9007199254740991;
$int_out = $int_max + 1;
$negative_out = -$int_out;

foreach ( [ 0, 1, -1, $int_max, -$int_max, [ $int_max, -$int_max, 0 ], '9007199254740993', [ '9007199254740993', '7' ], 12.5, true ] as $safe ) {
    $definition = $make( $safe );
    assert_true( EditorIntegerSafety::is_browser_safe( $definition ), 'AU3-01: safe scalar/array/string must not be blocked.' );
    $json = json_encode( DefinitionCodec::encode( $definition ), JSON_THROW_ON_ERROR );
    $decoded = DefinitionCodec::decode( json_decode( $json, true, 64, JSON_THROW_ON_ERROR ) );
    assert_true( DefinitionCodec::encode( $definition ) === DefinitionCodec::encode( $decoded ), 'AU3-02: safe codec roundtrip changed type/value.' );
}
foreach ( [ $int_out, $negative_out, PHP_INT_MAX, PHP_INT_MIN, [ 1, $int_out ], [ $negative_out, 2 ], 1.0, 1.0e20 ] as $unsafe ) {
    assert_true( ! EditorIntegerSafety::is_browser_safe( $make( $unsafe ) ), 'AU3-03: unsafe integer/array/number must be blocked.' );
}
assert_true( ! EditorIntegerSafety::is_browser_safe( $make( $int_out, true ) ), 'AU3-04: condition operands must be precision-checked.' );
assert_true( EditorIntegerSafety::is_browser_safe( $make( '9223372036854775807', true ) ), 'AU3-05: numeric strings are not integers.' );

// Large numeric JSON values beyond PHP_INT_MAX decode as floats by default.
// They must not be accidentally accepted as a different number type.
$large = json_decode( '{"source":"literal","value":92233720368547758070}', true, 64, JSON_THROW_ON_ERROR );
assert_true( is_float( $large['value'] ), 'AU3-06: hostile JSON fixture must exercise floating overflow.' );
assert_true( ! EditorIntegerSafety::is_browser_safe( $make( $large['value'] ) ), 'AU3-07: overflowed JSON integer must fail closed.' );

// Source gate: the existing read-only recovery view must be selected BEFORE
// WorkflowEditorData builds or templates expose the raw workflow definition.
$root = dirname( __DIR__, 2 );
$page = file_get_contents( $root . '/src/Admin/AutomationsPage.php' );
$service = file_get_contents( $root . '/src/Workflow/WorkflowService.php' );
$client = file_get_contents( $root . '/assets/js/admin-editor.js' );
assert_true( is_string( $page ) && is_string( $service ) && is_string( $client ), 'AU3 sources could not be read.' );
$page_guard = strpos( $page, 'EditorIntegerSafety::is_browser_safe(' );
$editor_data = strpos( $page, 'WorkflowEditorData::build(' );
assert_true( false !== $page_guard && false !== $editor_data && $page_guard < $editor_data, 'AU3-08: unsafe saved integer reached browser editor data.' );
$service_guard = strpos( $service, 'EditorIntegerSafety::is_browser_safe(' );
$write = strpos( $service, 'WorkflowRepository::update(' );
assert_true( false !== $service_guard && false !== $write && $service_guard < $write, 'AU3-09: precision was not checked before Save.' );
assert_true( ! str_contains( $client, 'Number.parseInt(' ), 'AU3-10: unsafe parseInt must be eliminated from live editor.' );

fwrite( STDOUT, "Automations AU-3 integer precision: PASS\n" );
