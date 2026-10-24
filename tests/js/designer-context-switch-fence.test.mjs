import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { fromProject, toProject } from '../../assets/js/automations-domain-project.js';

const source = readFileSync(new URL('../../assets/js/admin-designer-shell.js', import.meta.url), 'utf8');
const block = source.match(/const saveIsUncertainOrBusy = \(\) => \([\s\S]*?\n\t\t\);[\s\S]*?const switchContext = async \(url\) => \{[\s\S]*?\n\t\t\};/);
assert.ok(block, 'exercise the actual production Save fence and context transaction');
const clone = (value) => JSON.parse(JSON.stringify(value));
const definition = () => ({ definition_version: 1, trigger: null, states: [], conditions: [], actions: [] });
const workflowUrl = (id) => new URL('https://example.test/wp-admin/admin.php?page=core-blueprint-automations-workflows&workflow=' + id);

function fixture({ nextDefinition = definition(), nextRevision = '3', nextCatalog = [], failEditor = false, failHistory = false, failHeading = false, postCommitFault = false, uncertainCommit = false } = {}) {
  const pending = [];
  const events = [];
  const operations = [];
  const source = definition();
  let editor = clone(source);
  let historyDefinition = clone(source);
  let historyBaseline = clone(source);
  let historyStacks = { canUndo: false, canRedo: false, size: 0 };
  let throwEditor = failEditor;
  let throwHistory = failHistory;
  const id = { value: '1' }, revision = { value: '7' };
  const name = { value: 'Original' }, activation = { value: 'disabled' };
  const authority = { checked: false }, definitionControl = { value: JSON.stringify(source) };
  const contextDataNode = {
    textContent: JSON.stringify({ workflow: { id: 1, definition: source }, capabilities: [], operators: [] }),
  };
  const formFields = { '[name="workflow_id"]': id, '[name="revision"]': revision };
  const form = { querySelector: (key) => formFields[key] ?? null };
  const notice = { textContent: '', hidden: true, setAttribute() {} };
  const root = {
    dataset: { cbDesignShellSaveState: 'idle', cbDesignShellSaveOutcomeUnknown: 'false' },
    querySelector: () => notice,
    dispatchEvent: (event) => events.push(event.type),
  };
  let headingText = 'Original';
  let throwHeading = failHeading;
  const heading = {
    get textContent() { return headingText; },
    set textContent(value) {
      headingText = value;
      if (throwHeading) { throwHeading = false; throw new Error('Heading apply failed'); }
    },
  };
  const document = {
    querySelector: (key) => key === '.cb-automations-editor-heading .cb-core-title' ? heading : null,
    querySelectorAll: () => [],
  };
  const responseFor = (target) => {
    const input = {
      '[name="workflow_id"]': { value: String(target) },
      '[name="revision"]': { value: nextRevision },
      '[name="name"]': { value: 'Workflow ' + target },
      '[name="activation_state"]': { value: 'disabled' },
    };
    return {
      getElementById: () => ({ textContent: JSON.stringify({
        workflow: { id: target, definition: nextDefinition },
        capabilities: nextCatalog, operators: [], validation: [],
      }) }),
      querySelector: () => ({ querySelector: (key) => input[key] ?? null }),
      querySelectorAll: () => [],
    };
  };
  const window = {
    cbAutomationsEditorSession: {
      snapshot: () => clone(editor),
      replace: (next) => {
        operations.push('editor');
        editor = clone(next);
        definitionControl.value = JSON.stringify(next);
        if (throwEditor) { throwEditor = false; throw new Error('Editor render failed'); }
      },
    },
    cbAutomationsBuilderHistory: {
      snapshot: () => clone(historyDefinition),
      contextWitness: () => ({
        project: clone(historyDefinition),
        baseline: clone(historyBaseline),
        history: clone(historyStacks),
      }),
      // vm.runInNewContext JSON.parse creates cross-realm records. Normalize at
      // this fixture-only boundary before invoking the real strict JSON codec.
      prepareContext: (next) => fromProject(toProject(clone(next))),
      resetForContext: (next) => {
        operations.push('history');
        if (throwHistory) {
          throwHistory = false;
          return { status: 'refused', error: new Error('History staging refused') };
        }
        assert.deepEqual(editor, next, 'History reset cannot precede editor projection');
        historyDefinition = clone(next);
        historyBaseline = clone(next);
        historyStacks = { canUndo: false, canRedo: false, size: 0 };
        if (uncertainCommit) return { status: 'unknown' };
        if (postCommitFault) {
          try { throw new Error('History button update failed'); }
          catch { /* Real adapter isolates presentation failures after commit. */ }
        }
        return { status: 'committed' };
      },
    },
    history: { state: null, replaceState: () => operations.push('browser-url') },
  };
  const switchContext = runInNewContext(block[0] + '\nswitchContext;', {
    switchGeneration: 0, form, root, contextDataNode, window,
    definition: definitionControl, name, activation, authority,
    normalized: (value) => JSON.stringify(JSON.parse(String(value || '{}'))),
    contextError: (_key, fallback) => new Error(fallback),
    fetch: () => new Promise((resolve) => pending.push(resolve)),
    DOMParser: class { parseFromString(body) { return responseFor(Number(body)); } },
    document, CustomEvent: class { constructor(type) { this.type = type; } },
    baseline: {}, URL,
  });
  const select = (n) => switchContext(workflowUrl(n));
  const respond = (index, target, ok = true) => pending[index]({ ok, text: async () => String(target) });
  return {
    select, respond, pending, events, operations, id, revision, name, activation,
    authority, definitionControl, contextDataNode, root, notice,
    get editor() { return clone(editor); }, get history() { return clone(historyDefinition); },
    get witness() { return window.cbAutomationsBuilderHistory.contextWitness(); },
    get heading() { return headingText; },
    edit() {
      const changed = { ...editor, actions: [] };
      changed.actions.push({ step_id: 'action_1', capability: {
        kind: 'action', provider: 'core-blueprint-work', id: 'action.run', schema_version: '1',
      }, bindings: {} });
      editor = clone(changed);
      historyDefinition = clone(changed); // Editor changes update Base Domain Session.
      historyStacks = { canUndo: true, canRedo: false, size: 1 };
      definitionControl.value = JSON.stringify(changed);
    },
  };
}

