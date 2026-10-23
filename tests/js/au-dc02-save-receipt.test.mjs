import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { createHash, webcrypto } from 'node:crypto';
import { TextEncoder } from 'node:util';
import { runInNewContext } from 'node:vm';

const script = readFileSync(new URL('../../assets/js/admin-builder-save.js', import.meta.url), 'utf8');
const definition = JSON.stringify({ definition_version: 1, trigger: null, states: [], conditions: [], actions: [] });
const sha = createHash('sha256').update(definition).digest('hex');
const response = (options = {}) => ({
  ok: options.ok ?? true,
  status: options.status ?? 200,
  text: async () => JSON.stringify(options.body ?? {
    success: true,
    data: {
      revision: '9',
      name: 'Example',
      activation_state: 'disabled',
      persisted: { workflow_id: '17', revision: '9', submitted_sha256: sha },
      message: 'Automation saved.',
    },
  }),
});

async function simulate(serverResponse, { rebaseFails = false, initialName = 'Example', changeNameDuringSave = null } = {}) {
  const events = [];
  const notifications = [];
  const fields = {
    workflow_id: '17', revision: '8', name: initialName, activation_state: 'disabled',
    definition_json: definition,
  };
  class Element {
    constructor(value = '') { this.value = value; this.textContent = ''; this.disabled = false; this.checked = false; this.attributes = {}; this.dataset = {}; this.handlers = new Map(); this.children = []; this.hidden = false; }
    addEventListener(type, handler) { this.handlers.set(type, [...(this.handlers.get(type) || []), handler]); }
    replaceChildren(...children) { this.children = children; }
    querySelector() { return null; }
    querySelectorAll() { return []; }
    toggleAttribute(key, value) { this.attributes[key] = value; }
    dispatchEvent(event) { events.push(event.detail); for (const cb of this.handlers.get(event.type) || []) cb(event); return true; }
    append() {}
  }
  class HTMLFormElement extends Element {
    addEventListener(type, listener) { if (type === 'submit') this.submitHandler = listener; }
    getAttribute() { return 'https://example.test/wp-admin/admin-post.php'; }
  }
  class HTMLButtonElement extends Element {}
  class HTMLInputElement extends Element {}
  class HTMLSelectElement extends Element {}
  const form = new HTMLFormElement();
  const root = new Element();
  const save = new HTMLButtonElement();
  save.textContent = 'Save';
  const status = new Element();
  const recovery = new Element();
  recovery.hidden = true;
  root.addEventListener('cb:design-shell:savechange', (event) => {
    // Simulate Base's documented ONE Toast mapping, not a consumer Toast.
    const state = event.detail?.state;
    if (state === 'unknown') {
      root.dataset.cbDesignShellSaveOutcomeUnknown = 'true';
      root.dataset.cbDesignShellSaveState = 'unknown';
    }
    if (state === 'saved' || state === 'error' || state === 'unknown') {
      notifications.push({ variant: state === 'saved' ? 'success' : 'error', message: event.detail?.message });
    }
  });
  const inputs = {
    '[name="revision"]': new HTMLInputElement('8'),
    '[name="name"]': new HTMLInputElement(initialName),
    '[name="activation_state"]': new HTMLSelectElement('disabled'),
    '[data-cb-automations-definition]': new HTMLInputElement(definition),
  };
  form.querySelector = (selector) => inputs[selector] ?? null;
  form.querySelectorAll = () => [];
  root.querySelector = (selector) => ({
    '[data-cb-design-shell-primary-action]': save,
    '[data-cb-design-shell-status]': status,
    '[data-cb-automations-save-recovery]': recovery,
    '.cb-automations-validation-panel': null,
  })[selector] ?? null;
  const heading = new Element();
  let writeCount = 0;
  let rebases = 0;
  const window = {
    fetch: async () => {
      writeCount++;
      if (changeNameDuringSave !== null) inputs['[name="name"]'].value = changeNameDuringSave;
      if (serverResponse instanceof Error) throw serverResponse;
      return serverResponse;
    },
    crypto: webcrypto,
    location: { href: 'https://example.test/wp-admin/admin.php?page=core-blueprint-automations-workflows' },
    history: { replaceState() {} },
    cbAutomationsBuilderHistory: {
      captureSave: () => ({ token: 'opaque-test' }),
      snapshot: () => JSON.parse(definition),
      acknowledgeSave: () => { rebases++; if (rebaseFails) throw Error('REBASE_STALE'); },
    },
  };
  class FormData {
    constructor() { this.values = new Map(Object.entries(fields)); }
    set(key, value) { this.values.set(key, value); }
    get(key) { return this.values.get(key) ?? null; }
  }
  const document = {
    querySelector: (selector) => ({
      '[data-cb-automations-editor-form]': form,
      '[data-cb-automations-designer-shell]': root,
      '.cb-automations-editor-heading .cb-core-title': heading,
    })[selector] ?? null,
    querySelectorAll: () => Object.assign([], { item: () => null }),
    createElement: () => new Element(),
  };
  class CustomEvent { constructor(type, options = {}) { this.type = type; this.detail = options.detail; } }
  runInNewContext(script, { window, document, HTMLFormElement, HTMLButtonElement, HTMLInputElement,
    HTMLSelectElement, HTMLElement: Element, CustomEvent, FormData, TextEncoder,
    Uint8Array, URL, console });
  assert.equal(typeof form.submitHandler, 'function', 'actual Save script must register its handler');
  await form.submitHandler({ submitter: save, preventDefault() {} });
  return { events, notifications, statusText: status.textContent, recovery, root,
    writeCount, rebases, revision: inputs['[name="revision"]'].value,
    name: inputs['[name="name"]'].value, heading: heading.textContent };
}

