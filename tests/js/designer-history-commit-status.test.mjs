import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { toProject, fromProject } from '../../assets/js/automations-domain-project.js';

// Run the real consumer adapter function, not a copied implementation.
const source = readFileSync(new URL('../../assets/js/admin-builder-history.js', import.meta.url), 'utf8');
const match = source.match(/const contextWitness = \(\) => \(\{[\s\S]*?\n  \}\);[\s\S]*?const resetForContext = \(definition\) => \{[\s\S]*?\n  \};/);
assert.ok(match, 'History commit status function must remain separately testable');
const clone = (v) => JSON.parse(JSON.stringify(v));
const definition = () => ({ definition_version: 1, trigger: null, states: [], conditions: [], actions: [] });
const withAction = () => ({
  ...definition(),
  actions: [{ step_id: 'action_r1', capability: {
    kind: 'action', provider: 'core-blueprint-work', id: 'action.run', schema_version: '1',
  }, bindings: {} }],
});

const fixture = ({ replaceFault = '', uiFault = false, corruptWitness = false } = {}) => {
  let current = toProject(definition());
  let baseline = toProject(definition());
  let status = { canUndo: true, canRedo: false, size: 1 };
  const next = withAction();
  const calls = [];
  const session = {
    snapshot: () => clone(current),
    baselineSnapshot: () => clone(baseline),
    historyStatus: () => clone(status),
    replace: (project, options) => {
      calls.push(['replace', options]);
      if (replaceFault === 'refused') throw new Error('Base stage rejected');
      current = clone(project);
      baseline = clone(project);
      status = { canUndo: false, canRedo: false, size: 0 };
      if (replaceFault === 'ambiguous') throw new Error('Unexpected postcommit failure');
      if (corruptWitness) status = { canUndo: true, canRedo: false, size: 1 };
    },
  };
  const api = { snapshot: () => clone(next) };
  const prepared = (value) => fromProject(toProject(clone(value)));
  const funcs = runInNewContext(match[0] + '\n({ resetForContext, contextWitness });', {
    session, api, prepareContext: prepared, toProject, fromProject,
    same: (a, b) => JSON.stringify(a) === JSON.stringify(b),
    syncButtons: () => { calls.push(['buttons']); if (uiFault) throw new Error('Buttons failed'); },
  });
  return { ...funcs, next, calls,
    get current() { return fromProject(current); },
    get baseline() { return fromProject(baseline); },
    get status() { return clone(status); },
  };
};

test('R1-H01: Base commit refusal returns refused without mutating History stacks', () => {
  const x = fixture({ replaceFault: 'refused' });
  const witness = x.contextWitness();
  const result = x.resetForContext(x.next);
  assert.equal(result.status, 'refused');
  assert.match(result.error.message, /stage rejected/);
  assert.deepEqual(x.contextWitness(), witness);
  assert.equal(x.status.canUndo, true);
});

test('R1-H02: a presentation exception after committed Base replace never triggers rollback', () => {
  const x = fixture({ uiFault: true });
  const result = x.resetForContext(x.next);
  assert.equal(result.status, 'committed');
  assert.deepEqual(x.current, x.next);
  assert.deepEqual(x.baseline, x.next);
  assert.deepEqual(x.status, { canUndo: false, canRedo: false, size: 0 });
  assert.equal(x.calls.filter(([name]) => name === 'replace').length, 1);
});

test('R1-H03: exception with changed History witness is UNKNOWN, never refused', () => {
  const x = fixture({ replaceFault: 'ambiguous' });
  const result = x.resetForContext(x.next);
  assert.equal(result.status, 'unknown');
  assert.deepEqual(x.current, x.next);
  assert.equal(x.status.canUndo, false);
});

test('R1-H04: inconsistent postcommit status becomes UNKNOWN rather than success', () => {
  const x = fixture({ corruptWitness: true });
  const result = x.resetForContext(x.next);
  assert.equal(result.status, 'unknown');
  assert.deepEqual(x.current, x.next);
});
