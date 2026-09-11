import assert from 'node:assert/strict';

await import('../../assets/js/admin-validation-recovery.js');

const helpers = globalThis.cbAutomationsValidationRecoveryHelpers;
assert.ok(helpers, 'Validation recovery helpers must be available in non-browser test mode.');

const requiredEmail = {
	code: 'binding.required_missing',
	path: 'states.1.bindings.email',
	context: { field: 'email' },
};

assert.equal(
	helpers.locationLabel(requiredEmail),
	'Get data · Data lookup 2 · Email',
	'Binding issues must be presented at a human workflow location.'
);
assert.equal(
	helpers.recoveryLabel(requiredEmail),
	'Connect input',
	'Missing required bindings must offer a direct recovery action.'
);
assert.deepEqual(
	helpers.targetDescriptor(requiredEmail),
	{ type: 'binding', root: 'states', index: 1, field: 'email' },
	'Binding paths must resolve to their exact data lookup and field.'
);

const missingAction = { code: 'workflow.action_missing', path: 'actions', context: {} };
assert.equal(helpers.locationLabel(missingAction), 'Then · Actions');
assert.equal(helpers.recoveryLabel(missingAction), 'Add action');
assert.deepEqual(
	helpers.targetDescriptor(missingAction),
	{ type: 'add_action', root: 'actions', index: null, field: '' },
	'Missing actions must target the THEN add-action control.'
);

const unavailableCapability = {
	code: 'capability.missing',
	path: 'actions.0.capability',
	context: { provider: 'demo', id: 'followup.create' },
};
assert.equal(helpers.locationLabel(unavailableCapability), 'Then · Action 1');
assert.equal(helpers.recoveryLabel(unavailableCapability), 'Choose replacement');
assert.deepEqual(
	helpers.targetDescriptor(unavailableCapability),
	{ type: 'capability', root: 'actions', index: 0, field: '' },
	'Capability recovery must target the capability picker for the affected step.'
);

const badCondition = {
	code: 'condition.type_mismatch',
	path: 'conditions.2.right',
	context: {},
};
assert.equal(helpers.locationLabel(badCondition), 'Only if · Condition 3');
assert.equal(helpers.recoveryLabel(badCondition), 'Review condition');
assert.deepEqual(
	helpers.targetDescriptor(badCondition),
	{ type: 'condition', root: 'conditions', index: 2, field: 'right' },
	'Condition issues must target the affected operand.'
);

const obsoleteInput = {
	code: 'binding.input_unknown',
	path: 'states.0.bindings.legacy_email',
	context: { field: 'legacy_email' },
};
assert.equal(helpers.recoveryLabel(obsoleteInput), 'Review capability');
assert.deepEqual(
	helpers.targetDescriptor(obsoleteInput),
	{ type: 'capability', root: 'states', index: 0, field: '' },
	'Unknown persisted inputs are not rendered as fields and must recover through the step capability.'
);

const triggerBindings = {
	code: 'binding.trigger_has_bindings',
	path: 'trigger.bindings',
	context: {},
};
assert.equal(helpers.recoveryLabel(triggerBindings), 'Review trigger');
assert.deepEqual(
	helpers.targetDescriptor(triggerBindings),
	{ type: 'capability', root: 'trigger', index: null, field: '' },
	'Trigger binding recovery must target the trigger picker rather than a nonexistent input row.'
);

assert.equal(helpers.humanizeField('customer_id'), 'Customer ID');
assert.equal(helpers.humanizeField('api_url'), 'API URL');

console.log('validation-recovery: PASS');
