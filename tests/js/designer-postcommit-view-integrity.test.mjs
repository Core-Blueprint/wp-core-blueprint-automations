import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';

// Evaluate the ACTUAL registered consumer callbacks on Node EventTargets.
// An error inside the listener is caught by that view, acknowledged as false,
// and cannot be inferred from dispatchEvent() throwing.
const read = (file) => readFileSync(new URL('../../' + file, import.meta.url), 'utf8');
const inspector = read('assets/js/admin-builder-inspector.js');
const layers = read('assets/js/admin-builder-layers.js');
const shellSource = read('assets/js/admin-designer-shell.js');

class DetailEvent extends Event {
  constructor(type, options = {}) {
    super(type, { bubbles: options.bubbles === true });
    this.detail = options.detail;
  }
}
const listener = (source, start, end) => {
  const offset = source.indexOf(start);
  assert.ok(offset >= 0, 'production listener must exist');
  const finish = source.indexOf(end, offset);
  assert.ok(finish > offset, 'production listener must be syntactically bounded');
  return source.slice(offset, finish + end.length);
};

const inspectorListener = listener(
  inspector, "\tshell.addEventListener('cb-automations:contextloaded', (event) => {",
  "\n\t});",
);
const layersListener = listener(
  layers, "\tshell.addEventListener('cb-automations:contextloaded', (event) => {",
  "\n\t}, { signal });",
);
const stagesListener = listener(
  shellSource, "\t\troot.addEventListener('cb-automations:contextloaded', (event) => {",
  "\n\t\t});",
);
const dispatch = (target, expectedView) => {
  const confirmations = [];
  const result = target.dispatchEvent(new DetailEvent('cb-automations:contextloaded', {
    detail: { acknowledgeView: (view, ok) => confirmations.push({ view, ok }) },
  }));
  assert.equal(result, true, 'dispatchEvent does not report a view outcome');
  assert.deepEqual(confirmations, [{ view: expectedView, ok: false }]);
};

test('R1-VIEW-01: real Inspector listener reports render failure synchronously', () => {
  const shell = new EventTarget();
  const root = { querySelectorAll: () => [] };
  const renderInspector = () => { throw new Error('Inspector presentation failed'); };
  new Function('shell', 'root', 'renderInspector', 'CustomEvent',
    'let selected = "old";\n' + inspectorListener,
  )(shell, root, renderInspector, DetailEvent);
  dispatch(shell, 'inspector');
});

test('R1-VIEW-02: real Layers listener reports Base tree render failure', () => {
  const shell = new EventTarget();
  const controller = new AbortController();
  const tree = { reset() {} };
  const render = () => { throw new Error('Layers render failed'); };
  new Function('shell', 'signal', 'tree', 'render',
    'let selectedKey = "old";\n' + layersListener,
  )(shell, controller.signal, tree, render);
  dispatch(shell, 'layers');
});

test('R1-VIEW-03: real workflow Stages listener reports navigation failure', () => {
  const root = new EventTarget();
  const canvas = { scrollTop: 5 };
  const navigation = { setActive() { throw new Error('Stage navigation failed'); } };
  new Function('root', 'canvas', 'navigation',
    'let activeStage = "actions";\n' + stagesListener,
  )(root, canvas, navigation);
  dispatch(root, 'stages');
});

test('R1-VIEW-04: all three critical consumers explicitly acknowledge a successful render', () => {
  const inspectors = new EventTarget();
  const layerTree = new EventTarget();
  const stages = new EventTarget();
  new Function('shell', 'root', 'renderInspector', 'CustomEvent',
    'let selected = "old";\n' + inspectorListener,
  )(inspectors, { querySelectorAll: () => [] }, () => {}, DetailEvent);
  new Function('shell', 'signal', 'tree', 'render',
    'let selectedKey = "old";\n' + layersListener,
  )(layerTree, new AbortController().signal, { reset() {} }, () => {});
  new Function('root', 'canvas', 'navigation',
    'let activeStage = "actions";\n' + stagesListener,
  )(stages, { scrollTop: 0 }, { setActive() {} });
  for (const [target, view] of [[inspectors, 'inspector'], [layerTree, 'layers'], [stages, 'stages']]) {
    const confirmed = [];
    target.dispatchEvent(new DetailEvent('cb-automations:contextloaded', {
      detail: { acknowledgeView: (key, ok) => confirmed.push({ key, ok }) },
    }));
    assert.deepEqual(confirmed, [{ key: view, ok: true }]);
  }
});
