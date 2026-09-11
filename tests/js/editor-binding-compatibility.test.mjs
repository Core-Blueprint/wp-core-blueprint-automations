import assert from 'node:assert/strict';

await import('../../assets/js/admin-editor.js');

const helpers = globalThis.cbAutomationsEditorHelpers;
assert.ok(helpers, 'Admin editor compatibility helpers must be available in non-browser test mode.');

const projectId = { type: 'integer', items: null, semantic_type: 'core-blueprint-work.project_id' };
const sameProjectId = { type: 'integer', items: null, semantic_type: 'core-blueprint-work.project_id' };
const contractId = { type: 'integer', items: null, semantic_type: 'core-blueprint-contracts.contract_id' };
const legacyInteger = { type: 'integer', items: null };

assert.equal(helpers.typeCompatible(projectId, sameProjectId), true, 'Matching explicit semantic IDs must remain compatible.');
assert.equal(helpers.typeCompatible(contractId, projectId), false, 'Different explicit semantic IDs must be rejected even when primitive types match.');
assert.equal(helpers.typeCompatible(projectId, legacyInteger), true, 'Typed sources must remain compatible with legacy untyped targets when primitive types match.');
assert.equal(helpers.typeCompatible(legacyInteger, projectId), true, 'Legacy untyped sources must remain compatible with typed targets when primitive types match.');
assert.equal(
	helpers.typeCompatible(
		{ type: 'integer', semantic_type: 'core-blueprint-work.project_id' },
		{ type: 'number', semantic_type: 'core-blueprint-work.project_id' }
	),
	true,
	'Existing integer-to-number widening must remain valid when semantics match.'
);
assert.equal(
	helpers.typeCompatible(
		{ type: 'integer', semantic_type: 'core-blueprint-contracts.contract_id' },
		{ type: 'number', semantic_type: 'core-blueprint-work.project_id' }
	),
	false,
	'Primitive widening must not bypass an explicit semantic mismatch.'
);
assert.equal(
	helpers.typeCompatible(
		{ type: 'array', items: 'integer', semantic_type: 'core-blueprint-work.project_id' },
		{ type: 'array', items: 'number', semantic_type: 'core-blueprint-work.project_id' }
	),
	true,
	'Array item widening must retain the existing compatibility rule when semantics match.'
);

console.log('editor-binding-compatibility: PASS');
