<?php
declare(strict_types=1);

/**
 * AU-2: read-only codec/projection fixtures and fail-closed stored-data save policy.
 * No WordPress DB access and no real secret values exposed by the save boundary.
 */
require __DIR__ . '/workflow-core.php';

use CB\Automations\Admin\EditorDefinitionProjection;
use CB\Automations\Admin\EditorSaveBoundary;
use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityKind;
use CB\Automations\Discovery\ProviderStatus;
use CB\Automations\Validation\WorkflowValidator;
use CB\Automations\Workflow\DefinitionCodec;
use CB\Automations\Workflow\PersistencePolicy;

$work = 'core-blueprint-work';
$available = ProviderStatus::from_inventory(
    $work,
    [ 'name' => 'Work', 'installed' => true, 'active' => true, 'registered' => true, 'compatible' => true ]
);
$action = capability(
    CapabilityKind::ACTION,
    $work,
    'privacy.save',
    [ 'api_key' => field( 'string', false, true ), 'label' => field( 'string', false, false ) ],
    []
);
$target = new FakeCapabilitySource(
    [ 'action:' . $work . ':privacy.save' => $action ],
    [ $work => $available ]
);
$original = definition(
    null,
    [],
    [],
    [ step(
        'action_1',
        reference( CapabilityKind::ACTION, $work, 'privacy.save' ),
        [ 'api_key' => Binding::literal( 'private-existing-value' ), 'label' => Binding::literal( 'visible' ) ]
    ) ]
);
$original_bytes = json_encode( DefinitionCodec::encode( $original ), JSON_THROW_ON_ERROR );
$projection = EditorDefinitionProjection::project(
    DefinitionCodec::encode( $original ),
    [ 'action:' . $work . ':privacy.save' => $action ]
);
$public_json = json_encode( $projection['definition'], JSON_THROW_ON_ERROR );
assert_true( 1 === $projection['redacted'], 'Sensitive stored action binding must be redacted.' );
assert_true( ! str_contains( $public_json, 'private-existing-value' ), 'Browser projection disclosed a sensitive stored value.' );

$submitted = DefinitionCodec::decode( $projection['definition'] );
assert_true(
    null === EditorSaveBoundary::reconcile( $original, $submitted, $target ),
    'AU2-01: a redacted stored binding must block a destructive full-definition replacement.'
);
assert_true(
    $original_bytes === json_encode( DefinitionCodec::encode( $original ), JSON_THROW_ON_ERROR ),
    'AU2-02: blocked save must not mutate stored in-memory definition.'
);

// Even an unrelated visible edit cannot declare hidden data deleted by omission.
$changed = $projection['definition'];
$changed['actions'][0]['bindings']['label']['value'] = 'new label';
assert_true(
    null === EditorSaveBoundary::reconcile( $original, DefinitionCodec::decode( $changed ), $target ),
    'AU2-03: a normal visible change must not drop an invisible stored value.'
);
assert_true(
    null === EditorSaveBoundary::reconcile( $original, definition( null, [], [], [] ), $target ),
    'AU2-04: entire-step removal must not silently delete an invisible stored value.'
);

// Rehydrating a redacted secret to work around the boundary violates existing persistence policy.
$validation = ( new WorkflowValidator( $target ) )->validate( $original );
assert_true( $validation->has_code( 'privacy.sensitive_literal' ), 'Sensitive stored literals must remain policy violations.' );
assert_true( ! PersistencePolicy::allows( $validation ), 'AU2-05: prohibited literal cannot be silently repersisted.' );

// A hidden condition must likewise be retained on storage, not silently removed.
$trigger = capability(
    CapabilityKind::TRIGGER,
    $work,
    'privacy.trigger',
    [],
    [ 'secret' => field( 'string', false, true ) ]
);
$source = new FakeCapabilitySource(
    [
        'trigger:' . $work . ':privacy.trigger' => $trigger,
        'action:' . $work . ':privacy.save' => $action,
    ],
    [ $work => $available ]
);
$with_condition = definition(
    step( 'trigger_1', reference( CapabilityKind::TRIGGER, $work, 'privacy.trigger' ) ),
    [],
    [
        condition(
            'condition_1',
            Binding::step_output( 'trigger_1', 'secret' ),
            'equals',
            Binding::literal( 'hidden-condition-value' )
        ),
    ],
    [ step( 'action_1', reference( CapabilityKind::ACTION, $work, 'privacy.save' ) ) ]
);
$server_projection = EditorDefinitionProjection::project(
    DefinitionCodec::encode( $with_condition ),
    [
        'trigger:' . $work . ':privacy.trigger' => $trigger,
        'action:' . $work . ':privacy.save' => $action,
    ]
);
assert_true( [] === $server_projection['definition']['conditions'], 'Sensitive condition must not appear in browser.' );
assert_true(
    null === EditorSaveBoundary::reconcile( $with_condition, DefinitionCodec::decode( $server_projection['definition'] ), $source ),
    'AU2-06: invisible stored condition cannot be discarded by ordinary Save.'
);

