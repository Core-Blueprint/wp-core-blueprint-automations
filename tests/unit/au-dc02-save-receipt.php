<?php
declare(strict_types=1);

// AU-DC-02: real stored-record witness comparison. No WP database or CI access.
require __DIR__ . '/workflow-core.php';

use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityKind;
use CB\Automations\Persistence\WorkflowRecord;
use CB\Automations\Workflow\ActivationState;
use CB\Automations\Workflow\DefinitionCodec;
use CB\Automations\Workflow\TriggerKey;
use CB\Automations\Workflow\WorkflowSaveReceipt;

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $value ): string {
		return strip_tags( $value );
	}
}
$definition = definition( null, [], [], [
	step( 'action_1', reference( CapabilityKind::ACTION, 'core-blueprint-work', 'fixture.save' ), [
		'label' => Binding::literal( 'operator edited' ),
		'items' => Binding::literal( [] ),
	] ),
] );
$make = static function (
	int $id = 17, int $revision = 9, ?\CB\Automations\Workflow\Definition $actual = null,
	string $name = 'Example', ActivationState $state = ActivationState::Disabled,
	int $principal = 23, int $updated_by = 23
) use ( $definition ): WorkflowRecord {
	$actual ??= $definition;
	return new WorkflowRecord(
		$id, $name, $state, $actual, $revision, $principal,
		TriggerKey::for_definition( $actual ), 23, $updated_by, '2026-10-19 00:00:00', '2026-10-19 00:00:01'
	);
};
$verify = static fn ( ?WorkflowRecord $record ) => WorkflowSaveReceipt::verify(
	$record, 17, 8, 'Example', ActivationState::Disabled, $definition, 23, 23
);
$receipt = $verify( $make() );
assert_true( is_array( $receipt ), 'Readback with exact ID, revision, payload and actor must yield a receipt.' );
assert_true( $receipt['workflow_id'] === '17' && $receipt['revision'] === '9', 'Receipt must derive identifiers from actual readback.' );
assert_true( $receipt['name'] === 'Example' && $receipt['activation_state'] === 'disabled', 'Receipt must reflect stored metadata.' );
assert_true( $receipt['execution_principal_user_id'] === 23, 'Execution principal must be verified against stored row.' );
assert_true( ! str_contains( json_encode( $receipt, JSON_THROW_ON_ERROR ), 'operator edited' ), 'Receipt must not contain workflow definition values.' );
assert_true( null === $verify( null ), 'Read failure must not be treated as Saved.' );
assert_true( null === $verify( $make( id: 18 ) ), 'Wrong workflow identity must fail.' );
assert_true( null === $verify( $make( revision: 8 ) ), 'Unchanged revision must fail.' );
assert_true( null === $verify( $make( revision: 10 ) ), 'Concurrent writer overtaking readback must fail.' );
assert_true( null === $verify( $make( name: 'Changed elsewhere' ) ), 'Persisted name mismatch must fail.' );
assert_true( null === $verify( $make( state: ActivationState::Enabled ) ), 'Persisted activation mismatch must fail.' );
assert_true( null === $verify( $make( principal: 0 ) ), 'Persisted principal mismatch must fail.' );
assert_true( null === $verify( $make( updated_by: 99 ) ), 'Persisted last-writer witness mismatch must fail.' );

$other = definition( null, [], [], [
	step( 'action_1', reference( CapabilityKind::ACTION, 'core-blueprint-work', 'fixture.save' ), [
		'label' => Binding::literal( 'different edit' ),
		'items' => Binding::literal( [] ),
	] ),
] );
assert_true( null === $verify( $make( actual: $other ) ), 'Different persisted definition must fail.' );

// Empty bindings and literal arrays roundtrip through stored PHP definitions.
assert_true( DefinitionCodec::encode( $definition ) === DefinitionCodec::encode( $make()->definition() ), 'Canonical persisted definition must be used.' );
echo "AU-DC-02 authoritative persisted Save witness: PASS\n";
