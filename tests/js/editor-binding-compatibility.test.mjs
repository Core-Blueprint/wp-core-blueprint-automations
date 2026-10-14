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
assert.equal(helpers.typeCompatible(legacyInteger, projectId), false, 'Semantically typed targets must reject legacy untyped workflow outputs.');
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
		{ type: 'integer' },
		{ type: 'number', semantic_type: 'core-blueprint-work.project_id' }
	),
	false,
	'Primitive widening must not let an untyped workflow output satisfy a semantic target.'
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

const statuses = [
	{ value: 'draft', label: 'Draft' },
	{ value: 'publish', label: 'Published' },
	{ value: 'pending', label: 'Pending review' },
	{ value: 'future', label: 'Scheduled' },
	{ value: 'private', label: 'Private' },
	{ value: 'acme_review', label: 'Awaiting review' },
];
assert.equal(helpers.postStatusChoices({ type: 'integer', semantic_type: 'wp.post_status' }, statuses), null);
assert.equal(helpers.postStatusChoices({ type: 'string', semantic_type: 'wp.post_type' }, statuses), null);
assert.deepEqual(
	helpers.postStatusChoices({ type: 'string', semantic_type: 'wp.post_status' }, statuses).map((s) => s.value),
	['draft', 'publish', 'pending', 'future', 'private', 'acme_review'],
	'Only typed WordPress post-status literal inputs use the site-registered picker.'
);
assert.deepEqual(
	helpers.postStatusChoices({ type: 'string', semantic_type: 'wp.post_status' }, statuses, 'legacy_custom').at(-1),
	{ value: 'legacy_custom', label: 'legacy_custom' },
	'Previously saved/custom status values remain visible even if no longer registered.'
);
assert.equal(helpers.postStatusChoices({ type: 'string', semantic_type: 'wp.post_status' }, [...statuses, { value: 'draft', label: 'Duplicate' }]).length, statuses.length, 'Duplicate statuses must be removed.');
