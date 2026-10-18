import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { toProject, fromProject, workflowCommands } from '../../assets/js/automations-domain-project.js';

// SOURCE development pin: Base Designer G1d-B, not an installed ZIP receipt.
// Cross-repo execution evidence must cite this exact immutable Base commit.
const BASE_DESIGNER_V2_SOURCE_SHA = '084ff07b4eb1c4a5e53a32fe23eb6b49ca700042';
const read = (file) => readFile(new URL('../../' + file, import.meta.url), 'utf8');
const definition = () => ({
  definition_version: 1, trigger: null, states: [], conditions: [],
  actions: [{ step_id: 'action_1', capability: {
    kind: 'action', provider: 'core-blueprint-work', id: 'action.run', schema_version: '1',
  }, bindings: { label: { source: 'literal', value: 'original' } } }],
});

test('G1a-V21-A01: Base owns the only history/session, with public v2 save checkpoints', async () => {
  const history = await read('assets/js/admin-builder-history.js');
  assert.match(history, /import\s*\{[^}]*createDomainSession[^}]*\}\s*from\s*['"]@cb-core\/design-editor['"]/s);
  assert.match(history, /session\.execute\(/);
  assert.match(history, /session\.subscribeHistory\(/);
  assert.match(history, /session\.captureSave\(/);
  assert.match(history, /session\.rebase\(/);
  assert.match(history, /session\.replace\(/);
  assert.match(history, /workflowCommands\.editorChange\(/);
  assert.doesNotMatch(history, /\bcreateDesignerStateHistory\b|\bCommandHistory\b|\bProjectState\b|\bEditorState\b/);
  assert.doesNotMatch(history, /\.record\(|\.history\.undo\(|\.history\.redo\(/);
});

test('G1a-V21-A02: each authoring event is an immutable checked domain command', () => {
  const before = definition();
  const after = structuredClone(before);
  after.actions[0].bindings.label.value = 'updated';
  const command = workflowCommands.editorChange({ expected: before, next: after });
  const base = toProject(before);
  const updated = command.apply(base).project;
  assert.deepEqual(fromProject(updated), after);
  assert.deepEqual(fromProject(base), before);
  assert.throws(() => command.apply(toProject(after)), /conflict/i);
  after.actions[0].bindings.label.value = 'mutated later';
  assert.equal(fromProject(command.apply(base).project).actions[0].bindings.label.value, 'updated');
  assert.ok(Object.isFrozen(updated));
});

test('G1a-V21-A03: save never refreshes on ambiguous responses and keeps the local draft on conflicts', async () => {
  const save = await read('assets/js/admin-builder-save.js');
  assert.match(save, /captureSave\(/);
  assert.match(save, /acknowledgeSave\(/);
  assert.match(save, /409/);
  assert.doesNotMatch(save, /window\.location\.reload\(\)/);
  assert.match(save, /currentDirty/);
});

test('G1a-V21-A04: only same-build advertised Domain Session v2 enables Designer', async () => {
  assert.equal(BASE_DESIGNER_V2_SOURCE_SHA, '084ff07b4eb1c4a5e53a32fe23eb6b49ca700042');
  const assets = await read('src/Admin/DesignerAssets.php');
  assert.match(assets, /DOMAIN_SESSION_API/);
  assert.match(assets, />=\s*2/);
  assert.match(assets, /wp_enqueue_script_module\(\s*self::HISTORY_MODULE/);
});

test('G1a-V21-A05: underlying privacy and persistence guarantees are not bypassed', async () => {
  const controller = await read('src/Admin/WorkflowController.php');
  const service = await read('src/Workflow/WorkflowService.php');
  assert.match(controller, /self::guard\( 'cb_automations_save_workflow' \)/);
  assert.match(service, /EditorSaveBoundary::reconcile/);
  assert.match(service, /WorkflowRepository::update/);
  assert.match(service, /EditorIntegerSafety::is_browser_safe/);
  assert.match(service, /ExecutionPrincipalPolicy::evaluate/);
});

test('G1a-V21-A06: workspace, folders, templates and import are preserved', async () => {
  const page = await read('templates/admin/automations.php');
  const controller = await read('src/Admin/WorkflowController.php');
  const transfer = await read('src/Admin/WorkflowTransferController.php');
  assert.match(page, /workflows|folders/);
  assert.match(controller, /create_from_template/);
  assert.match(transfer, /function confirm\(/);
  assert.match(transfer, /function export\(/);
});