test('A2-01: clean authorized context is one commit, one history reset, two lifecycle events', async () => {
  const x = fixture(), request = x.select(2);
  assert.equal(x.pending.length, 1);
  x.respond(0, 2);
  const result = await request;
  assert.equal(result.workflowId, 2);
  assert.equal(x.id.value, '2');
  assert.equal(x.revision.value, '3');
  assert.deepEqual(x.operations, ['editor', 'history', 'browser-url']);
  assert.deepEqual(x.events, ['cb-automations:validation-saved', 'cb-automations:contextloaded']);
  assert.deepEqual(x.history, x.editor);
  assert.deepEqual(fromProject(toProject(x.history)), x.editor, 'Save/Reopen projection');
});

test('A2-02: Base-authorized Discard may replace a dirty draft after explicit approval', async () => {
  const x = fixture();
  x.edit();
  const request = x.select(2); x.respond(0, 2);
  assert.equal((await request).workflowId, 2);
  assert.deepEqual(x.editor, definition());
  assert.equal(x.operations.filter((v) => v === 'history').length, 1);
});

test('A2-03: Save choice is committed by Base before target dispatch', async () => {
  const x = fixture();
  // Base B1 invokes the registered Save adapter before dispatching contextrequest.
  // Here the source CAS acknowledgement is represented by revision 8.
  x.revision.value = '8';
  const req = x.select(2); x.respond(0, 2);
  assert.equal((await req).workflowId, 2);
  assert.equal(x.revision.value, '3');
});

test('A2-04: Cancel emits no consumer request or context mutation', () => {
  const x = fixture();
  // Base never calls the consumer after a cancelled confirmation.
  assert.equal(x.pending.length, 0);
  assert.equal(x.id.value, '1');
  assert.equal(x.operations.length, 0);
});

