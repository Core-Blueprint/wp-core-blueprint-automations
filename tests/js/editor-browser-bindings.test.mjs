import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { toProject, fromProject, workflowCommands } from '../../assets/js/automations-domain-project.js';

// The fixture is actual PHP DefinitionCodec -> privacy projection -> browser
// JSON, not a hand-authored JS object that accidentally skips [] vs {}.
test('P0-B01: real PHP editor JSON with empty bindings initializes public domain project', () => {
  const output = execFileSync('php', [
    fileURLToPath(new URL('../unit/editor-browser-bindings.php', import.meta.url)),
  ], {
    env: { ...process.env, CB_AUTOMATIONS_BROWSER_BINDINGS_FIXTURE: '1' },
    encoding: 'utf8',
  });
  const browser = JSON.parse(output.trim().split(/\r?\n/).at(-1));
  assert.equal(Array.isArray(browser.trigger.bindings), false);
  assert.equal(Array.isArray(browser.states[0].bindings), false);
  assert.deepEqual(browser.trigger.bindings, {});
  assert.deepEqual(browser.states[0].bindings, {});
  assert.deepEqual(browser.actions[0].bindings.items.value, []);
  assert.equal(browser.actions[0].bindings.label.value, 'retain-me');

  const project = toProject(browser);
  assert.deepEqual(fromProject(project), browser);

  const changed = JSON.parse(JSON.stringify(browser));
  changed.actions[0].bindings.label.value = 'updated';
  const command = workflowCommands.editorChange({ expected: browser, next: changed });
  assert.deepEqual(fromProject(command.apply(project).project), changed);
  assert.deepEqual(fromProject(project), browser);
});

test('P0-B02: malformed nonempty lists remain fail-closed, with no coercion of private fields', () => {
  const safe = {
    definition_version: 1,
    trigger: null,
    states: [],
    conditions: [],
    actions: [{
      step_id: 'action_1',
      capability: { kind: 'action', provider: 'core-blueprint-work', id: 'fixture.action', schema_version: '1' },
      bindings: {},
    }],
  };
  assert.throws(() => toProject({
    ...safe,
    actions: [{ ...safe.actions[0], bindings: [{ source: 'literal', value: 'do-not-coerce' }] }],
  }), /bindings must be an object/i);
  assert.throws(() => toProject({
    ...safe,
    actions: [{ ...safe.actions[0], bindings: { secret: { source: 'literal', value: null } } }],
  }), /literal/i);
  assert.deepEqual(fromProject(toProject(safe)), safe);
});
