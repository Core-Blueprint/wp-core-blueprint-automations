<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\Automations\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}

	$relative = substr( $class, strlen( $prefix ) );
	$file     = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );

use CB\Automations\Binding\Binding;
use CB\Automations\Capability\CapabilityKind;
use CB\Automations\Capability\CapabilityReference;
use CB\Automations\Condition\Condition;
use CB\Automations\Discovery\CapabilityDefinition;
use CB\Automations\Discovery\CapabilitySource;
use CB\Automations\Discovery\ProviderStatus;
use CB\Automations\Validation\ValidationState;
use CB\Automations\Validation\WorkflowValidator;
use CB\Automations\Workflow\ActivationPolicy;
use CB\Automations\Workflow\ActivationState;
use CB\Automations\Workflow\Definition;
use CB\Automations\Workflow\DefinitionCodec;
use CB\Automations\Workflow\Step;

final class FakeCapabilitySource implements CapabilitySource {
	/** @param array<string,CapabilityDefinition> $definitions @param array<string,ProviderStatus> $statuses */
	public function __construct(
		private array $definitions,
		private array $statuses
	) {}

	public function current( CapabilityReference $reference ): ?CapabilityDefinition {
		$key = implode( ':', [ $reference->kind(), $reference->provider(), $reference->id() ] );
		return $this->definitions[ $key ] ?? null;
	}

	public function provider_status( string $provider ): ProviderStatus {
		return $this->statuses[ $provider ] ?? ProviderStatus::from_inventory( $provider, null );
	}
}

/** @return array<string,mixed> */
function field( string $type, bool $required = false, bool $sensitive = false, ?string $items = null, ?string $semantic_type = null ): array {
	$field = [ 'type' => $type, 'required' => $required, 'sensitive' => $sensitive, 'items' => $items ];
	if ( null !== $semantic_type ) {
		$field['semantic_type'] = $semantic_type;
	}
	return $field;
}

function capability( string $kind, string $provider, string $id, array $input, array $output, string $version = '1' ): CapabilityDefinition {
	$definition = [
		'provider'       => $provider,
		'id'             => $id,
		'label'          => $id,
		'description'    => '',
		'schema_version' => $version,
	];
	if ( CapabilityKind::TRIGGER === $kind ) {
		$definition['payload_schema'] = $output;
	} else {
		$definition['input_schema']        = $input;
		$definition['output_schema']       = $output;
		$definition['required_capability'] = null;
	}

	$result = CapabilityDefinition::from_base_definition( $kind, $definition );
	if ( null === $result ) {
		throw new RuntimeException( 'Fixture capability is malformed.' );
	}
	return $result;
}

function reference( string $kind, string $provider, string $id, string $version = '1' ): CapabilityReference {
	$result = CapabilityReference::from_values( $kind, $provider, $id, $version );
	if ( null === $result ) {
		throw new RuntimeException( 'Fixture reference is malformed.' );
	}
	return $result;
}

function step( string $id, CapabilityReference $reference, array $bindings = [] ): Step {
	$result = Step::from_values( $id, $reference, $bindings );
	if ( null === $result ) {
		throw new RuntimeException( 'Fixture step is malformed.' );
	}
	return $result;
}

function condition( string $id, Binding $left, string $operator, ?Binding $right ): Condition {
	$result = Condition::from_values( $id, $left, $operator, $right );
	if ( null === $result ) {
		throw new RuntimeException( 'Fixture condition is malformed.' );
	}
	return $result;
}

function definition( ?Step $trigger, array $states, array $conditions, array $actions ): Definition {
	$result = Definition::from_values( $trigger, $states, $conditions, $actions );
	if ( null === $result ) {
		throw new RuntimeException( 'Fixture definition is malformed.' );
	}
	return $result;
}

function assert_true( bool $value, string $message ): void {
	if ( ! $value ) {
		throw new RuntimeException( $message );
	}
}

function assert_code( $result, string $code, string $message ): void {
	assert_true( $result->has_code( $code ), $message . ' Missing: ' . $code );
}

