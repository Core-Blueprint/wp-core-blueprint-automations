import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';

const template = readFileSync(
	new URL('../../templates/admin/workflow-editor.php', import.meta.url),
	'utf8',
);

const contextMatches = [...template.matchAll(
	/<([a-z][\w-]*)\b([^>]*\bdata-cb-design-shell-context\b[^>]*)>/g,
)];

test('AUTO-HP-01: workflow context uses a neutral container for the Base interactive Header Picker', () => {
	assert.equal(contextMatches.length, 1, 'exactly one Designer context is declared');
	const [opening, tag, attributes] = contextMatches[0];
	assert.equal(tag, 'div', 'a label cannot contain the Picker button injected by Base');
	assert.match(attributes, /\bclass="cb-core-field"/);
	assert.match(attributes, /\bhidden\b/);
	assert.match(attributes, /\baria-hidden="true"/);

	const start = contextMatches[0].index;
	const end = template.indexOf('</div>', start + opening.length);
	assert.notEqual(end, -1, 'neutral context wrapper must close');
	const context = template.slice(start, end + '</div>'.length);
	assert.doesNotMatch(context, /<\/?label\b/i, 'the interactive Picker must not be nested inside a label');
	assert.match(context, /<span class="cb-core-field__label">/);
	assert.equal((context.match(/<select\b/g) || []).length, 1, 'Base enhances the one native select');
});

test('AUTO-HP-01: accessible workflow switcher, options and context authority stay native', () => {
	assert.equal(contextMatches.length, 1);
	const start = contextMatches[0].index;
	const end = template.indexOf('</div>', start);
	assert.notEqual(end, -1);
	const context = template.slice(start, end + '</div>'.length);

	assert.match(context, /<select data-cb-automations-workflow-switcher aria-label="<\?php esc_attr_e\( 'Workflow', 'core-blueprint-automations' \); \?>">/);
	assert.match(context, /<\?php foreach \( \$workflow_contexts as \$workflow_context \) : \?>/);
	assert.match(context, /value="<\?php echo esc_url\( \\CB\\Automations\\Admin\\AutomationsPage::url\( \[ 'workflow' => \$workflow_context\['id'\] \] \) \); \?>"/);
	assert.match(context, /<\?php selected\( \$workflow_context\['id'\], \$record->id\(\) \); \?>/);
	assert.match(context, /<\?php echo esc_html\( \$workflow_context\['name'\] \); \?>/);
	assert.match(context, /<\/select>/);
});
