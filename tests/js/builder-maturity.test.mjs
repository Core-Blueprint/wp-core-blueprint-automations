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
	assert.match(shell, /showModal\(\{/);
	assert.match(shell, /chooseSwitchAction/);
	assert.match(shell, /saveBeforeSwitch/);
	assert.match(shell, /form\.requestSubmit\(saveButton\)/);
	assert.match(shell, /workflowSwitcher\.dispatchEvent\(new Event\('change'/);
	assert.match(shell, /approvedSwitchUrl === url\.href/);
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
	assert.match(shell, /beforeunload/);
	assert.match(shell, /window\.history\.replaceState\(/);
	assert.match(inspector, /setAttribute\('role', 'group'\)/);
	assert.match(save, /aliases\.includes\(event\.submitter\)/);
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
	assert.match(save, /baselineName: submittedName/);
	assert.match(save, /currentDirty: definitionDirty/);
	assert.match(save, /cb:design-shell:savechange/);

	assert.match(controller, /new\s+WorkflowService\(\)\s*\)->save\(/);
	assert.match(controller, /self::guard\( 'cb_automations_save_workflow' \)/);
	assert.match(controller, /PersistencePolicy::allows_encoded_definition/);
	assert.match(controller, /DefinitionCodec::decode/);
	assert.match(controller, /ValidationState::from_result\( \$result->validation\(\) \)->value/);
	assert.match(controller, /'name'\s*=>\s*\$persisted_name/);
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

test('Context switch uses the live Designer root and dispatches both lifecycle events', async () => {
	const source = await read('assets/js/admin-designer-shell.js');
	const block = source.match(/const switchContext = async \(url\) => \{[\s\S]*?\n\t\t\};/);
	assert.ok(block, 'context switch must remain separately identifiable');
	assert.doesNotMatch(block[0], /\bshell\.(?:hasAttribute|dispatchEvent)\(/, 'no undefined shell alias in switch path');

	const events = [];
	const updated = { id: null, revision: null };
	const currentId = { value: '1' };
	const currentRevision = { value: '1' };
	const contextDataNode = { textContent: JSON.stringify({ capabilities: [], operators: [] }) };
	const nextDataNode = { textContent: JSON.stringify({
		workflow: { id: 2, definition: { trigger: null, actions: [] } },
		capabilities: [], operators: [], validation: [],
	}) };
	const fields = {
		'[name="workflow_id"]': currentId,
		'[name="revision"]': currentRevision,
	};
	const incoming = {
		'[name="workflow_id"]': { value: '2' },
		'[name="revision"]': { value: '3' },
		'[name="name"]': { value: 'Workflow B' },
		'[name="activation_state"]': { value: 'disabled' },
	};
	const root = {
		hasAttribute: () => false,
		dispatchEvent: (event) => { events.push(event.type); },
	};
	const form = { querySelector: (selector) => fields[selector] || null };
	const nextForm = { querySelector: (selector) => incoming[selector] || null };
	const nextDocument = {
		getElementById: (id) => id === 'cb-automations-editor-data' ? nextDataNode : null,
		querySelector: (selector) => selector === '[data-cb-automations-editor-form]' ? nextForm : null,
		querySelectorAll: () => [],
	};
	const windowMock = {
		cbAutomationsEditorSession: { replace: (definition) => { updated.definition = definition; } },
		cbAutomationsBuilderHistory: { resetForContext: (definition) => { updated.history = definition; } },
		history: { state: null, replaceState: (_state, _title, href) => { updated.href = href; } },
	};
	const { runInNewContext } = await import('node:vm');
	const switchContext = runInNewContext(block[0] + '\nswitchContext;', {
		root, form, contextDataNode, window: windowMock,
		contextError: (_key, message) => new Error(message),
		fetch: async () => ({ ok: true, text: async () => '' }),
		DOMParser: class { parseFromString() { return nextDocument; } },
		document: { querySelector: () => null, querySelectorAll: () => [] },
		CustomEvent: class { constructor(type) { this.type = type; } },
		name: { value: 'Workflow A' },
		activation: { value: 'disabled' },
		authority: { checked: false },
		baseline: {},
		normalized: (value) => value,
	});
	const url = new URL('https://example.test/wp-admin/admin.php?page=core-blueprint-automations-workflows&workflow=2');
	const result = await switchContext(url);
	assert.equal(result.workflowId, 2);
	assert.equal(currentId.value, '2');
	assert.equal(currentRevision.value, '3');
	assert.deepEqual(events, ['cb-automations:validation-saved', 'cb-automations:contextloaded']);
	assert.equal(updated.href, url.href);
	assert.ok(updated.definition && updated.history, 'editor and history were refreshed');
});

test('Designer close confirmation is required with or without changes', async () => {
	const source = await read('assets/js/admin-designer-shell.js');
	const block = source.match(/const chooseExitAction = async \(\) => \{[\s\S]*?\n\t\t\};/);
	assert.ok(block, 'exit choice helper is present');
	const { runInNewContext } = await import('node:vm');
	let dirty = false;
	let modalResult = false;
	let opened = 0;
	let exitPurpose = null;
	const select = runInNewContext(block[0] + '\nchooseExitAction;', {
		isDirty: () => dirty,
		showModal: async ({ title, confirmLabel, cancelLabel }) => {
			opened += 1;
			assert.equal(title, 'Close Automation Builder?');
			assert.equal(confirmLabel, 'Close Builder');
			assert.equal(cancelLabel, 'Cancel');
			return modalResult;
		},
		chooseSwitchAction: async (purpose) => {
			exitPurpose = purpose;
			return 'discard';
		},
		window: { cbAutomationsInspectorStrings: {} },
	});
	assert.equal(await select(), null, 'cancel keeps a clean workflow open');
	modalResult = true;
	assert.equal(await select(), 'close', 'clean close also requires confirmation');
	dirty = true;
	assert.equal(await select(), 'discard', 'dirty close delegates to save/discard choices');
	assert.equal(exitPurpose, 'exit');
	assert.equal(opened, 2, 'no double modal when changes exist');
	assert.match(source, /launchRoot\?\.addEventListener\('click',[\s\S]*?data-cb-design-shell-close/);
	assert.match(source, /window\.addEventListener\('keydown',[\s\S]*?requestExit\(\)/);
	assert.match(source, /if \(allowConfirmedExit \|\| !isDirty\(\)\) return;/);
	assert.match(source, /allowConfirmedExit = true;/);
	assert.match(source, /designerController = createDesignerShell\(root, \{ defaultPanel: 'settings' \}\)/);
	assert.match(source, /designerController\.exitFullscreen\(\)/);
	assert.doesNotMatch(source, /approvedCloseClick/);
	assert.doesNotMatch(source, /root\.querySelector\('\[data-cb-design-shell-fullscreen\]'\)/);
	assert.match(source, /if \(root\.hasAttribute\('aria-busy'\)\) \{[\s\S]*?saveInProgress/);
});

test('Designer close invokes Base shell controller even when fullscreen button is detached', async () => {
	const source = await read('assets/js/admin-designer-shell.js');
	const match = source.match(/const requestExit = async \(\) => \{[\s\S]*?\n\t\t\};/);
	assert.ok(match, 'the guarded exit function remains separately testable');
	const { runInNewContext } = await import('node:vm');
	const runCase = async ({ selection = 'close', saving = false, fullscreen = true, exitResult = true } = {}) => {
		const status = { textContent: '' };
		let exits = 0;
		let prompts = 0;
		const state = {
			root: {
				hasAttribute: (name) => name === 'aria-busy' && saving,
				querySelector: () => status,
			},
			window: { cbAutomationsInspectorStrings: {} },
			designerController: {
				isFullscreen: () => fullscreen,
				exitFullscreen: () => { exits += 1; return exitResult; },
			},
			chooseExitAction: async () => { prompts += 1; return selection; },
			contextError: (_key, message) => new Error(message),
			exitPending: false,
			allowConfirmedExit: false,
		};
		const requestExit = runInNewContext(match[0] + '\nrequestExit;', state);
		await requestExit();
		return { status, exits, prompts, state };
	};
	const confirmed = await runCase({ selection: 'discard' });
	assert.equal(confirmed.exits, 1, 'the Base controller is called for confirmed discard');
	assert.equal(confirmed.state.allowConfirmedExit, true, 'beforeunload is bypassed only after approval');
	assert.equal((await runCase({ selection: null })).exits, 0, 'Cancel never exits');
	assert.equal((await runCase({ saving: true })).prompts, 0, 'saving blocks the confirmation');
	const unavailable = await runCase({ fullscreen: false });
	assert.equal(unavailable.exits, 0, 'fullscreen inactive fails closed');
	assert.match(unavailable.status.textContent, /unavailable/i);
	const rejected = await runCase({ exitResult: false });
	assert.equal(rejected.exits, 1);
	assert.equal(rejected.state.allowConfirmedExit, false, 'failed close restores unload protection');
	assert.match(rejected.status.textContent, /could not be closed/i);
});