function source( bool $work_active = true, bool $include_create = true, string $create_version = '1' ): FakeCapabilitySource {
	$contracts = 'core-blueprint-contracts';
	$work      = 'core-blueprint-work';

	$trigger = capability(
		CapabilityKind::TRIGGER,
		$contracts,
		'contract.signed',
		[],
		[
			'contract_id' => field( 'integer', true, false, null, 'core-blueprint-contracts.contract_id' ),
			'status'      => field( 'string', true ),
			'secret'      => field( 'string', false, true ),
		]
	);
	$lookup = capability(
		CapabilityKind::STATE,
		$work,
		'project.lookup',
		[ 'contract_id' => field( 'integer', true, false, null, 'core-blueprint-contracts.contract_id' ) ],
		[
			'project_id' => field( 'integer', true, false, null, 'core-blueprint-work.project_id' ),
			'tags'       => field( 'array', false, false, 'string' ),
		]
	);
	$create = capability(
		CapabilityKind::ACTION,
		$work,
		'project.create',
		[
			'project_id'        => field( 'integer', true, false, null, 'core-blueprint-work.project_id' ),
			'legacy_project_id' => field( 'integer' ),
			'secret_copy'       => field( 'string', false ),
		],
		[ 'created_id' => field( 'integer', true, false, null, 'core-blueprint-work.project_id' ) ],
		$create_version
	);
	$followup = capability(
		CapabilityKind::ACTION,
		$work,
		'project.followup',
		[],
		[ 'project_id' => field( 'integer', true, false, null, 'core-blueprint-work.project_id' ) ]
	);

	$definitions = [
		$trigger->reference()->kind() . ':' . $contracts . ':contract.signed' => $trigger,
		$lookup->reference()->kind() . ':' . $work . ':project.lookup'       => $lookup,
		$followup->reference()->kind() . ':' . $work . ':project.followup'   => $followup,
	];
	if ( $include_create ) {
		$definitions[ $create->reference()->kind() . ':' . $work . ':project.create' ] = $create;
	}

	$statuses = [
		$contracts => ProviderStatus::from_inventory(
			$contracts,
			[ 'name' => 'Contracts', 'installed' => true, 'active' => true, 'registered' => true, 'compatible' => true ]
		),
		$work => ProviderStatus::from_inventory(
			$work,
			[ 'name' => 'Work', 'installed' => true, 'active' => $work_active, 'registered' => $work_active, 'compatible' => true ]
		),
	];

	return new FakeCapabilitySource( $definitions, $statuses );
}

function valid_definition(): Definition {
	$contracts = 'core-blueprint-contracts';
	$work      = 'core-blueprint-work';

	$trigger = step( 'trigger_1', reference( CapabilityKind::TRIGGER, $contracts, 'contract.signed' ) );
	$state   = step(
		'state_1',
		reference( CapabilityKind::STATE, $work, 'project.lookup' ),
		[ 'contract_id' => Binding::step_output( 'trigger_1', 'contract_id' ) ]
	);
	$condition = condition(
		'condition_1',
		Binding::step_output( 'trigger_1', 'status' ),
		'equals',
		Binding::literal( 'signed' )
	);
	$action = step(
		'action_1',
		reference( CapabilityKind::ACTION, $work, 'project.create' ),
		[
			'project_id'  => Binding::step_output( 'state_1', 'project_id' ),
			'secret_copy' => Binding::step_output( 'trigger_1', 'secret' ),
		]
	);

	return definition( $trigger, [ $state ], [ $condition ], [ $action ] );
}

$valid     = valid_definition();
$validator = new WorkflowValidator( source() );
$result    = $validator->validate( $valid );
assert_true( $result->is_valid(), 'Expected canonical workflow to validate.' );
assert_true( $result->contains_sensitive_paths(), 'Sensitive binding metadata was not propagated.' );
assert_true( ValidationState::Valid === ValidationState::from_result( $result ), 'Expected live validation state valid.' );
assert_true( ActivationPolicy::allows( ActivationState::Enabled, $result ), 'Valid workflow should be enableable.' );

$encoded = DefinitionCodec::encode( $valid );
assert_true( $encoded === DefinitionCodec::encode( DefinitionCodec::decode( $encoded ) ), 'Definition round-trip changed canonical data.' );

$unknown = $encoded;
$unknown['future_field'] = true;
try {
	DefinitionCodec::decode( $unknown );
	throw new RuntimeException( 'Codec accepted an unknown root field.' );
} catch ( UnexpectedValueException ) {
	/* expected */
}

$schema_mismatch = $encoded;
$schema_mismatch['actions'][0]['capability']['schema_version'] = '2';
$schema_result = $validator->validate( DefinitionCodec::decode( $schema_mismatch ) );
assert_code( $schema_result, 'capability.schema_mismatch', 'Expected schema drift.' );
assert_true( ValidationState::NeedsReview === ValidationState::from_result( $schema_result ), 'Schema drift should need review.' );
assert_true( ! ActivationPolicy::allows( ActivationState::Enabled, $schema_result ), 'Schema drift must block enable.' );
assert_true( ActivationPolicy::allows( ActivationState::Disabled, $schema_result ), 'Invalid drafts must remain saveable while disabled.' );

$inactive_result = ( new WorkflowValidator( source( false ) ) )->validate( $valid );
assert_code( $inactive_result, 'dependency.provider_inactive', 'Expected inactive dependency.' );
assert_true( ValidationState::DependencyUnavailable === ValidationState::from_result( $inactive_result ), 'Inactive provider should be dependency unavailable.' );

$missing_cap_result = ( new WorkflowValidator( source( true, false ) ) )->validate( $valid );
assert_code( $missing_cap_result, 'capability.missing', 'Expected missing capability.' );

$required_missing = $encoded;
unset( $required_missing['actions'][0]['bindings']['project_id'] );
$required_result = $validator->validate( DefinitionCodec::decode( $required_missing ) );
assert_code( $required_result, 'binding.required_missing', 'Expected missing required binding.' );

