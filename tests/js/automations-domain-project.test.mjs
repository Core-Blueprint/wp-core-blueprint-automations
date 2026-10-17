import assert from 'node:assert/strict';
import test from 'node:test';
import { toProject, fromProject, workflowCommands } from '../../assets/js/automations-domain-project.js';

const capability = (kind) => ({ kind, provider: 'core-blueprint-work', id: `${kind}.lookup`, schema_version: '1' });
const step = (kind, id, bindings = {}) => ({ step_id: id, capability: capability(kind), bindings });
const literal = (value) => ({ source: 'literal', value });
const output = (id, field = 'value') => ({ source: 'step_output', step_id: id, field });
const condition = (id) => ({ condition_id: id, left: output('state_1'), operator: 'equals', right: literal('ready') });
const empty = () => ({ definition_version: 1, trigger: null, states: [], conditions: [], actions: [] });
const populated = () => ({
  definition_version: 1,
  trigger: step('trigger', 'trigger_1'),
  states: [step('state', 'state_1', { owner: literal('team'), count: literal(42) })],
  conditions: [condition('condition_1')],
  actions: [step('action', 'action_1', { project_id: output('state_1') })],
});
const run = (project, command) => command.apply(project).project;

// Intentionally consumer-owned test harness, NOT a second history engine.
// History/save behaviour is verified against the real Base session at a later gate.
test('AU1-A01: nested G1a wrapper roundtrips canonical empty and populated definitions', () => {
  for (const definition of [empty(), populated()]) {
    const project = toProject(definition);
    assert.deepEqual(Object.keys(project).sort(), ['design_type', 'root', 'schema_version']);
    assert.equal(project.schema_version, 1);
    assert.equal(project.design_type, 'automations.workflow');
    assert.deepEqual(project.root.children, []);
    assert.equal(project.root.type, 'automations.workflow');
    assert.equal(project.root.provider, 'core-blueprint-automations');
    assert.deepEqual(fromProject(project), definition);
    assert.ok(Object.isFrozen(project.root.properties.definition));
    assert.notStrictEqual(fromProject(project), definition);
  }
});

test('AU1-A02: detached project values never mutate the original source or project', () => {
  const definition = populated();
  const project = toProject(definition);
  definition.states[0].step_id = 'state_external';
  const read = fromProject(project);
  read.states[0].step_id = 'state_local';
  assert.equal(fromProject(project).states[0].step_id, 'state_1');
  assert.equal(Object.isFrozen(project), true);
});

test('AU1-A03: deterministic add/move/remove uses supplied IDs without generating new IDs', () => {
  const start = toProject(populated());
  const newAction = step('action', 'action_2', { payload: output('state_1') });
  const add = workflowCommands.insert({ collection: 'actions', index: 1, entry: newAction });
  const withAction = run(start, add);
  const withActionAgain = run(start, add);
  assert.deepEqual(fromProject(withAction), fromProject(withActionAgain));
  assert.deepEqual(fromProject(withAction).actions.map(x => x.step_id), ['action_1', 'action_2']);
  const move = workflowCommands.move({ collection: 'actions', id: 'action_2', fromIndex: 1, toIndex: 0 });
  const moved = run(withAction, move);
  assert.deepEqual(fromProject(moved).actions.map(x => x.step_id), ['action_2', 'action_1']);
  const remove = workflowCommands.remove({ collection: 'actions', id: 'action_2', expected: newAction });
  assert.deepEqual(fromProject(run(moved, remove)), populated());
  newAction.step_id = 'action_mutated';
  assert.deepEqual(fromProject(run(start, add)).actions.map(x => x.step_id), ['action_1', 'action_2']);
});

test('AU1-A04: distinct intents and stable identifiers across trigger, condition and bindings', () => {
  const start = toProject(populated());
  const originalState = fromProject(start).states[0];
  const editedState = { ...originalState, bindings: { ...originalState.bindings, owner: literal('operations') } };
  const changeBinding = workflowCommands.replace({ collection: 'states', id: 'state_1', expected: originalState, entry: editedState });
  const conditionBefore = fromProject(start).conditions[0];
  const conditionAfter = { ...conditionBefore, right: literal('approved') };
  const changeCondition = workflowCommands.replace({ collection: 'conditions', id: 'condition_1', expected: conditionBefore, entry: conditionAfter });
  const afterBinding = run(start, changeBinding);
  const afterCondition = run(afterBinding, changeCondition);
  assert.deepEqual(fromProject(afterBinding).states[0].bindings.owner, literal('operations'));
  assert.deepEqual(fromProject(afterBinding).conditions[0], conditionBefore);
  assert.deepEqual(fromProject(afterCondition).conditions[0], conditionAfter);
  assert.equal(fromProject(afterCondition).conditions[0].condition_id, 'condition_1');
  assert.equal(fromProject(afterCondition).actions[0].step_id, 'action_1');
  assert.equal(fromProject(afterCondition).states[0].step_id, 'state_1');
  const triggerBefore = fromProject(start).trigger;
  const triggerAfter = { ...triggerBefore, bindings: { visible: literal(true) } };
  const updateTrigger = workflowCommands.setTrigger({ expected: triggerBefore, entry: triggerAfter });
  assert.equal(fromProject(run(start, updateTrigger)).trigger.step_id, 'trigger_1');
  assert.notStrictEqual(changeBinding, changeCondition);
});