// No redactions: preserve canonical DefinitionCodec semantics and source order.
$clean = definition(
    null,
    [],
    [],
    [ step(
        'action_1',
        reference( CapabilityKind::ACTION, $work, 'privacy.save' ),
        [ 'label' => Binding::literal( 'visible' ) ]
    ) ]
);
$clean_changed = definition(
    null,
    [],
    [],
    [ step(
        'action_1',
        reference( CapabilityKind::ACTION, $work, 'privacy.save' ),
        [ 'label' => Binding::literal( 'edited' ) ]
    ) ]
);
assert_true(
    $clean_changed === EditorSaveBoundary::reconcile( $clean, $clean_changed, $target ),
    'AU2-07: unchanged visible-schema projection must allow the submitted canonical definition.'
);

// Missing, inactive, incompatible or version-drifted provider must fail closed.
$missing = new FakeCapabilitySource( [], [ $work => $available ] );
$inactive = ProviderStatus::from_inventory(
    $work,
    [ 'name' => 'Work', 'installed' => true, 'active' => false, 'registered' => false, 'compatible' => true ]
);
$disabled = new FakeCapabilitySource( [ 'action:' . $work . ':privacy.save' => $action ], [ $work => $inactive ] );
$new_version = capability(
    CapabilityKind::ACTION,
    $work,
    'privacy.save',
    [ 'label' => field( 'string' ) ],
    [],
    '2'
);
$drifted = new FakeCapabilitySource( [ 'action:' . $work . ':privacy.save' => $new_version ], [ $work => $available ] );
foreach ( [ $missing, $disabled, $drifted ] as $unsafe ) {
    assert_true(
        null === EditorSaveBoundary::reconcile( $clean, $clean_changed, $unsafe ),
        'AU2-08: missing, unavailable or version-drifted capability must block Save.'
    );
}

// A hostile capability provider exception must not reach the Save response/log.
$throwing = new class( $available ) implements \CB\Automations\Discovery\CapabilitySource {
    public function __construct( private \CB\Automations\Discovery\ProviderStatus $status ) {}
    public function current( \CB\Automations\Capability\CapabilityReference $reference ): ?\CB\Automations\Discovery\CapabilityDefinition {
        throw new RuntimeException( 'Sensitive provider diagnostic must not escape' );
    }
    public function provider_status( string $provider ): \CB\Automations\Discovery\ProviderStatus {
        return $this->status;
    }
};
assert_true(
    null === EditorSaveBoundary::reconcile( $clean, $clean_changed, $throwing ),
    'AU2-09: failed capability provider must not leak diagnostics or permit a write.'
);

// Empty draft has no provider whose schema could silently hide stored values.
$empty = definition( null, [], [], [] );
assert_true(
    $empty === EditorSaveBoundary::reconcile( $empty, $empty, $missing ),
    'AU2-10: empty draft remains authorable.'
);

// Source gate: the real service must retrieve and compare the actual stored revision
// before applying the editor save boundary, and preserve SQL CAS in Repository::update.
$service_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Workflow/WorkflowService.php' );
assert_true( is_string( $service_source ), 'Service source fixture unavailable.' );
$find = strpos( $service_source, 'WorkflowRepository::find( $id )' );
$version_check = strpos( $service_source, '$current->revision() !== $expected_revision' );
$save_guard = strpos( $service_source, 'EditorSaveBoundary::reconcile(' );
$update = strpos( $service_source, 'WorkflowRepository::update(' );
assert_true(
    false !== $find && false !== $version_check && false !== $save_guard && false !== $update
        && $find < $version_check && $version_check < $save_guard && $save_guard < $update,
    'AU2-11: stale revision or data projection must not bypass the save boundary/SQL CAS.'
);

fwrite( STDOUT, "Automations AU-2 editor save protection: PASS\n" );