test('AU-DC02-J01: exact persisted receipt permits one Base rebase and advances version', async () => {
  const v = await simulate(response());
  assert.equal(v.writeCount, 1);
  assert.equal(v.rebases, 1);
  assert.equal(v.revision, '9');
  assert.equal(v.events.at(-1)?.state, 'saved');
  assert.equal(v.notifications.length, 1, 'one Base-owned success Toast');
  assert.equal(v.notifications[0]?.variant, 'success');
  assert.equal(v.statusText, '', 'no transient toolbar message');
  assert.equal(v.recovery.hidden, true, 'no conflict recovery on confirmed Save');
});

test('AU-DC02-J02: no credible content/identity/revision receipt ever rebases or advances cursor', async () => {
  for (const change of [
    { persisted: null },
    { persisted: { workflow_id: '18', revision: '9', submitted_sha256: sha } },
    { persisted: { workflow_id: '17', revision: '10', submitted_sha256: sha } },
    { persisted: { workflow_id: '17', revision: '9', submitted_sha256: '0'.repeat(64) } },
    { persisted: { workflow_id: '17', revision: '9', submitted_sha256: 'broken' } },
  ]) {
    const data = { revision: '9', name: 'Example', activation_state: 'disabled',
      persisted: { workflow_id: '17', revision: '9', submitted_sha256: sha }, ...change };
    const v = await simulate(response({ body: { success: true, data } }));
    assert.equal(v.writeCount, 1, 'uncertain client must not automatically retry');
    assert.equal(v.rebases, 0);
    assert.equal(v.revision, '8');
    assert.equal(v.events.at(-1)?.state, 'unknown');
    assert.equal(v.events.at(-1)?.uncertain, true);
    assert.equal(v.root.dataset.cbDesignShellSaveOutcomeUnknown, 'true');
    assert.equal(v.notifications.length, 1, 'one Base-owned error Toast for unknown Save');
    assert.equal(v.notifications[0].variant, 'error');
    assert.equal(v.statusText, '', 'no transient toolbar error');
  }
});

