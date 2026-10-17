<?php
declare(strict_types=1);

/**
 * AU-R1A contract v1.1: pure, server-only, test-first recovery planner.
 * Must run directly: current CI lists individual PHP entrypoints.
 */
require __DIR__ . '/workflow-core.php';

use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityKind;
use CB\Automations\Condition\Condition;
use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\DefinitionCodec;
use CB\Automations\Workflow\EditorIntegerSafety;
use CB\Automations\Workflow\PersistencePolicy;
use CB\Automations\Workflow\Recovery\WorkflowRecoveryPlanner;
use CB\Automations\Validation\WorkflowValidator;

$provider = 'core-blueprint-work';
$trigger = capability(
    CapabilityKind::TRIGGER, $provider, 'recovery.trigger', [],
    [
        'secret' => field( 'string', false, true ),
        'id'     => field( 'integer', false, false, null, 'external.identifier' ),
        'label'  => field( 'string' ),
    ]
);
$action = capability(
    CapabilityKind::ACTION, $provider, 'recovery.action',
    [
        'secret_a' => field( 'string', false, true ),
        'secret_b' => field( 'string', false, true ),
        'label' => field( 'string' ),
        'decimal' => field( 'string' ),
        'items' => field( 'array', false, false, 'string' ),
        'external_id' => field( 'integer', false, false, null, 'external.identifier' ),
        'copy_id' => field( 'integer', false, false, null, 'external.identifier' ),
    ], []
);
$make_snapshot = static function ( bool $available = true, bool $drift = false ) use ( $provider, $trigger, $action ): array {
    $entries = [];
    foreach ( [ $trigger, $action ] as $cap ) {
        $ref = $cap->reference()->to_array();
        if ( $drift && 'action' === $ref['kind'] ) {
            $ref['schema_version'] = '2';
        }
        $entries[ implode( ':', [ $ref['kind'], $ref['provider'], $ref['id'], $ref['schema_version'] ] ) ] = [
            'reference' => $ref,
            'input_schema' => $cap->input_schema(),
            'output_schema' => $cap->output_schema(),
            'required_capability' => $cap->required_capability(),
        ];
    }
    return [
        'snapshot_id' => str_repeat( 'a', 64 ),
        'providers' => [ $provider => [ 'available' => $available ] ],
        'capabilities' => $entries,
    ];
};
$snapshot = $make_snapshot();

$workflow = static function ( array $bindings = [], array $conditions = [] ) use ( $provider ): Definition {
    return definition(
        step( 'trigger_1', reference( CapabilityKind::TRIGGER, $provider, 'recovery.trigger' ) ),
        [],
        $conditions,
        [ step( 'action_1', reference( CapabilityKind::ACTION, $provider, 'recovery.action' ), $bindings ) ]
    );
};

$target = static fn ( string $field ): array => [
    'collection' => 'actions', 'step_id' => 'action_1', 'field' => $field,
];
$remove = static fn ( string $id, string $field, Binding $expected ): array => [
    'intent_id' => $id, 'operation' => 'remove_sensitive_binding',
    'target' => $target( $field ), 'expected' => $expected,
    'replacement' => null, 'confirm' => true,
];
$replace = static fn ( string $id, string $op, array $selector, Binding $expected, ?Binding $replacement ): array => [
    'intent_id' => $id, 'operation' => $op, 'target' => $selector,
    'expected' => $expected, 'replacement' => $replacement, 'confirm' => true,
];
$private_a = Binding::literal( 'hidden-a-value' );
$private_b = Binding::literal( 'hidden-b-value' );
$stored = $workflow( [
    'secret_a' => $private_a,
    'secret_b' => $private_b,
    'label' => Binding::literal( 'keep-visible' ),
] );
$original = DefinitionCodec::encode( $stored );
$diagnosis = WorkflowRecoveryPlanner::classify( $stored, $snapshot );
assert_true( 'needs_secret_remediation' === $diagnosis['status'], 'C1-00: classify sensitive stored bindings.' );
assert_true( ! str_contains( serialize( $diagnosis ), 'hidden-a-value' ), 'C3-06: classification must not disclose hidden data.' );

