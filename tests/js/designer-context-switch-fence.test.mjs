import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../assets/js/admin-designer-shell.js', import.meta.url), 'utf8');
const match = source.match(/const switchContext = async \(url\) => \{[\s\S]*?\n\t\t\};/);
assert.ok(match, 'Test must exercise the real shipped context switching function');

const definition = () => ({
  definition_version: 1, trigger: null, states: [], conditions: [], actions: [],
});
function fixture() {
  const pending = [];
  const edits = [];
  let busy = false;
  const id = { value: '1' };
  const revision = { value: '7' };
  const name = { value: 'Original' };
  const activation = { value: 'disabled' };
  const authority = { checked: false };
  const definitionControl = { value: JSON.stringify(definition()) };
  const contextDataNode = { textContent: JSON.stringify({ workflow: { id: 1, definition: definition() }, capabilities: [], operators: [] }) };
  const form = {
    querySelector: (selector) => ({
      '[name="workflow_id"]': id,
      '[name="revision"]': revision,
      '[data-cb-automations-principal-label]': null,
    })[selector] ?? null,
  };
  const root = {
    hasAttribute: (attr) => attr === 'aria-busy' && busy,
    dispatchEvent: (event) => { edits.push(['event', event.type]); },
  };
  const document = { querySelector: () => null, querySelectorAll: () => [] };
  const responseFor = (newId) => {
    const fields = {
      '[name="workflow_id"]': { value: String(newId) },
      '[name="revision"]': { value: '3' },
      '[name="name"]': { value: 'Workflow ' + newId },
      '[name="activation_state"]': { value: 'disabled' },
    };
    return {
      getElementById: () => ({ textContent: JSON.stringify({
        workflow: { id: newId, definition: definition() },
        capabilities: [], operators: [], validation: [],
      }) }),
      querySelector: () => ({ querySelector: (selector) => fields[selector] ?? null }),
      querySelectorAll: () => [],
    };
  };
  const window = {
    cbAutomationsEditorSession: { replace: () => edits.push(['replace']) },
    cbAutomationsBuilderHistory: { resetForContext: () => edits.push(['history']) },
    history: { state: null, replaceState: () => edits.push(['location']) },
  };
  const switchContext = runInNewContext(match[0] + '\nswitchContext;', {
    root, form, contextDataNode, window,
    switchGeneration: 0, definition: definitionControl,
    name, activation, authority,
    normalized: (value) => JSON.stringify(JSON.parse(String(value || '{}'))),
    contextError: (_key, message) => new Error(message),
    fetch: () => new Promise((resolve) => pending.push(resolve)),
    DOMParser: class { parseFromString(body) { return responseFor(Number(body)); } },
    document, CustomEvent: class { constructor(type) { this.type = type; } },
  });
  const select = (newId) => switchContext(new URL(
    'https://example.test/wp-admin/admin.php?page=core-blueprint-automations-workflows&workflow=' + newId,
  ));
  const respond = (position, newId) => pending[position]({
    ok: true,
    text: async () => String(newId),
  });
  return {
    select, respond, pending, edits, id, revision, name,
    activation, authority, definitionControl,
    setBusy: (value) => { busy = value; },
  };
}

test('DC-07-A01: unchanged clean context may load once and resets Base History', async () => {
  const x = fixture();
  const load = x.select(2);
  assert.equal(x.pending.length, 1);
  x.respond(0, 2);
  const result = await load;
  assert.equal(result.workflowId, 2);
  assert.equal(x.id.value, '2');
  assert.deepEqual(x.edits.map(v => v[0]), ['replace', 'history', 'event', 'event', 'location']);
});

test('DC-07-A02: a Save starting while context GET is pending blocks context mutation', async () => {
  const x = fixture();
  const load = x.select(2);
  x.setBusy(true);
  x.respond(0, 2);
  await assert.rejects(load, /save to finish/i);
  assert.equal(x.id.value, '1');
  assert.equal(x.revision.value, '7');
  assert.deepEqual(x.edits, []);
});

test('DC-07-A03: completed Save during GET (changed CAS revision) cannot be overwritten', async () => {
  const x = fixture();
  const load = x.select(2);
  x.revision.value = '8';
  x.respond(0, 2);
  await assert.rejects(load);
  assert.equal(x.id.value, '1');
  assert.equal(x.revision.value, '8');
  assert.deepEqual(x.edits, []);
});

test('DC-07-A04: new workflow authoring edits during GET cannot be discarded', async () => {
  const x = fixture();
  const load = x.select(2);
  x.definitionControl.value = JSON.stringify({ ...definition(), actions: [{ step_id: 'new-edit' }] });
  x.respond(0, 2);
  await assert.rejects(load);
  assert.equal(x.id.value, '1');
  assert.deepEqual(x.edits, []);
});

test('DC-07-A05: latest-issued context GET wins; late prior response stays inert', async () => {
  const x = fixture();
  const older = x.select(2);
  const newer = x.select(3);
  assert.equal(x.pending.length, 2);
  x.respond(1, 3);
  assert.equal((await newer).workflowId, 3);
  x.respond(0, 2);
  await assert.rejects(older);
  assert.equal(x.id.value, '3');
  assert.equal(x.edits.filter(v => v[0] === 'history').length, 1);
});
