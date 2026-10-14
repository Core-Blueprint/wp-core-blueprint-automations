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
	assert.match(shell, /stageHost\.append\(navigation\.element\)/);
	assert.doesNotMatch(template, /data-cb-automations-stage-navigation/);
	assert.match(template, /cb-core-design-shell__canvas-workarea/);
	assert.doesNotMatch(template, /cb-automations-validation-panel[^>]*>\s*<div class="cb-core-design-shell__panel-body"/);
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
	assert.match(recovery, /renderSavedValidation/);
	const controller = await read('src/Admin/WorkflowController.php');
	assert.match(controller, /'code'\s*=>\s*\$issue->code\(\)/);
	assert.doesNotMatch(controller.slice(controller.indexOf('foreach ( $result->validation()->issues()')), /'context'\s*=>\s*\$issue->context\(\)/);
	const assets = await read('src/Admin/AdminAssets.php');
	for (const key of ['ready', 'needs_attention', 'valid_description', 'invalid_description']) assert.match(assets, new RegExp("'" + key + "'\\s*=>"));
	assert.doesNotMatch(save, /health\.append\(/);
});

test('Context switching respects the public Base event and unsaved editor data', async () => {
	const [shell, save] = await Promise.all([
		read('assets/js/admin-designer-shell.js'),
		read('assets/js/admin-builder-save.js'),
	]);
	assert.match(shell, /cb:design-shell:contextrequest/);
	assert.match(shell, /switchContext\(url\)/);
	assert.match(shell, /new DOMParser\(\)/);
	assert.match(shell, /history\.resetForContext\(nextDefinition\)/);
	assert.match(shell, /cb-automations:contextloaded/);
	assert.doesNotMatch(shell, /window\.location\.assign\(/);
	assert.match(shell, /launchRoot\?\.addEventListener\('cb:design-shell:contextrequest'/);
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
	const history = await read('assets/js/admin-builder-history.js');
	assert.match(history, /createDesignerStateHistory/);
	assert.doesNotMatch(history, /\b(?:ProjectState|EditorState|CommandHistory)\b/);
	assert.doesNotMatch(inspector, /assets\/js\/design\//);
	assert.match(assets, /'technicalDetails'/);
});

test('Settings health recovery stacks validation messages and actions in the narrow sidebar', async () => {
	const css = await read('assets/css/admin-validation-recovery.css');
	assert.match(css, /\\.cb-automations-validation-list li\\[data-cb-validation-enhanced="1"\\]\\s*\\{[^}]*grid-template-columns:\\s*minmax\\(0,\\s*1fr\\);/s);
	assert.doesNotMatch(css, /grid-template-columns:\\s*minmax\\(0,\\s*1fr\\)\\s+auto;/);
	assert.match(css, /\\.cb-automations-validation-item__controls\\s*\\{[^}]*flex-direction:\\s*column;/s);
	assert.match(css, /\\.cb-automations-validation-item__controls\\s*\\{[^}]*align-items:\\s*flex-start;/s);
});
