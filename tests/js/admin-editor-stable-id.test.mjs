import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync(new URL('../../assets/js/admin-editor.js', import.meta.url), 'utf8');
const allocator = script.match(/function freshId\(prefix\) \{[\s\S]*?\n\t\}\n\n\tfunction nextStepId\(prefix\) \{[\s\S]*?\n\t\}\n\n\tfunction nextConditionId\(\) \{[\s\S]*?\n\t\}/);
const trigger = script.match(/function renderTrigger\(\) \{[\s\S]*?\n\t\}(?=\n\n\tfunction motionRootFor)/);
assert.ok(allocator, 'production allocator must remain covered');
assert.ok(trigger, 'production trigger editor must remain covered');

const ref = (kind, id = 'trigger.old') => ({
  kind, provider: 'core-blueprint-work', id, schema_version: '1',
});
function harness(initialTrigger, { secureRandom = true } = {}) {
  const state = { trigger: initialTrigger, states: [], actions: [], conditions: [] };
  let onChange = null;
  let nonce = 0;
  const root = { replaceChildren() {}, append() {} };
  const crypto = secureRandom ? {
    getRandomValues(bytes) { nonce += 1; bytes.fill(nonce); return bytes; },
  } : undefined;
  const controls = runInNewContext(allocator[0] + '\n' + trigger[0] + '\n({ nextStepId, nextConditionId, renderTrigger })', {
    state, window: { crypto }, Uint8Array,
    triggerRoot: root,
    capabilitySelect: (kind, current, handler) => { onChange = handler; return {}; },
    selectedReference: (key) => key === 'different' ? ref('trigger', 'trigger.different') : null,
    el: () => ({ append() {} }),
    capabilityFor: () => null,
    renderAll: () => {},
  });
  return { state, controls, choose(key) { controls.renderTrigger(); onChange(key); } };
}

test('A1-B-E01: changing an imported trigger capability preserves its stable ID', () => {
  const h = harness({ step_id: 'external_webhook', capability: ref('trigger'), bindings: {} });
  h.choose('different');
  assert.equal(h.state.trigger.step_id, 'external_webhook');
  assert.equal(h.state.trigger.capability.id, 'trigger.different');
});

test('A1-B-E02: explicit delete and readd issues a fresh, nonsequential ID', () => {
  const h = harness({ step_id: 'legacy_trigger_9', capability: ref('trigger'), bindings: {} });
  h.choose('');
  assert.equal(h.state.trigger, null);
  h.choose('different');
  assert.match(h.state.trigger.step_id, /^trigger_[0-9a-f]{32}$/);
  assert.notEqual(h.state.trigger.step_id, 'legacy_trigger_9');
  assert.equal(h.state.trigger.capability.id, 'trigger.different');
});

test('A1-B-E03: newly allocated action, state and condition IDs differ across authoring intents', () => {
  const h = harness(null);
  const ids = [h.controls.nextStepId('state'), h.controls.nextStepId('action'), h.controls.nextConditionId()];
  assert.equal(new Set(ids).size, ids.length);
  assert.match(ids[0], /^state_[0-9a-f]{32}$/);
  assert.match(ids[1], /^action_[0-9a-f]{32}$/);
  assert.match(ids[2], /^condition_[0-9a-f]{32}$/);
});

test('A1-B-E04: missing secure entropy fails closed instead of allocating a reusable ID', () => {
  const h = harness(null, { secureRandom: false });
  assert.throws(() => h.controls.nextStepId('action'), /secure workflow identifier/i);
  assert.throws(() => h.controls.nextConditionId(), /secure workflow identifier/i);
});