$only_a = WorkflowRecoveryPlanner::plan( $stored, [ $remove( 'fix_a', 'secret_a', $private_a ) ], $snapshot );
assert_true( 'pending_remediation' === $only_a['status'] && null === $only_a['candidate'], 'C1-01: partial sensitive correction is nonpersistable.' );
assert_true( DefinitionCodec::encode( $stored ) === $original, 'C1-01: original source must remain unchanged.' );
$complete = WorkflowRecoveryPlanner::plan( $stored, [
    $remove( 'fix_a', 'secret_a', $private_a ),
    $remove( 'fix_b', 'secret_b', $private_b ),
], $snapshot );
assert_true( 'ready' === $complete['status'] && $complete['candidate'] instanceof Definition, 'C1-02: atomic complete repair should be ready.' );
$fixed = DefinitionCodec::encode( $complete['candidate'] );
assert_true( count( $complete['intent_ids'] ) === 2 && $complete['intent_ids'] === [ 'fix_a', 'fix_b' ], 'C1-02: command IDs and order preserved.' );
assert_true( $fixed['actions'][0]['bindings'] === [ 'label' => [ 'source' => 'literal', 'value' => 'keep-visible' ] ], 'C3-03: untouched fields preserved verbatim.' );
assert_true( $fixed['actions'][0]['step_id'] === 'action_1' && $fixed['trigger']['step_id'] === 'trigger_1', 'C3-03: step identities changed.' );
assert_true( ( new WorkflowValidator( source() ) ) instanceof WorkflowValidator, 'C3-07: real validator available in fixtures.' );
assert_true( DefinitionCodec::encode( $stored ) === $original, 'C1-02: staged repair mutated original.' );
assert_true( ! str_contains( serialize( [ $complete['status'], $complete['intent_ids'], $complete['codes'] ] ), 'hidden-a-value' ), 'C3-06: no diagnostic secret disclosure.' );
assert_true( 'blocked_invalid_intent' === WorkflowRecoveryPlanner::plan( $stored, [
    $remove( 'fix_a', 'secret_a', $private_a ),
    $remove( 'fix_b', 'secret_b', Binding::literal( 'wrong-original' ) ),
], $snapshot )['status'], 'C1-03: mismatched original must block the whole batch.' );
assert_true( DefinitionCodec::encode( $stored ) === $original, 'C1-03: failed batch mutated original.' );

foreach ( [ 'malformed_json', 'oversized_definition', 'unsupported_schema', 'trigger_key_mismatch' ] as $code ) {
    $corrupt = WorkflowRecoveryPlanner::classify( null, [], $code );
    assert_true( $corrupt['status'] === 'unreadable_definition' && $corrupt['codes'] === [ $code ], 'C2-01: corrupt source must return a safe code.' );
    assert_true( ! array_key_exists( 'candidate', $corrupt ), 'C2-02: unreadable source must have no candidate.' );
}
assert_true( WorkflowRecoveryPlanner::classify( null, [], 'raw-unknown-data' )['codes'] === [ 'read_failure_unknown' ], 'C2-02: raw error must be collapsed to a safe code.' );

assert_true( 'needs_provider_recovery' === WorkflowRecoveryPlanner::classify( $stored, $make_snapshot( false ) )['status'], 'C3-01: missing provider takes priority over sensitive classification.' );
assert_true( 'blocked_provider_unavailable' === WorkflowRecoveryPlanner::plan( $stored, [ $remove( 'fix_a', 'secret_a', $private_a ) ], $make_snapshot( false ) )['status'], 'C3-01: unavailable provider must block planning.' );
assert_true( 'blocked_schema_mismatch' === WorkflowRecoveryPlanner::plan( $stored, [ $remove( 'fix_a', 'secret_a', $private_a ) ], $make_snapshot( true, true ) )['status'], 'C3-01: version drift must block planning.' );