test('AU-DC02-J03: post-CAS uncertain readback and ordinary conflict both preserve draft', async () => {
  for (const notice of ['save_unverified', 'conflict']) {
    const v = await simulate(response({ ok: false, status: 409,
      body: { success: false, data: { notice, message: 'No verified save' } } }));
    assert.equal(v.rebases, 0);
    assert.equal(v.revision, '8');
    assert.equal(v.events.at(-1)?.state, notice === 'save_unverified' ? 'unknown' : 'error');
    assert.equal(v.root.dataset.cbDesignShellSaveOutcomeUnknown === 'true', notice === 'save_unverified');
    assert.equal(v.notifications.length, 1, 'one Base-owned conflict/uncertain Toast');
    assert.equal(v.notifications[0].variant, 'error');
    assert.equal(v.recovery.hidden, false, 'persistent Settings recovery must remain visible');
    assert.equal(v.recovery.children[0]?.target, '_blank');
    assert.equal(v.statusText, '', 'no transient toolbar conflict message');
    v.root.dispatchEvent({ type: 'cb-automations:contextloaded' });
    assert.equal(v.recovery.hidden, true, 'switching workflow must clear the old recovery action');
  }
});

test('AU-DC02-J04: Base rebase refusal does not falsely advance browser revision', async () => {
  const v = await simulate(response(), { rebaseFails: true });
  assert.equal(v.rebases, 1);
  assert.equal(v.revision, '8');
  assert.equal(v.events.at(-1)?.state, 'unknown');
  assert.equal(v.root.dataset.cbDesignShellSaveOutcomeUnknown, 'true');
  assert.equal(v.notifications.length, 1);
  assert.equal(v.notifications[0]?.variant, 'error');
  assert.equal(v.statusText, '');
  assert.equal(v.recovery.hidden, false, 'post-commit rebase refusal requires actionable recovery');
});

test('AU-DC02-J05: lost fetch acknowledgement preserves local draft and exposes recovery without retry', async () => {
  const v = await simulate(new Error('Failed to fetch'));
  assert.equal(v.writeCount, 1);
  assert.equal(v.rebases, 0);
  assert.equal(v.revision, '8');
  assert.equal(v.name, 'Example');
  assert.equal(v.events.at(-1)?.state, 'unknown');
  assert.equal(v.root.dataset.cbDesignShellSaveOutcomeUnknown, 'true');
  assert.equal(v.notifications.length, 1);
  assert.equal(v.notifications[0]?.variant, 'error');
  assert.equal(v.recovery.hidden, false, 'unknown post-submit outcome requires Settings recovery');
  assert.equal(v.recovery.children[0]?.target, '_blank');
  assert.equal(v.statusText, '');
});

test('AU-DC02-J06: unreadable HTTP response body is an uncertain save with no automatic retry', async () => {
  const v = await simulate({
    ok: true, status: 200,
    text: async () => { throw new Error('Network interrupted while reading body'); },
  });
  assert.equal(v.writeCount, 1);
  assert.equal(v.rebases, 0);
  assert.equal(v.revision, '8');
  assert.equal(v.events.at(-1)?.state, 'unknown');
  assert.equal(v.root.dataset.cbDesignShellSaveOutcomeUnknown, 'true');
  assert.equal(v.notifications.length, 1);
  assert.equal(v.recovery.hidden, false);
});

test('AU-DC02-J07: server-normalized name becomes input, heading and saved baseline', async () => {
  const v = await simulate(response(), { initialName: ' Example ' });
  assert.equal(v.writeCount, 1);
  assert.equal(v.rebases, 1);
  assert.equal(v.revision, '9');
  assert.equal(v.name, 'Example');
  assert.equal(v.heading, 'Example');
  assert.equal(v.events.at(-1)?.baselineName, 'Example');
  assert.equal(v.events.at(-1)?.currentDirty, false);
  assert.equal(v.recovery.hidden, true);
});

test('AU-DC02-J08: newer user name edit is preserved while server-normalized baseline is authoritative', async () => {
  const v = await simulate(response(), {
    initialName: ' Example ',
    changeNameDuringSave: 'Newer local edit',
  });
  assert.equal(v.writeCount, 1);
  assert.equal(v.rebases, 1);
  assert.equal(v.revision, '9');
  assert.equal(v.name, 'Newer local edit');
  assert.equal(v.events.at(-1)?.baselineName, 'Example');
  assert.equal(v.events.at(-1)?.currentDirty, true);
  assert.match(v.events.at(-1)?.message || '', /newer edits/i);
  assert.equal(v.recovery.hidden, true);
});