$wrong_type = $encoded;
$wrong_type['actions'][0]['bindings']['project_id'] = [ 'source' => 'literal', 'value' => 'not-an-integer' ];
$type_result = $validator->validate( DefinitionCodec::decode( $wrong_type ) );
assert_code( $type_result, 'binding.type_mismatch', 'Expected literal type mismatch.' );

$semantic_mismatch = $encoded;
$semantic_mismatch['actions'][0]['bindings']['project_id'] = [
	'source'  => 'step_output',
	'step_id' => 'trigger_1',
	'field'   => 'contract_id',
];
$semantic_mismatch_result = $validator->validate( DefinitionCodec::decode( $semantic_mismatch ) );
assert_code( $semantic_mismatch_result, 'binding.type_mismatch', 'Explicitly different semantic IDs must not bind despite matching primitive types.' );

$legacy_semantic_fallback = $encoded;
$legacy_semantic_fallback['actions'][0]['bindings']['legacy_project_id'] = [
	'source'  => 'step_output',
	'step_id' => 'state_1',
	'field'   => 'project_id',
];
$legacy_semantic_result = $validator->validate( DefinitionCodec::decode( $legacy_semantic_fallback ) );
assert_true( ! $legacy_semantic_result->has_code( 'binding.type_mismatch' ), 'Legacy untyped targets must retain primitive compatibility with semantically typed sources.' );

$missing_source = $encoded;
$missing_source['actions'][0]['bindings']['project_id'] = [ 'source' => 'step_output', 'step_id' => 'missing_1', 'field' => 'project_id' ];
$missing_source_result = $validator->validate( DefinitionCodec::decode( $missing_source ) );
assert_code( $missing_source_result, 'binding.source_missing', 'Expected missing binding source.' );

$missing_field = $encoded;
$missing_field['actions'][0]['bindings']['project_id'] = [ 'source' => 'step_output', 'step_id' => 'state_1', 'field' => 'missing_field' ];
$missing_field_result = $validator->validate( DefinitionCodec::decode( $missing_field ) );
assert_code( $missing_field_result, 'binding.field_missing', 'Expected missing output field.' );

$forward = $encoded;
$forward['actions'][] = [
	'step_id'    => 'action_2',
	'capability' => reference( CapabilityKind::ACTION, 'core-blueprint-work', 'project.followup' )->to_array(),
	'bindings'   => [],
];
$forward['actions'][0]['bindings']['project_id'] = [ 'source' => 'step_output', 'step_id' => 'action_2', 'field' => 'project_id' ];
$forward_result = $validator->validate( DefinitionCodec::decode( $forward ) );
assert_code( $forward_result, 'binding.source_not_available_yet', 'Expected forward action reference to fail.' );

$action_condition = $forward;
$action_condition['conditions'][0] = [
	'condition_id' => 'condition_1',
	'left'         => [ 'source' => 'step_output', 'step_id' => 'action_2', 'field' => 'project_id' ],
	'operator'     => 'equals',
	'right'        => [ 'source' => 'literal', 'value' => 1 ],
];
$action_condition_result = $validator->validate( DefinitionCodec::decode( $action_condition ) );
assert_code( $action_condition_result, 'binding.source_not_available_yet', 'Conditions must not consume action output.' );

$semantic_condition = $encoded;
$semantic_condition['conditions'][0] = [
	'condition_id' => 'condition_1',
	'left'         => [ 'source' => 'step_output', 'step_id' => 'state_1', 'field' => 'project_id' ],
	'operator'     => 'equals',
	'right'        => [ 'source' => 'step_output', 'step_id' => 'trigger_1', 'field' => 'contract_id' ],
];
$semantic_condition_result = $validator->validate( DefinitionCodec::decode( $semantic_condition ) );
assert_code( $semantic_condition_result, 'condition.type_mismatch', 'Conditions must reject explicitly different semantic IDs despite matching primitive types.' );

$unsupported = $encoded;
$unsupported['conditions'][0]['operator'] = 'matches_magic';
$unsupported_result = $validator->validate( DefinitionCodec::decode( $unsupported ) );
assert_code( $unsupported_result, 'condition.operator_unsupported', 'Expected unsupported operator.' );

$arity = $encoded;
$arity['conditions'][0]['operator'] = 'is_empty';
$arity_result = $validator->validate( DefinitionCodec::decode( $arity ) );
assert_code( $arity_result, 'condition.arity_mismatch', 'Expected unary operator arity mismatch.' );

$empty_array = $encoded;
$empty_array['conditions'][0] = [
	'condition_id' => 'condition_1',
	'left'         => [ 'source' => 'step_output', 'step_id' => 'state_1', 'field' => 'tags' ],
	'operator'     => 'equals',
	'right'        => [ 'source' => 'literal', 'value' => [] ],
];
$empty_array_result = $validator->validate( DefinitionCodec::decode( $empty_array ) );
assert_true( ! $empty_array_result->has_code( 'condition.type_mismatch' ), 'Typed arrays should be comparable with an empty array literal.' );

fwrite( STDOUT, "workflow-core: PASS\n" );