$duplicate = WorkflowRecoveryPlanner::plan( $stored, [
    $remove( 'fix_a', 'secret_a', $private_a ),
    $remove( 'fix_b', 'secret_a', $private_a ),
], $snapshot );
assert_true( 'blocked_invalid_intent' === $duplicate['status'], 'C3-02: duplicate targets must be rejected.' );
$wrong_target = $remove( 'fix_a', 'secret_a', $private_a );
$wrong_target['target'] = [ 'json_pointer' => '/actions/0/bindings/secret_a' ];
assert_true( 'blocked_invalid_intent' === WorkflowRecoveryPlanner::plan( $stored, [ $wrong_target ], $snapshot )['status'], 'C3-02: free JSON pointer must be rejected.' );
$wrong_selector = $remove( 'fix_a', 'secret_a', $private_a );
$wrong_selector['target']['step_id'] = 'action_404';
assert_true( 'blocked_invalid_intent' === WorkflowRecoveryPlanner::plan( $stored, [ $wrong_selector ], $snapshot )['status'], 'C3-02: missing step must be rejected.' );
$denied = $remove( 'fix_a', 'secret_a', $private_a );
$denied['confirm'] = false;
assert_true( 'blocked_invalid_intent' === WorkflowRecoveryPlanner::plan( $stored, [ $denied ], $snapshot )['status'], 'C3-02: absent operator confirmation must fail closed.' );

$sensitive_condition = condition(
    'condition_1', Binding::step_output( 'trigger_1', 'secret' ), 'equals', Binding::literal( 'concealed-comparison' )
);
$with_condition = $workflow( [], [ $sensitive_condition ] );
$remove_condition = [
    'intent_id' => 'remove_condition', 'operation' => 'remove_sensitive_condition',
    'target' => [ 'collection' => 'conditions', 'condition_id' => 'condition_1' ],
    'expected' => $sensitive_condition, 'replacement' => null, 'confirm' => true,
];
$clean_condition = WorkflowRecoveryPlanner::plan( $with_condition, [ $remove_condition ], $snapshot );
assert_true( 'ready' === $clean_condition['status'] && $clean_condition['candidate']->conditions() === [], 'C3-condition: explicitly remove private condition.' );
assert_true( count( $with_condition->conditions() ) === 1, 'C3-condition: original conditions changed.' );

$huge = $workflow( [ 'decimal' => Binding::literal( PHP_INT_MAX ) ] );
assert_true( 'needs_integer_remediation' === WorkflowRecoveryPlanner::classify( $huge, $snapshot )['status'], 'C3-04: unsafe PHP_INT_MAX detected.' );
$repair_integer = $replace( 'number', 'replace_unsafe_integer', $target( 'decimal' ), Binding::literal( PHP_INT_MAX ), Binding::literal( (string) PHP_INT_MAX ) );
$integer_done = WorkflowRecoveryPlanner::plan( $huge, [ $repair_integer ], $snapshot );
assert_true( 'ready' === $integer_done['status'], 'C3-04: exact decimal string replacement should be ready for string schema.' );
assert_true( $integer_done['candidate']->actions()[0]->bindings()['decimal']->value() === (string) PHP_INT_MAX, 'C3-04: integer became inexact.' );
assert_true( is_string( $integer_done['candidate']->actions()[0]->bindings()['decimal']->value() ), 'C3-04: integer->string type must be explicit.' );
foreach ( [ PHP_INT_MIN, PHP_INT_MAX ] as $edge ) {
    $candidate = WorkflowRecoveryPlanner::plan(
        $workflow( [ 'decimal' => Binding::literal( $edge ) ] ),
        [ $replace( 'exact', 'replace_unsafe_integer', $target( 'decimal' ), Binding::literal( $edge ), Binding::literal( (string) $edge ) ) ],
        $snapshot
    );
    assert_true( 'ready' === $candidate['status'], 'C3-04: PHP signed integer boundary not repaired exactly.' );
}
$altered = $replace( 'number', 'replace_unsafe_integer', $target( 'decimal' ), Binding::literal( PHP_INT_MAX ), Binding::literal( (string) ( PHP_INT_MAX - 1 ) ) );
assert_true( 'blocked_precision_or_semantics' === WorkflowRecoveryPlanner::plan( $huge, [ $altered ], $snapshot )['status'], 'C3-04: changed numeric value is not equivalent.' );
$semantic = $workflow( [ 'external_id' => Binding::literal( PHP_INT_MAX ) ] );
assert_true( 'blocked_precision_or_semantics' === WorkflowRecoveryPlanner::plan( $semantic, [
    $replace( 'number', 'replace_unsafe_integer', $target( 'external_id' ), Binding::literal( PHP_INT_MAX ), Binding::literal( 7 ) ),
], $snapshot )['status'], 'C3-05: opaque external identifier may not be reassigned.' );
$stored_array = $workflow( [ 'items' => Binding::literal( [ PHP_INT_MIN, 'remain' ] ) ] );
$array_target = $target( 'items' );
$array_target['array_index'] = 0;
$array_result = WorkflowRecoveryPlanner::plan( $stored_array, [
    $replace( 'array', 'replace_unsafe_integer', $array_target, Binding::literal( [ PHP_INT_MIN, 'remain' ] ), Binding::literal( (string) PHP_INT_MIN ) ),
], $snapshot );
assert_true( 'ready' === $array_result['status'], 'C3-04: exact array index replacement should be valid.' );
assert_true( $array_result['candidate']->actions()[0]->bindings()['items']->value() === [ (string) PHP_INT_MIN, 'remain' ], 'C3-04: untouched array item changed.' );
$numeric_string = $workflow( [ 'decimal' => Binding::literal( '9223372036854775807' ) ] );
assert_true( 'ready' === WorkflowRecoveryPlanner::classify( $numeric_string, $snapshot )['status'], 'C3-04: existing numeric strings must stay string.' );

