import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const root = new URL('../../', import.meta.url);
const read = (path) => readFile(new URL(path, root), 'utf8');

test('Automation Builder keeps canonical persistence, shared-shell ownership and save reconciliation intact', async () => {
	const [inspector, history, save, editor, shell, controller, assets, finishCss, designerCss, adminCss, template] = await Promise.all([
		read('assets/js/admin-builder-inspector.js'),
		read('assets/js/admin-builder-history.js'),
		read('assets/js/admin-builder-save.js'),
		read('assets/js/admin-editor.js'),
		read('assets/js/admin-designer-shell.js'),
		read('src/Admin/WorkflowController.php'),
		read('src/Admin/DesignerAssets.php'),
		read('assets/css/admin-builder-finish.css'),
		read('assets/css/admin-designer-shell.css'),
		read('assets/css/admin-automations.css'),
		read('templates/admin/workflow-editor.php'),
	]);

	assert.match(template, /data-cb-design-launch-root/);
	assert.match(template, /data-cb-design-launch-mode="direct"/);
	assert.ok(template.includes('data-cb-design-exit-url="<?php echo esc_url( \\CB\\Automations\\Admin\\AutomationsPage::url() ); ?>"'));
	assert.match(assets, /enqueue_designer_mode\( __\( 'Automation Builder'/);
	assert.doesNotMatch(assets, /admin-builder-bootstrap\.js|admin-builder-layout\.js|admin_body_class|builder_launch_requested/);
	assert.match(template, /data-cb-design-shell-context/);
	assert.match(template, /data-cb-automations-workflow-switcher/);
	assert.doesNotMatch(template, /cb-automations-design-shell__identity/);
	assert.match(shell, /querySelector\('\[data-cb-automations-workflow-switcher\]'\)/);
	assert.match(shell, /cb:design-shell:contextrequest/);
	assert.match(shell, /launchRoot\?\.addEventListener\('cb:design-shell:contextrequest'/);
	assert.match(shell, /event\.detail\.respondWith/);
	assert.match(shell, /isDirty\(\)/);
	assert.doesNotMatch(shell, /showModal\(\{/);
	assert.doesNotMatch(shell, /chooseSwitchAction|awaitingChoice|approvedSwitchUrl/);
	assert.match(shell, /saveBeforeSwitch/);
	assert.match(shell, /form\.requestSubmit\(saveButton\)/);
	assert.doesNotMatch(shell, /workflowSwitcher\.dispatchEvent\(new Event\('change'/);
	assert.match(shell, /event\.detail\.respondWith\(switchContext\(url\)\)/);
	assert.match(save, /cbAutomationsAsyncSaveReady = true/);
	assert.doesNotMatch(shell, /window\.confirm\(/);
	assert.match(shell, /cb:design-shell:savechange/);
	assert.match(shell, /new URL\(target, window\.location\.href\)/);
	assert.match(shell, /url\.origin !== window\.location\.origin/);
	assert.doesNotMatch(shell, /window\.location\.assign\(/);
	assert.match(shell, /switchContext\(url\)/);
	assert.match(shell, /cb-automations:contextloaded/);

	assert.match(inspector, /dataset\.cbBuilderSelectable/);
	assert.match(inspector, /Selected step|selectedStep/);
	assert.match(inspector, /GET DATA/);
	assert.match(inspector, /ONLY IF/);
	assert.match(inspector, /key: `state:\$\{value\?\.step_id \|\| index\}`/);
	assert.match(inspector, /key: `condition:\$\{value\?\.condition_id \|\| index\}`/);
	assert.match(inspector, /key: `action:\$\{value\?\.step_id \|\| index\}`/);
	assert.match(inspector, /panelBody = panel\?\.querySelector\('\.cb-core-design-shell__panel-body'\)/);
	assert.match(inspector, /panelBody\.replaceChildren\(\)/);
	assert.match(inspector, /inspectorApi\.createIdentity\(/);
	assert.match(inspector, /inspectorApi\.createControls\(/);
	assert.match(inspector, /inspectorApi\.createSection\(/);
	assert.match(inspector, /sectionStates\.get\(record\.key\)/);
	assert.match(inspector, /cb-core-design-shell__panel-section-description/);
	assert.match(inspector, /cb-core-design-shell__panel-section-title/);
	assert.doesNotMatch(inspector, /panel\.replaceChildren\(\)/);

	assert.match(editor, /window\.cbAutomationsEditorSession/);
	assert.match(editor, /cb-automations:definitionchange/);
	assert.match(editor, /window\.cbCore\?\.designEditor\?\.motion\?\.animateLayoutChange/);
	assert.match(history, /createDomainSession/);
	assert.match(history, /session\.captureSave\(\)/);
	assert.match(history, /session\.rebase\(/);
	assert.doesNotMatch(history, /\b(?:CommandHistory|EditorState|ProjectState)\b/);
	assert.match(history, /resetForContext/);
	assert.match(history, /session\.undo\(\)/);
	assert.match(history, /session\.redo\(\)/);
	assert.match(history, /session\.baselineSnapshot\(\)/);

	assert.match(save, /new FormData\(form\)/);
	assert.match(save, /payload\.set\(ASYNC_FIELD, '1'\)/);
	assert.match(save, /window\.fetch\(endpoint/);
	assert.match(save, /cb-automations:validation-saved/);
	assert.match(shell, /exitGuard\.register\(root, \{/);
	assert.match(shell, /window\.history\.replaceState\(/);
	assert.match(inspector, /setAttribute\('role', 'group'\)/);
	assert.match(save, /aliases\.includes\(submitter\)/);
	assert.match(save, /event\.preventDefault\(\)/);
	assert.match(save, /submitter != null && submitter !== save/);
	assert.match(template, /data-cb-automations-save-alias/);
	assert.doesNotMatch(save, /health\.append\(|health\.querySelector\('\.cb-automations-panel-heading/);
	assert.match(save, /let saving = false/);
	assert.match(save, /updatePersistedStatuses\(result, !definitionDirty\)/);
	assert.match(save, /headingTitle\.textContent = result\.data\.name/);
	assert.match(save, /cb-core-status__dot--success/);
	assert.match(save, /cb-core-status__dot--warning/);
	assert.match(save, /cb-core-status__dot--danger/);
	assert.match(save, /cb-core-status__dot--muted/);
	assert.match(save, /baselineDefinition: submittedDefinition/);
	assert.match(save, /baselineName: result\.data\.name/);
	assert.match(save, /const currentDirty = definitionDirty \|\| nameDirty \|\| activationDirty \|\| rebindDirty/);
	assert.match(save, /\bcurrentDirty,\s*\}/);
	assert.match(save, /cb:design-shell:savechange/);

	assert.match(controller, /new\s+WorkflowService\(\)\s*\)->save\(/);
	assert.match(controller, /self::guard\( 'cb_automations_save_workflow' \)/);
	assert.match(controller, /PersistencePolicy::allows_encoded_definition/);
	assert.match(controller, /DefinitionCodec::decode/);
	assert.match(controller, /ValidationState::from_result\( \$result->validation\(\) \)->value/);
	assert.match(controller, /'name'\s*=>\s*\$witness\['name'\]\s*\?\?\s*\$persisted_name/);
	assert.match(controller, /\$witness\s*=\s*\$result->persisted_receipt\(\)/);
	assert.match(controller, /if\s*\(\s*\$result->was_saved\(\)\s*&&\s*null\s*!==\s*\$witness\s*\)/);
	assert.match(controller, /'workflow_id'\s*=>\s*\$witness\['workflow_id'\]/);
	assert.match(controller, /'revision'\s*=>\s*\$witness\['revision'\]/);
	assert.doesNotMatch(controller, /'revision'\s*=>\s*\(string\)\s*\(\s*\$result->was_saved\(\)\s*\?\s*\$revision\s*\+\s*1/);
	assert.match(controller, /'activation_state'\s*=>\s*\$persisted_state->value/);
	assert.doesNotMatch(controller, /WorkflowRepository::(?:create|update)\(/);

	assert.match(assets, /activationEnabled/);
	assert.match(assets, /validationDependencyUnavailable/);
	assert.match(template, /\$definition_json\s*=\s*wp_json_encode\(\s*\$editor_data\['workflow'\]\['definition'\]\s*\)/);
	assert.match(template, /name="definition_json".*data-cb-automations-definition/);
	assert.match(editor, /hidden\.value = JSON\.stringify\(definition\)/);
	assert.match(editor, /renderAll\('initial'\)/);

	assert.doesNotMatch(designerCss, /\.cb-automations-design-shell \.cb-core-design-shell__workspace/);
	assert.match(adminCss, /\.cb-automations-stage\s*\{[^}]*margin-bottom:\s*0\s*!important;/s);
	assert.match(adminCss, /\.cb-automations-stage:not\(:last-child\)::after\s*\{[^}]*height:\s*var\(--cb-space-4\);/s);
	assert.ok((template.match(/class="cb-core-design-shell__panel-body"/g) || []).length >= 2);
	assert.match(template, /class="cb-core-design-shell__panel-section"/);
	assert.match(template, /class="cb-core-design-shell__field"/);
	assert.match(template, /data-cb-design-shell-sidebar-role="inspector"/);
	assert.match(template, /data-cb-design-shell-sidebar-role="settings"/);
	assert.doesNotMatch(template, /data-cb-design-shell-tab="health"/);
	assert.doesNotMatch(designerCss, /\.cb-automations-design-shell__sidebar\s*\{/);
	assert.doesNotMatch(designerCss, /\.cb-automations-design-shell__sidebar \.cb-core-design-shell__sidebar-(?:tabs|tab|panel)/);
	assert.doesNotMatch(designerCss, /cb-automations-design-shell__identity|cb-core-design-shell__toolbar-context|cb-core-design-shell__brand|cb-core-brand-lockup/);
	assert.doesNotMatch(shell, /cb-core-design-shell__brand|cb-core-brand-lockup|brand-wordmark|is-identity-condensed/);
	assert.doesNotMatch(finishCss, /is-palette-collapsed|is-sidebar-collapsed/);
	for (const source of [inspector, history, save, editor]) {
		assert.doesNotMatch(source, /assets\/js\/design\/|CB_CORE_URL/);
	}
});

test('Base B1 context authorization has a single consumer transaction', async () => {
  const shell = await read('assets/js/admin-designer-shell.js');
  const history = await read('assets/js/admin-builder-history.js');
  const assets = await read('src/Admin/DesignerAssets.php');
  assert.match(assets, /DESIGNER_EXIT_GUARD_API/);
  assert.match(assets, /DESIGNER_EXIT_GUARD_API' \) >= 2/);
  assert.match(shell, /event\.detail\.respondWith\(switchContext\(url\)\)/);
  assert.match(shell, /history\.prepareContext\(nextWorkflow\.definition\)/);
  assert.match(shell, /history\.resetForContext\(nextDefinition\)/);
  assert.match(history, /resetSelection: true/);
  assert.doesNotMatch(shell, /approvedSwitchUrl|chooseSwitchAction|awaitingChoice|new Event\('change'/);
});

test('Designer close and unload authority belong to the public Base Exit Guard', async () => {
	const source = await read('assets/js/admin-designer-shell.js');
	assert.match(source, /const exitGuard = window\.cbCore\?\.designerExitGuard/);
	assert.match(source, /exitGuard\.register\(root, \{/);
	assert.match(source, /isDirty,/);
	assert.match(source, /isUncertain: \(\) => root\.dataset\.cbDesignShellSaveOutcomeUnknown/);
	assert.match(source, /save: async \(\) => \{/);
	assert.match(source, /await saveBeforeSwitch\(\)/);
	assert.match(source, /cleanTitle:/);
	assert.match(source, /dirtyDescription:/);
	assert.match(source, /close: String\(labels\.closeBuilder/);
	assert.match(source, /phase: 'exit-guard'/);
	assert.doesNotMatch(source, /const requestExit = async|chooseExitAction = async/);
	assert.doesNotMatch(source, /allowConfirmedExit|window\.addEventListener\('beforeunload'\)|window\.addEventListener\('keydown'/);
	assert.doesNotMatch(source, /launchRoot\?\.addEventListener\('click',[\s\S]*?data-cb-design-shell-close/);
	assert.match(source, /createDesignerShell\(root, \{ defaultPanel: 'settings', defaultPanels: \{ palette: 'elements' \} \}\)/);
});

test('Automations supplies a bounded Base Exit Guard adapter with verified Save and UNKNOWN fencing', async () => {
	const source = await read('assets/js/admin-designer-shell.js');
	const block = source.match(/exitGuard\.register\(root, \{[\s\S]*?\n\t\t\t\}\);/);
	assert.ok(block, 'one public Base Exit Guard adapter is registered');
	const { runInNewContext } = await import('node:vm');
	let dirty = true;
	let candidate = null;
	let writes = 0;
	const root = { dataset: { cbDesignShellSaveOutcomeUnknown: 'false', cbDesignShellSaveState: 'idle' } };
	runInNewContext(block[0], {
		exitGuard: { register: (shell, adapter) => {
			assert.equal(shell, root);
			candidate = adapter;
		} },
		root,
		isDirty: () => dirty,
		saveBeforeSwitch: async () => { writes++; dirty = false; },
		labels: {},
	});
	assert.ok(candidate, 'Base has received the only authoritative close adapter');
	assert.equal(candidate.isDirty(), true);
	assert.equal(candidate.isUncertain(), false);
	assert.equal(await candidate.save(), true, 'verified Save permits close only once the draft is clean');
	assert.equal(writes, 1);
	root.dataset.cbDesignShellSaveOutcomeUnknown = 'true';
	assert.equal(candidate.isUncertain(), true, 'ambiguous writes remain fenced');
	root.dataset.cbDesignShellSaveOutcomeUnknown = 'false';
	root.dataset.cbDesignShellSaveState = 'unknown';
	assert.equal(candidate.isUncertain(), true, 'native Base UNKNOWN is also fenced');
	assert.equal(typeof candidate.labels.cleanTitle, 'string');
	assert.equal(typeof candidate.labels.dirtyDescription, 'string');
	assert.equal(typeof candidate.labels.cancel, 'string');
});
