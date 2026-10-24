import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';
import { toProject } from '../../assets/js/automations-domain-project.js';

// Exercise the actual immutable Base B1 CommandHistory, EditorState and
// ProjectState. A mock holding only workflow JSON cannot prove Undo/Redo.
const BASE_B1 = 'ff8d62acf0360025ac23482c08017d99104abe9b';
const baseDir = process.env.CB_BASE_DESIGNER_B1_REPO
  || join(process.env.HOME || '', 'Downloads/wp-core-blueprint-a2-pairing');

const definition = () => ({
  definition_version: 1, trigger: null, states: [], conditions: [], actions: [],
});
const first = definition();
const second = {
  ...definition(),
  actions: [{ step_id: 'action_r1', capability: {
    kind: 'action', provider: 'core-blueprint-work', id: 'action.run', schema_version: '1',
  }, bindings: {} }],
};

test('R1-BASE: pinned Base B1 preserves actual Undo/Redo and Selection on aborted context, resets once on commit', () => {
  assert.equal(execFileSync('git', ['-C', baseDir, 'rev-parse', 'HEAD'], { encoding: 'utf8' }).trim(), BASE_B1);
  const code = `
import assert from 'node:assert/strict';
import { SourceTextModule, createContext, runInContext } from 'node:vm';
import { readFileSync } from 'node:fs';
import { join, dirname, posix } from 'node:path';
import { TextEncoder } from 'node:util';

let input = '';
for await (const part of process.stdin) input += part;
const { baseDir, first, second } = JSON.parse(input);
const context = createContext({ TextEncoder, console });
const modules = new Map();
const moduleAt = (path) => {
  if (modules.has(path)) return modules.get(path);
  const source = readFileSync(join(baseDir, path), 'utf8');
  const module = new SourceTextModule(source, { identifier: path, context });
  modules.set(path, module);
  return module;
};
const entry = new SourceTextModule(
  "import { ProjectState } from './project-state.js';" +
  "import { EditorState } from './editor-state.js';" +
  "import { CommandHistory } from './history.js';" +
  "export { ProjectState, EditorState, CommandHistory };",
  { identifier: 'assets/js/design/core/r1-harness.js', context },
);
await entry.link((specifier, reference) => {
  if (!specifier.startsWith('./')) throw new Error('Unexpected Base dependency ' + specifier);
  return moduleAt(posix.normalize(posix.join(posix.dirname(reference.identifier), specifier)));
});
await entry.evaluate();
const { ProjectState, EditorState, CommandHistory } = entry.namespace;
// Create project records inside the SAME realm as Base's strict ProjectState.
const makeProject = (value) => runInContext('JSON.parse(' + JSON.stringify(JSON.stringify(value)) + ')', context);
const original = context.__original = makeProject(first);
const next = context.__next = makeProject(second);
const project = new ProjectState(original);
const editor = new EditorState();
const history = new CommandHistory(project, editor, { limit: 100 });
const same = (left, right) => JSON.stringify(left) === JSON.stringify(right);
const apply = (nextProject) => Object.freeze({
  label: 'r1-change',
  apply: () => ({ project: nextProject }),
});
history.execute(apply(next));
assert.equal(history.status().canUndo, true);
assert.equal(history.undo(), true);
assert.equal(history.status().canRedo, true);
// Keep an actual node selection, not just the empty default snapshot.
editor.selection.select([]);
const before = {
  project: project.snapshot(), baseline: history.baselineSnapshot(),
  status: history.status(), selection: editor.selection.snapshot(),
};
// An invalid Base project is rejected BEFORE committing or notifying.
assert.throws(() => history.replace({ broken: true }, {
  source: 'context', resetSelection: true, contextChanged: true,
}));
assert.ok(same(project.snapshot(), before.project));
assert.ok(same(history.status(), before.status));
assert.ok(same(history.baselineSnapshot(), before.baseline));
assert.ok(same(editor.selection.snapshot(), before.selection));
// Previous redo entry is still executable, i.e. the stack was not just
// replaced by an identical current definition.
assert.equal(history.redo(), true);
assert.ok(same(project.snapshot(), next));
assert.equal(history.undo(), true);
assert.ok(same(project.snapshot(), original));
// The successful replacement is Base's single irreversible commit.
// This callback models a throwing History-button presenter. Base isolates it.
history.subscribeHistory(() => { throw new Error('History button refresh failed'); });
history.replace(next, { source: 'context', contextChanged: true, resetSelection: true });
assert.ok(same(project.snapshot(), next));
assert.ok(same(history.baselineSnapshot(), next));
assert.deepEqual(JSON.parse(JSON.stringify(history.status())), {
  canUndo: false, canRedo: false, size: 0,
});
// Simulate a consumer History-button presenter failure AFTER the commit.
try { throw new Error('Button refresh failed'); } catch (_) {}
assert.ok(same(project.snapshot(), next));
assert.equal(history.status().canUndo, false);
assert.equal(history.status().canRedo, false);
assert.equal(editor.selection.snapshot().paths.length, 0, 'committed context clears selection');
`;
  execFileSync(process.execPath, [
    '--no-warnings', '--experimental-vm-modules', '--input-type=module', '--eval', code,
  ], {
    input: JSON.stringify({ baseDir, first: toProject(first), second: toProject(second) }),
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  });
});
