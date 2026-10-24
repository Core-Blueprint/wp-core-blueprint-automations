import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';

// A2-R1's three synchronous ACKs remain mandatory after B2 takes Selection
// authority. Inspector and Layers must ACK the ALREADY committed B2 views,
// never create a second contextloaded render or trust dispatchEvent return.
const read = (path) => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const inspector = read('assets/js/admin-builder-inspector.js');
const layers = read('assets/js/admin-builder-layers.js');
const shell = read('assets/js/admin-designer-shell.js');
class DetailEvent extends Event {
  constructor(type, options = {}) {
    super(type, { bubbles: options.bubbles === true });
    this.detail = options.detail;
  }
}
const listener = (source, start, end) => {
  const offset = source.indexOf(start);
  assert.ok(offset >= 0, 'real postcommit listener must exist');
  const last = source.indexOf(end, offset);
  assert.ok(last > offset);
  return source.slice(offset, last + end.length);
};
const inspectorListener = listener(inspector,
  "shell.addEventListener('cb-automations:contextloaded', (event) => {", '\n  });');
const layersListener = listener(layers,
  "shell.addEventListener('cb-automations:contextloaded', (event) => {", '\n  }, { signal });');
const stagesListener = listener(shell,
  "\t\troot.addEventListener('cb-automations:contextloaded', (event) => {", '\n\t\t});');

const dispatch = (target, view) => {
  const confirmations = [];
  assert.equal(target.dispatchEvent(new DetailEvent('cb-automations:contextloaded', {
    detail: { acknowledgeView: (name, ok) => confirmations.push([name, ok]) },
  })), true);
  assert.deepEqual(confirmations, [[view, target.expected]]);
};

test('R1-VIEW-01: Inspector ACK denies incomplete B2 Inspector/Canvas render', () => {
  for (const valid of [false, true]) {
    const element = new EventTarget();
    element.expected = valid;
    const selection = { verified: (view) => valid || view === 'inspector' };
    new Function('shell', 'selection', inspectorListener)(element, selection);
    dispatch(element, 'inspector');
  }
});

test('R1-VIEW-02: Layers ACK verifies the canonical B2 view instead of rendering twice', () => {
  for (const valid of [false, true]) {
    const element = new EventTarget();
    element.expected = valid;
    const selection = { verified: () => valid };
    new Function('shell', 'selection', 'signal', layersListener)(
      element, selection, new AbortController().signal);
    dispatch(element, 'layers');
  }
});

test('R1-VIEW-03: Stages still acknowledges actual navigation errors', () => {
  const root = new EventTarget();
  root.expected = false;
  const canvas = { scrollTop: 1 };
  const navigation = { setActive() { throw new Error('Stage failure'); } };
  new Function('root', 'canvas', 'navigation',
    'let activeStage = "action";\n' + stagesListener)(root, canvas, navigation);
  dispatch(root, 'stages');
});

test('R1-VIEW-04: Inspector Layers and Stages ACK exactly once on successful postcommit', () => {
  const inspect = new EventTarget(), layer = new EventTarget(), stage = new EventTarget();
  const selection = { verified: () => true };
  inspect.expected = layer.expected = stage.expected = true;
  new Function('shell', 'selection', inspectorListener)(inspect, selection);
  new Function('shell', 'selection', 'signal', layersListener)(
    layer, selection, new AbortController().signal);
  new Function('root', 'canvas', 'navigation',
    'let activeStage = "action";\n' + stagesListener)(
    stage, { scrollTop: 1 }, { setActive() {} });
  dispatch(inspect, 'inspector');
  dispatch(layer, 'layers');
  dispatch(stage, 'stages');
});
