import assert from 'node:assert/strict';

await import('../../assets/js/admin-condition-builder.js');

const helpers = globalThis.cbAutomationsConditionBuilderHelpers;
assert.ok(helpers, 'Condition builder helpers must be available in non-browser test mode.');

assert.deepEqual(
	helpers.parseOutputValue('step:state_1:lifetime_value'),
	{ step_id: 'state_1', field: 'lifetime_value' },
	'Step-output references must retain their typed workflow identity.'
);
assert.equal(helpers.parseOutputValue('literal'), null, 'Non-output sources must not be parsed as step outputs.');

assert.equal(helpers.typeLabel({ type: 'integer' }), 'Number');
assert.equal(helpers.typeLabel({ type: 'number' }), 'Number');
assert.equal(helpers.typeLabel({ type: 'boolean' }), 'True / false');
assert.equal(helpers.typeLabel({ type: 'array' }), 'List');
assert.equal(helpers.typeLabel({ type: 'string' }), 'Text');

assert.equal(helpers.operatorPhrase('equals', 'number'), 'is');
assert.equal(helpers.operatorPhrase('not_equals', 'string'), 'is not');
assert.equal(helpers.operatorPhrase('greater_than', 'number'), 'is greater than');
assert.equal(helpers.operatorPhrase('greater_than_or_equal', 'number'), 'is at least');
assert.equal(helpers.operatorPhrase('less_than_or_equal', 'number'), 'is at most');
assert.equal(helpers.operatorPhrase('is_empty', 'string'), 'is empty');
assert.equal(helpers.operatorPhrase('is_empty', 'array'), 'has no items');
assert.equal(helpers.operatorPhrase('is_not_empty', 'array'), 'has items');

assert.equal(
	helpers.sentence('Current customer state → Lifetime value', 'is greater than', '1000', 2),
	'Current customer state → Lifetime value is greater than 1000',
	'Binary rules must read as one human sentence.'
);
assert.equal(
	helpers.sentence('Tags', 'has items', '', 1),
	'Tags has items',
	'Unary rules must not render an empty comparison operand.'
);

console.log('condition-builder: PASS');
