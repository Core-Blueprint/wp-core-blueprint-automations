import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';

const read = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const standard = read('assets/js/admin-designer-shell.js');
const history = read('assets/js/admin-builder-history.js');
const advanced = read('assets/js/admin-advanced-designer-shell.js');
const graph = read('assets/js/admin-advanced-editor.js');
const assets = read('src/Admin/DesignerAssets.php');

test('DCMP-03A: both Designers require the Base Exit Guard v3 contract', () => {
  assert.match(assets, /DESIGNER_EXIT_GUARD_API' \) >= 3/);
  assert.match(standard, /exitGuard\.register\(root, \{/);
  assert.match(standard, /discard: discardWorkflow/);
  assert.match(advanced, /guard\.register\(root, \{/);
  assert.match(advanced, /discard: \(\) => \{/);
  assert.doesNotMatch(advanced, /\bsave: async\s*\(\)/,
    'Native WordPress POST cannot authorize unverified Save & Close');
});

test('DCMP-03A: Standard Discard delegates to one canonical Base History transaction', () => {
  assert.match(history, /session\.discardToBaseline\(\)/);
  assert.match(history, /fromProject\(session\.baselineSnapshot\(\)\)/);
  assert.match(history, /selection\.primary === null/);
  assert.match(history, /selection\.refs\.length === 0/);
  assert.match(history, /\['layers', 'inspector', 'canvas'\]\.every\(/);
  assert.match(history, /discardToBaseline,/);
  assert.match(standard, /history\.discardToBaseline\(\) !== true/);
  assert.match(standard, /revision\.value === serverRevision/);
  assert.match(standard, /normalized\(definition\.value\) === baseline\.definition/);
  assert.match(standard, /name\.value === baseline\.name/);
  assert.match(standard, /activation\.value === baseline\.activation/);
  assert.match(standard, /Boolean\(authority\?\.checked\) === baseline\.rebind/);
  assert.match(standard, /fenceUncertainContext\(\)/);
});

test('DCMP-03A: Advanced Discard restores the graph on its exact Base event source', () => {
  assert.match(advanced, /event\?\.source === 'history' \|\| event\?\.source === 'discard'/);
  assert.match(advanced, /graph\.restoreHistory\(project\.root, \{ discard: event\.source === 'discard' \}\)/);
  assert.match(advanced, /historySession\.discardToBaseline\(\) !== true/);
  assert.match(advanced, /graph\.isDiscardRestored\(baseline\)/);
  assert.match(advanced, /history\.size !== 0/);
  assert.match(advanced, /revision\.value !== serverRevision/);
  assert.match(advanced, /cbDesignShellSaveOutcomeUnknown = 'true'/);
  assert.match(graph, /if \(discard\) gestureEpoch\+\+/);
  assert.match(graph, /current = discard \? null/);
  assert.match(graph, /selectedEdge = discard \? null/);
  assert.match(graph, /insertionPoint = null/);
  assert.match(graph, /pendingPort = null; draggingPort = null/);
  assert.match(graph, /previewEpoch\+\+; \/\/ Any in-flight simulation becomes stale/);
  assert.match(graph, /if \(discard && previewFixture\) previewFixture\.value = savedPreviewFixture/);
  assert.match(graph, /if \(dragEpoch !== gestureEpoch\) return/);
});

test('DCMP-03A: native Advanced Save never manufactures a clean receipt', () => {
  const submit = graph.slice(graph.indexOf("form.addEventListener('submit', event => {"));
  assert.ok(submit.length > 0);
  assert.match(submit, /saving = true/);
  assert.match(submit, /root\.dataset\.cbDesignShellSaveState = 'saving'/);
  assert.doesNotMatch(submit, /root\.dataset\.cbAdvancedDirty = 'false'/);
  assert.match(graph, /window\.addEventListener\('beforeunload'/,
    'Existing fallback must remain until joint Base FIELD is accepted');
  assert.match(advanced, /isUncertain: \(\) =>/);
});
