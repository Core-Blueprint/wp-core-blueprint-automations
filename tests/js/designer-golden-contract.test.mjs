import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const root = new URL('../../', import.meta.url);
const read = (path) => readFile(new URL(path, root), 'utf8');

test('Designer Golden uses Base sidebar roles and retains Settings health content', async () => {
	const [template, shell] = await Promise.all([
		read('templates/admin/workflow-editor.php'),
		read('assets/js/admin-designer-shell.js'),
	]);
	assert.match(template, /data-cb-design-shell-tab="inspector"/);
	assert.match(template, /data-cb-design-shell-tab="settings"/);
	assert.doesNotMatch(template, /data-cb-design-shell-tab="health"/);
	assert.match(template, /cb-automations-validation-panel/);
	assert.ok(template.indexOf('cb-automations-validation-panel') > template.indexOf('data-cb-design-shell-panel="settings"'));
	assert.match(shell, /configureDesignerSidebar\(root/);
	assert.match(shell, /createDesignerNavigation\(/);
	assert.match(shell, /cb-automations:definitionchange/);
	assert.doesNotMatch(shell, /\.cb-core-design-shell__workspace\.append/);
});

test('Async saves reconcile workflow validation and both save entrypoints', async () => {
	const [save, recovery, template] = await Promise.all([
		read('assets/js/admin-builder-save.js'),
		read('assets/js/admin-validation-recovery.js'),
		read('templates/admin/workflow-editor.php'),
	]);
	assert.match(template, /data-cb-automations-save-alias/);
	assert.match(save, /aliases\.includes\(event\.submitter\)/);
	assert.match(save, /cb-automations:validation-saved/);
	assert.match(recovery, /renderSavedValidation\(event\.detail\)/);
	assert.match(recovery, /summaryItems = Array\.from\(list\?\.children/);
	assert.match(recovery, /applyInlineIssues\(\)/);
	assert.match(recovery, /clearInlineIssues\(\)/);
	assert.match(recovery, /cb-automations:definitionchange/);
	assert.doesNotMatch(save, /health\.append\(/);
});

test('Context switching respects the public Base event and unsaved editor data', async () => {
	const [shell, save] = await Promise.all([
		read('assets/js/admin-designer-shell.js'),
		read('assets/js/admin-builder-save.js'),
	]);
	assert.match(shell, /cb:design-shell:contextrequest/);
	assert.match(shell, /event\.detail\.respondWith/);
	assert.match(shell, /isDirty\(\)/);
	assert.match(shell, /beforeunload/);
	assert.match(shell, /cb:design-shell:savechange/);
	assert.match(save, /baselineDefinition: submittedDefinition/);
	assert.match(save, /baselineName: submittedName/);
	assert.match(save, /baselineActivation:/);
});

test('Inspector composes Base helpers without a second authoring surface', async () => {
	const [inspector, assets] = await Promise.all([
		read('assets/js/admin-builder-inspector.js'),
		read('src/Admin/DesignerAssets.php'),
	]);
	assert.match(inspector, /window\.cbCore\?\.designEditor\?\.shell\?\.inspector/);
	assert.match(inspector, /inspectorApi\.createIdentity\(/);
	assert.match(inspector, /inspectorApi\.createControls\(/);
	assert.match(inspector, /inspectorApi\.createSection\(/);
	assert.match(inspector, /sectionStates\.get\(record\.key\)/);
	assert.match(inspector, /aria-current/);
	assert.match(inspector, /cb:design-editor:ready/);
	assert.doesNotMatch(inspector, /assets\/js\/design\//);
	assert.match(assets, /'technicalDetails'/);
});
