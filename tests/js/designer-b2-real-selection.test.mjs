import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';

// This witness loads REAL Base B2 modules, not reimplementations, from an
// isolated exact-SHA checkout. Existing Base B1 tests remain independently pinned.
const PINNED_B2 = '9ce68f14c12ceac88d20aa692be8a5bb064c56aa';
const dir = process.env.CB_BASE_DESIGNER_B2_REPO
  || join(process.env.HOME || '', 'Downloads/wp-core-blueprint-b2-pairing');

test('A-B2-T01/T02/T03/T05/T06/T07/T08: real pinned Base B2 Selection and final context flush', () => {
  assert.equal(execFileSync('git', ['-C', dir, 'rev-parse', 'HEAD'], { encoding: 'utf8' }).trim(), PINNED_B2);
  const runner = `
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join, posix } from 'node:path';
import { SourceTextModule, createContext, runInContext } from 'node:vm';
import { TextEncoder, TextDecoder } from 'node:util';

const base = JSON.parse(process.argv[1]);
const vmContext = createContext({ console, TextEncoder, TextDecoder });
const modules = new Map();
const load = (path) => {
  const resolved = path === '@cb-core/design-motion'
    ? 'assets/js/design/core/motion.js' : path;
  if (modules.has(resolved)) return modules.get(resolved);
  const module = new SourceTextModule(
    readFileSync(join(base, resolved), 'utf8'), { identifier: resolved, context: vmContext },
  );
  modules.set(resolved, module);
  return module;
};
const entry = load('assets/js/design/editor.js');
await entry.link((specifier, importing) => {
  if (specifier === '@cb-core/design-motion') return load(specifier);
  if (!specifier.startsWith('./') && !specifier.startsWith('../')) {
    throw new Error('Unexpected public Base dependency: ' + specifier);
  }
  return load(posix.normalize(posix.join(posix.dirname(importing.identifier), specifier)));
});
await entry.evaluate();
const { createDomainSession, createDesignerSelectionController } = entry.namespace;
assert.equal(typeof createDomainSession, 'function');
assert.equal(typeof createDesignerSelectionController, 'function');

const definition = (ids) => ({
  definition_version: 1, trigger: null, states: [], conditions: [],
  actions: ids.map((id) => ({
    step_id: id,
    capability: { kind: 'action', provider: 'core-blueprint-work',
      id: 'action.run', schema_version: '1' },
    bindings: {},
  })),
});
const project = (ids) => {
  const value = {
    schema_version: 0, design_type: 'automations.workflow',
    root: { type: 'automations.workflow', provider: 'core-blueprint-automations',
      properties: { definition: definition(ids) }, children: [] },
  };
  // The public Base schema validates plain objects in its own module realm.
  return runInContext('JSON.parse(' + JSON.stringify(JSON.stringify(value)) + ')', vmContext);
};
const ref = (id) => Object.freeze({ kind: 'entity', type: 'automations.action', id });
const resolver = runInContext(`(ref, current) => ref.kind === 'entity'
 && ref.type === 'automations.action'
 && current.root.properties.definition.actions.filter(item => item.step_id === ref.id).length === 1`, vmContext);
const session = createDomainSession({
  project: project(['shared']),
  resolveSelectionEntity: resolver,
  validateProject: () => {},
});
const first = session.selection().contextEpoch;
assert.equal(session.select([ref('shared')], ref('shared'), { expectedEpoch: first }), true);
const selected = session.selection();
const baseline = session.baselineSnapshot();
const history = session.historyStatus();
const invalids = [
  { kind: 'entity', type: 'automations.action', id: 'missing' },
  { kind: 'entity', type: 'automations.action', id: 'shared', extra: 1 },
  Object.assign(Object.create({ id: 'shared' }), { kind: 'entity', type: 'automations.action' }),
];
const hidden = {kind:'entity',type:'automations.action',id:'shared'};
Object.defineProperty(hidden, 'hidden', {value:1});
invalids.push(hidden);
const sym = {kind:'entity',type:'automations.action',id:'shared'};
sym[Symbol('no')] = true;
invalids.push(sym);
for (const candidate of invalids) {
  assert.throws(() => session.select([candidate], candidate, {expectedEpoch:first}),
    err => err.code === 'SELECTION_INVALID');
  assert.deepEqual(JSON.parse(JSON.stringify(session.selection())), JSON.parse(JSON.stringify(selected)));
  assert.deepEqual(JSON.parse(JSON.stringify(session.historyStatus())), JSON.parse(JSON.stringify(history)));
}
assert.throws(() => session.select([ref('shared'), ref('shared')], ref('shared'),
  {expectedEpoch:first}), err => err.code === 'SELECTION_INVALID');

// A consumer should never treat B2's early equal-project Selection callback
// as final. Wrap the real public controller adapters with an epoch hold.
const visible = [];
const order = [];
let acceptedEpoch = first;
let pending = null;
const adapter = name => ctx => {
  if (ctx.selection.contextEpoch !== acceptedEpoch) {
    pending = ctx.selection.contextEpoch; return;
  }
  visible.push(name + ':' + ctx.selection.contextEpoch);
};
const controller = createDesignerSelectionController({
  session, typed: 'domain',
  renderLayers: adapter('layers'),
  renderInspector: adapter('inspector'),
  syncCanvas: adapter('canvas'),
});
const off = session.subscribeHistory(() => {
  order.push('final-history');
  if (session.selection().contextEpoch === acceptedEpoch) return;
  acceptedEpoch = session.selection().contextEpoch;
  pending = null;
  controller.refresh();
});
visible.length = 0;
// SAME DesignProject, DIFFERENT workflow identity: Base is domain-neutral,
// so the consumer's workflow-ID is intentionally outside project serialization.
session.replace(project(['shared']), {source:'context',contextChanged:true,resetSelection:true});
assert.equal(session.selection().contextEpoch, first + 1);
assert.equal(session.selection().refs.length, 0);
assert.deepEqual(visible, ['layers:' + (first+1),'inspector:' + (first+1),'canvas:' + (first+1)]);
assert.equal(pending,null);
assert.equal(order.length,1);
assert.throws(() => session.select([ref('shared')], ref('shared'),
  {expectedEpoch:first}),err => err.code === 'SELECTION_INVALID');
assert.deepEqual(JSON.parse(JSON.stringify(session.baselineSnapshot())),
  JSON.parse(JSON.stringify(baseline)));
assert.equal(session.historyStatus().canUndo,false);

// Subsequent ordinary select renders once and never creates a History entry.
visible.length = 0;
const afterEpoch = session.selection().contextEpoch;
assert.equal(session.select([ref('shared')], ref('shared'),{expectedEpoch:afterEpoch}),true);
assert.deepEqual(visible,['layers:'+afterEpoch,'inspector:'+afterEpoch,'canvas:'+afterEpoch]);
assert.equal(session.historyStatus().canUndo,false);
// A precommit failure leaves selection and epoch intact.
const before = session.selection();
assert.throws(() => session.replace({schema_version:99},{contextChanged:true}));
assert.deepEqual(JSON.parse(JSON.stringify(session.selection())),
  JSON.parse(JSON.stringify(before)));

off();
controller.destroy();
session.dispose();
`;
  execFileSync(process.execPath, [
    '--no-warnings', '--experimental-vm-modules', '--input-type=module',
    '--eval', runner, JSON.stringify(dir),
  ], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
});