$mixed = $workflow( [ 'secret_a' => $private_a, 'decimal' => Binding::literal( PHP_INT_MAX ) ] );
$pending = WorkflowRecoveryPlanner::plan( $mixed, [ $remove( 'fix_a', 'secret_a', $private_a ) ], $snapshot );
assert_true( 'pending_remediation' === $pending['status'] && null === $pending['candidate'], 'C1-04: mixed secret + integer partial batch must not become persistable.' );
$fully_mixed = WorkflowRecoveryPlanner::plan( $mixed, [
    $remove( 'fix_a', 'secret_a', $private_a ),
    $repair_integer,
], $snapshot );
assert_true( 'ready' === $fully_mixed['status'], 'C1-04: mixed privacy+integer complete batch should be ready.' );

// No network, database, WordPress globals, provider callbacks or external execution:
// all planner inputs above are canonically decoded objects and immutable array snapshots.
// No timestamp/randomness-dependent result, and no opaque execution principal.
assert_true(
    DefinitionCodec::encode( $stored ) === $original
    && PersistencePolicy::allows( ( new WorkflowValidator( source() ) )->validate( valid_definition() ) )
    && EditorIntegerSafety::is_browser_safe( $fully_mixed['candidate'] ),
    'C3-07: pure planner must preserve input and return a safe final Definition.'
);

// Additional adversarial authoring fixtures; these have no WordPress/database dependencies.
$required = $make_snapshot();
$action_key = 'action:' . $provider . ':recovery.action:1';
$required['capabilities'][ $action_key ]['input_schema']['secret_a']['required'] = true;
$required_remove = WorkflowRecoveryPlanner::plan(
    $workflow( [ 'secret_a' => $private_a ] ),
    [ $remove( 'required', 'secret_a', $private_a ) ],
    $required
);
assert_true( 'blocked_invalid_candidate' === $required_remove['status'] && null === $required_remove['candidate'], 'C1-required: removal cannot leave a required field missing.' );