test('AU1-A05: replay refuses conflicting server normalization without mutating either snapshot', () => {
  const start = toProject(populated());
  const old = fromProject(start).states[0];
  const revised = { ...old, bindings: { owner: literal('ops'), count: literal(42) } };
  const command = workflowCommands.replace({ collection: 'states', id: 'state_1', expected: old, entry: revised });
  const accepted = toProject({ ...populated(), states: [step('state', 'state_1', { owner: literal('server-changed') })] });
  assert.throws(() => run(accepted, command), /conflict/i);
  assert.deepEqual(fromProject(start), populated());
  assert.deepEqual(fromProject(accepted).states[0].bindings.owner, literal('server-changed'));
  assert.equal(fromProject(run(start, command)).states[0].bindings.owner.value, 'ops');
});

test('AU1-A06: invalid targets and IDs fail closed, without implicit upsert', () => {
  const project = toProject(populated());
  assert.throws(() => run(project, workflowCommands.insert({ collection: 'actions', index: 0, entry: step('action', 'state_1') })), /conflict/i);
  assert.throws(() => run(project, workflowCommands.move({ collection: 'actions', id: 'action_1', fromIndex: 4, toIndex: 0 })), /conflict/i);
  assert.throws(() => run(project, workflowCommands.remove({ collection: 'actions', id: 'action_99', expected: step('action', 'action_99') })), /conflict/i);
  assert.throws(() => workflowCommands.replace({ collection: 'conditions', id: 'condition_1', expected: condition('condition_1'), entry: condition('condition_2') }), /identifier/i);
  assert.throws(() => workflowCommands.insert({ collection: 'states', index: -1, entry: step('state', 'state_2') }), /index/i);
  assert.deepEqual(fromProject(project), populated());
});

test('AU1-A07: codec-style schema and identifiers are validated; unsupported data fails closed', () => {
  const malformed = { ...empty(), states: [{...step('state', 'state_1'), future: true }] };
  assert.throws(() => toProject(malformed), /fields/i);
  assert.throws(() => toProject({ ...populated(), actions: [step('action', 'state_1')] }), /duplicate/i);
  assert.throws(() => toProject({ ...empty(), conditions: [condition('condition_1'), condition('condition_1')] }), /duplicate/i);
  assert.throws(() => toProject({ ...empty(), trigger: step('action', 'action_1') }), /kind/i);
  assert.throws(() => toProject({ ...empty(), actions: [step('action', 'WRONG')] }), /identifier/i);
  assert.throws(() => toProject({ ...empty(), actions: [step('action', 'action_1', { key: literal(null) })] }), /literal/i);
  const unsupported = empty(); unsupported.extra = 'not canonical';
  assert.throws(() => toProject(unsupported), /fields/i);
  const prototype = JSON.parse('{"definition_version":1,"trigger":null,"states":[],"conditions":[],"actions":[],"__proto__":{}}');
  assert.throws(() => toProject(prototype), /unsafe|fields/i);
});

test('AU1-A08: unsafe integers, non-finite numbers, circular data and oversize payload rejected', () => {
  const bad = (value) => ({ ...empty(), states: [step('state', 'state_1', { input: literal(value) })] });
  for (const value of [Number.MAX_SAFE_INTEGER + 1, -Number.MAX_SAFE_INTEGER - 1, Number.POSITIVE_INFINITY, Number.NaN]) {
    assert.throws(() => toProject(bad(value)), /number|integer/i);
  }
  assert.equal(fromProject(toProject(bad(Number.MAX_SAFE_INTEGER))).states[0].bindings.input.value, Number.MAX_SAFE_INTEGER);
  assert.throws(() => toProject(bad([Number.MAX_SAFE_INTEGER + 1])), /integer/i);
  const cycle = empty(); cycle.states.push(cycle);
  assert.throws(() => toProject(cycle), /cyclic|cycle/i);
  assert.throws(() => toProject(bad('x'.repeat(270000))), /size|bytes/i);
});

test('AU1-A09: rejects alternate wrappers, dirty JavaScript values and unauthorized metadata', () => {
  const project = toProject(populated());
  assert.throws(() => fromProject({ ...project, root: { ...project.root, children: [step('state', 'x')] } }), /wrapper|children/i);
  assert.throws(() => fromProject({ ...project, root: { ...project.root, properties: { definition: empty(), principal: 7 } } }), /fields/i);
  assert.throws(() => toProject({ ...empty(), execution_principal_user_id: 33 }), /fields/i);
  assert.throws(() => fromProject({ ...project, design_type: 'mail-template' }), /wrapper|design_type/i);
});