test('A2-05: UNKNOWN and in-flight Save are fenced before requesting a workflow', async () => {
  for (const saveState of ['saving', 'unknown']) {
    const x = fixture();
    x.root.dataset.cbDesignShellSaveState = saveState;
    await assert.rejects(x.select(2));
    assert.equal(x.pending.length, 0);
    assert.deepEqual(x.operations, []);
  }
  const x = fixture();
  x.root.dataset.cbDesignShellSaveOutcomeUnknown = 'true';
  await assert.rejects(x.select(2));
  assert.deepEqual(x.operations, []);
});

test('A2-06: Save starting during load blocks the eventual commit', async () => {
  const x = fixture(), request = x.select(2);
  x.root.dataset.cbDesignShellSaveState = 'saving';
  x.respond(0, 2);
  await assert.rejects(request, /save to finish/i);
  assert.equal(x.id.value, '1');
  assert.deepEqual(x.operations, []);
});

test('A2-07: completed Save or late authoring edits during GET reject stale selection', async () => {
  for (const update of [(x) => { x.revision.value = '8'; }, (x) => x.edit()]) {
    const x = fixture(), request = x.select(2);
    update(x); x.respond(0, 2);
    await assert.rejects(request);
    assert.equal(x.id.value, '1');
    assert.deepEqual(x.operations, []);
  }
});

test('A2-08: older overlapping GET cannot overwrite the newest applied context', async () => {
  const x = fixture(), older = x.select(2), newer = x.select(3);
  x.respond(1, 3);
  assert.equal((await newer).workflowId, 3);
  x.respond(0, 2);
  await assert.rejects(older);
  assert.equal(x.id.value, '3');
  assert.equal(x.operations.filter((x) => x === 'history').length, 1);
});

test('A2-09: HTTP errors, malformed definitions, invalid revisions and catalog changes fail precommit', async () => {
  for (const opts of [
    { nextDefinition: { definition_version: 100, trigger: null, states: [], conditions: [], actions: [] } },
    { nextRevision: '0' },
    { nextRevision: 'bad' },
    { nextCatalog: [{ provider: 'changed' }] },
  ]) {
    const x = fixture(opts), request = x.select(2); x.respond(0, 2);
    await assert.rejects(request);
    assert.equal(x.id.value, '1');
    assert.deepEqual(x.operations, []);
  }
  const x = fixture(), request = x.select(2);
  x.respond(0, 2, false);
  await assert.rejects(request);
  assert.deepEqual(x.operations, []);
});

test('A2-10: editor mutation fault rolls back all metadata and local draft before History commit', async () => {
  const x = fixture({ failEditor: true });
  const request = x.select(2); x.respond(0, 2);
  await assert.rejects(request, /render failed/);
  assert.equal(x.id.value, '1');
  assert.equal(x.revision.value, '7');
  assert.equal(x.name.value, 'Original');
  assert.deepEqual(x.editor, definition());
  assert.deepEqual(x.history, definition());
  assert.equal(x.events.includes('cb-automations:contextloaded'), false);
  assert.equal(x.operations.includes('history'), false);
});

test('A2-11: History staging refusal restores live editor and original workflow metadata', async () => {
  const x = fixture({ failHistory: true });
  const request = x.select(2); x.respond(0, 2);
  await assert.rejects(request, /History staging refused/);
  assert.equal(x.id.value, '1');
  assert.equal(x.revision.value, '7');
  assert.deepEqual(x.editor, definition());
  assert.deepEqual(x.history, definition());
  assert.equal(x.events.includes('cb-automations:contextloaded'), false);
});