$safe_secret_reference = $replace(
    'safe_binding', 'replace_sensitive_binding', $target( 'secret_a' ),
    $private_a, Binding::step_output( 'trigger_1', 'secret' )
);
$safely_rebound = WorkflowRecoveryPlanner::plan( $workflow( [ 'secret_a' => $private_a ] ), [ $safe_secret_reference ], $snapshot );
assert_true( 'ready' === $safely_rebound['status'], 'C3-05: type-compatible prior output can replace sensitive literal.' );
assert_true( $safely_rebound['candidate']->actions()[0]->bindings()['secret_a']->source() === Binding::SOURCE_STEP_OUTPUT, 'C3-05: replacement is not an output reference.' );

$semantic_output = $replace(
    'keep_semantic', 'replace_unsafe_integer_binding', $target( 'copy_id' ),
    Binding::literal( PHP_INT_MAX ), Binding::step_output( 'trigger_1', 'id' )
);
$semantic_result = WorkflowRecoveryPlanner::plan(
    $workflow( [ 'copy_id' => Binding::literal( PHP_INT_MAX ) ] ), [ $semantic_output ], $snapshot
);
assert_true( 'ready' === $semantic_result['status'], 'C3-semantic: matching semantic output can replace unsafe external ID.' );
$wrong_semantic = $semantic_output;
$wrong_semantic['replacement'] = Binding::step_output( 'trigger_1', 'label' );
assert_true(
    'blocked_invalid_candidate' === WorkflowRecoveryPlanner::plan(
        $workflow( [ 'copy_id' => Binding::literal( PHP_INT_MAX ) ] ), [ $wrong_semantic ], $snapshot
    )['status'],
    'C3-semantic: output type or semantic mismatch must never be ready.'
);

$bad_snapshot = $snapshot;
$bad_snapshot['capabilities'][ $action_key ]['input_schema']['decimal']['semantic_type'] = [];
assert_true(
    'blocked_schema_mismatch' === WorkflowRecoveryPlanner::plan(
        $huge, [ $repair_integer ], $bad_snapshot
    )['status'],
    'C3-snapshot: malformed typed provider evidence cannot be accepted.'
);

$dupe_id = $remove( 'same', 'secret_a', $private_a );
assert_true(
    'blocked_invalid_intent' === WorkflowRecoveryPlanner::plan( $stored, [
        $dupe_id, $remove( 'same', 'secret_b', $private_b ),
    ], $snapshot )['status'],
    'C3-identity: duplicate command IDs are forbidden.'
);
$unexpected = $remove( 'added', 'secret_a', $private_a );
$unexpected['unknown'] = 'covert';
assert_true(
    'blocked_invalid_intent' === WorkflowRecoveryPlanner::plan( $stored, [ $unexpected ], $snapshot )['status'],
    'C3-target: arbitrary extra command fields are rejected.'
);
assert_true(
    'blocked_invalid_intent' === WorkflowRecoveryPlanner::plan( $stored, [], $snapshot )['status'],
    'C3-noop: a no-op recovery cannot claim a successful save candidate.'
);
$bad_float = $workflow( [ 'decimal' => Binding::literal( 1.0 ) ] );
assert_true(
    'needs_integer_remediation' === WorkflowRecoveryPlanner::classify( $bad_float, $snapshot )['status'],
    'C3-float: integral PHP floats are not type-stable in existing JSON storage.'
);
assert_true(
    'blocked_precision_or_semantics' === WorkflowRecoveryPlanner::plan( $bad_float, [
        $replace( 'bad_float', 'replace_unsafe_integer', $target( 'decimal' ), Binding::literal( 1.0 ), Binding::literal( '1' ) ),
    ], $snapshot )['status'],
    'C3-float: an integral float cannot be silently reclassified as an exact source integer.'
);

$public_pending = WorkflowRecoveryPlanner::plan( $stored, [ $remove( 'only_a', 'secret_a', $private_a ) ], $snapshot );
assert_true(
    null === $public_pending['candidate']
    && ! str_contains( serialize( $public_pending ), 'hidden-b-value' )
    && ! str_contains( serialize( $public_pending ), 'hidden-a-value' ),
    'C3-privacy: incomplete private draft cannot leak into diagnostics or ready candidate.'
);

fwrite( STDOUT, "Automations AU-R1A recovery planner: PASS\n" );