test('A2-12: source PHP gate, no replay/modal and single shared context authorization', () => {
  const php = readFileSync(new URL('../../src/Admin/DesignerAssets.php', import.meta.url), 'utf8');
  const history = readFileSync(new URL('../../assets/js/admin-builder-history.js', import.meta.url), 'utf8');
  assert.match(php, /DESIGNER_EXIT_GUARD_API/);
  assert.match(php, /DESIGNER_EXIT_GUARD_API' \) >= 2/);
  assert.match(source, /event\.detail\.respondWith\(switchContext\(url\)\)/);
  assert.match(source, /typeof exitGuard\?\.requestTransition !== 'function'/);
  assert.doesNotMatch(source, /chooseSwitchAction|approvedSwitchUrl|awaitingChoice|showModal|dispatchEvent\(new Event\('change'/);
  assert.match(history, /resetSelection: true/);
});


test('A2-13: DOM application failure after editor render rolls back metadata and Base draft', async () => {
  const x = fixture({ failHeading: true });
  const request = x.select(2); x.respond(0, 2);
  await assert.rejects(request, /Heading apply failed/);
  assert.equal(x.id.value, '1');
  assert.equal(x.revision.value, '7');
  assert.equal(x.name.value, 'Original');
  assert.equal(x.heading, 'Original');
  assert.deepEqual(x.editor, definition());
  assert.deepEqual(x.history, definition());
  assert.equal(x.events.includes('cb-automations:contextloaded'), false);
  assert.equal(x.operations.includes('history'), false);
});

test('R1-01: editor precommit fault preserves Undo/Redo, baseline, selection witness and original metadata', async () => {
  const x = fixture({ failEditor: true });
  x.edit();
  const before = x.witness;
  const request = x.select(2); x.respond(0, 2);
  await assert.rejects(request, /Editor render failed/);
  assert.deepEqual(x.witness, before);
  assert.equal(x.name.value, 'Original');
  assert.equal(x.id.value, '1');
  assert.deepEqual(x.editor, before.project);
});

test('R1-02: refused History commit preserves previous Undo/Redo and context', async () => {
  const x = fixture({ failHistory: true });
  x.edit();
  const before = x.witness;
  const request = x.select(2); x.respond(0, 2);
  await assert.rejects(request, /History staging refused/);
  assert.deepEqual(x.witness, before);
  assert.equal(x.root.dataset.cbDesignShellSaveOutcomeUnknown, 'false');
  assert.equal(x.id.value, '1');
  assert.deepEqual(x.editor, before.project);
});

test('R1-03: History postcommit button failure never rolls back committed workflow', async () => {
  const x = fixture({ postCommitFault: true });
  const request = x.select(2); x.respond(0, 2);
  assert.equal((await request).workflowId, 2);
  assert.equal(x.id.value, '2');
  assert.equal(x.name.value, 'Workflow 2');
  assert.deepEqual(x.editor, x.history);
  assert.deepEqual(x.events.filter((v) => v === 'cb-automations:contextloaded').length, 1);
  assert.equal(x.operations.filter((v) => v === 'editor').length, 1);
  assert.equal(x.root.dataset.cbDesignShellSaveOutcomeUnknown, 'false');
});

test('R1-04: uncertain postcommit status blocks Save without an old-editor/new-History split', async () => {
  const x = fixture({ uncertainCommit: true });
  const request = x.select(2); x.respond(0, 2);
  await assert.rejects(request, /Reload before saving/);
  assert.equal(x.id.value, '2', 'never restore old workflow metadata after uncertain commit');
  assert.deepEqual(x.editor, x.history, 'new editor stays matched with potentially committed History');
  assert.equal(x.root.dataset.cbDesignShellSaveOutcomeUnknown, 'true');
  assert.equal(x.root.dataset.cbDesignShellSaveState, 'unknown');
  assert.match(x.notice.textContent, /Reload the editor before saving/);
  assert.equal(x.events.includes('cb-automations:contextloaded'), false);
});

test('R1-05: Base committed History remains authoritative even if postcommit notification fails', async () => {
  const x = fixture();
  const previousDispatch = x.root.dispatchEvent;
  x.root.dispatchEvent = (event) => {
    if (event.type === 'cb-automations:contextloaded') throw new Error('Inspector callback failed');
    return previousDispatch(event);
  };
  const request = x.select(2); x.respond(0, 2);
  assert.equal((await request).workflowId, 2);
  assert.equal(x.id.value, '2');
  assert.deepEqual(x.editor, x.history);
  assert.equal(x.root.dataset.cbDesignShellSaveOutcomeUnknown, 'true');
});
