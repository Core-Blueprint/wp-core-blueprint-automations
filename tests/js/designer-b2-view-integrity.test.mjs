import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { runInNewContext } from 'node:vm';
import { toProject, fromProject } from '../../assets/js/automations-domain-project.js';

// A-B2-R01: execute the actual Automations render/verification/Save closures
// with the real corrected Base F02 SelectionController. Do not mirror or
// redefine consumer acceptance rules in the fixture.
const history = readFileSync(new URL('../../assets/js/admin-builder-history.js', import.meta.url), 'utf8');
const inspector = readFileSync(new URL('../../assets/js/admin-builder-inspector.js', import.meta.url), 'utf8');
const layers = readFileSync(new URL('../../assets/js/admin-builder-layers.js', import.meta.url), 'utf8');
const shellSource = readFileSync(new URL('../../assets/js/admin-designer-shell.js', import.meta.url), 'utf8');
const F02 = '11622c6224862b0b414352b0fda6e895e2cf153c';
const base = process.env.CB_BASE_DESIGNER_F02_REPO
  || join(process.env.HOME || '', 'Downloads/wp-core-blueprint-f02-pairing');
assert.equal(execFileSync('git', ['-C', base, 'rev-parse', 'HEAD'], {encoding:'utf8'}).trim(), F02);
const baseController = readFileSync(join(base, 'assets/js/design/shell/selection-controller.js'), 'utf8');
const createController = new Function(baseController.replace(
  'export const createDesignerSelectionController =',
  'const createDesignerSelectionController =',
) + '\nreturn createDesignerSelectionController;')();

const extract = (pattern, label) => {
  const match = history.match(pattern);
  assert.ok(match, label + ' must be tested from real consumer source');
  return match;
};
const definitions = extract(/const views = new Map\(\);[\s\S]*?let disposed = false;/,
  'view registry');
const fence = extract(/const fenceView = \(\) => \{[\s\S]*?\n  \};/, 'failure boundary');
const captureOrigin = extract(/const captureOrigin = \(\) => Object\.freeze\(\{[\s\S]*?\n  \}\);/,
  'render-time provenance');
const render = extract(/const renderView = \(name, context\) => \{[\s\S]*?\n  \};/,
  'canonical view render adapter');
const verified = extract(/verified: \(name\) => ([\s\S]*?),\n    reportView:/,
  'postcommit view verification')[1];
const captureSave = extract(/const captureSave = \(\) => \{[\s\S]*?\n  \};/,
  'fail-closed Save checkpoint');
assert.doesNotMatch(history, /controller\.refresh\(/,
  'Base F02 must own the one final History view');

const definition = Object.freeze({
  definition_version: 1, trigger: null, states: [], conditions: [], actions: [],
});
const project = toProject(definition);
const viewOrder = ['layers', 'inspector', 'canvas'];
const createFixture = () => {
  let workflow = 'workflow_a';
  let snapshot = Object.freeze({ refs: [], primary: null, contextEpoch: 0 });
  let fault = null;
  let rebinds = 0;
  const sequence = [];
  const projectObservers = [];
  const selectionObservers = [];
  const historyObservers = [];
  const notice = { hidden: true, setAttribute() {}, textContent: '' };
  const shell = {
    dataset: { cbDesignShellSaveOutcomeUnknown: 'false', cbDesignShellSaveState: 'idle' },
    dispatchEvent: () => true,
  };
  const session = {
    project: () => project,
    selection: () => snapshot,
    select: () => true,
    subscribe: (fn) => { projectObservers.push(fn); return () => {}; },
    subscribeSelection: (fn) => { selectionObservers.push(fn); return () => {}; },
    subscribeHistory: (fn) => { historyObservers.push(fn); return () => {}; },
    captureSave: () => Object.freeze({ token: 'checkpoint', submittedProject: project }),
  };
  const consumer = runInNewContext([
    definitions[0],
    'const notice = status;',
    fence[0],
    captureOrigin[0],
    render[0],
    'const selectionApi = Object.freeze({ ready: () => true, verified: (name) => ' + verified + ' });',
    captureSave[0],
    '({ renderView, verified: selectionApi.verified, captureSave, views, viewResults })',
  ].join('\n'), {
    session, shell, status: notice,
    workflowId: () => workflow,
    api: { snapshot: () => definition, replace: () => { rebinds++; } },
    window: { cbAutomationsInspectorStrings: {} },
    CustomEvent: class { constructor(type, options) { this.type = type; this.detail = options.detail; } },
    fromProject,
    same: (a, b) => JSON.stringify(a) === JSON.stringify(b),
  });
  for (const name of viewOrder) {
    consumer.views.set(name, () => {
      sequence.push('V:' + name);
      if (fault === name) throw new Error('Synthetic ' + name + ' view failure');
      return true;
    });
  }
  const controller = createController({
    typed: 'domain', session, openInspectorOnInsert: false,
    renderLayers: (ctx) => consumer.renderView('layers', ctx),
    renderInspector: (ctx) => consumer.renderView('inspector', ctx),
    syncCanvas: (ctx) => consumer.renderView('canvas', ctx),
  });
  const allVerified = () => viewOrder.every((name) => consumer.verified(name) === true);
  const switchWorkflow = (id, revision) => {
    // The workflow ID is already staged in the form before Base History commit.
    workflow = id;
    snapshot = Object.freeze({ refs: [], primary: null, contextEpoch: revision });
    sequence.push('S');
    for (const fn of selectionObservers) {
      fn(snapshot, { event: Object.freeze({
        source: 'context', action: 'replace', historyRevision: revision,
      }) });
    }
    const beforeHistory = [...sequence];
    sequence.push('H');
    for (const fn of historyObservers) {
      fn(Object.freeze({ source: 'context', action: 'replace', historyRevision: revision }));
    }
    return beforeHistory;
  };
  return {
    session, shell, consumer, sequence, notice, controller, allVerified, switchWorkflow,
    fail: (name) => { fault = name; },
    deleteAdapter: (name) => consumer.views.delete(name),
    setEpoch: (epoch) => {
      snapshot = Object.freeze({ refs: [], primary: null, contextEpoch: epoch });
    },
    get rebinds() { return rebinds; },
    get workflowId() { return workflow; },
    get epoch() { return snapshot.contextEpoch; },
  };
};

const assertSaveBlocked = (x) => {
  assert.throws(() => x.consumer.captureSave(), /selection is not verified|Reload before saving/i);
  assert.equal(x.shell.dataset.cbDesignShellSaveOutcomeUnknown, 'true');
};
test('A-B2-R01-T01: equal-content workflows A→B→C verify epoch ONLY after matching F02 History and exactly one final view', () => {
  const x = createFixture();
  assert.equal(x.allVerified(), true, 'initial view must be fully rendered');
  assert.equal(x.consumer.captureSave().token, 'checkpoint');
  x.sequence.length = 0;
  const beforeB = x.switchWorkflow('workflow_b', 1);
  assert.deepEqual(beforeB, ['S'], 'marked Selection must not render before matching History');
  assert.deepEqual(x.sequence, ['S', 'H', 'V:layers', 'V:inspector', 'V:canvas']);
  assert.equal(x.allVerified(), true, 'B must be verified at the newly committed epoch');
  assert.equal(x.consumer.captureSave().token, 'checkpoint',
    'Save must not be falsely blocked after successful final B view');
  x.sequence.length = 0;
  const beforeC = x.switchWorkflow('workflow_c', 2);
  assert.deepEqual(beforeC, ['S']);
  assert.deepEqual(x.sequence, ['S', 'H', 'V:layers', 'V:inspector', 'V:canvas']);
  assert.equal(x.allVerified(), true, 'C must be verified at the second new epoch');
  assert.equal(x.consumer.captureSave().token, 'checkpoint');
  assert.equal(x.shell.dataset.cbDesignShellSaveOutcomeUnknown, 'false');
  assert.ok(x.rebinds >= 3, 'Canvas must be rebound to each new workflow origin');
  x.controller.destroy();
});
test('A-B2-R01-T02: each failed critical adapter denies epoch verification and Save after F02 commit', () => {
  for (const failed of viewOrder) {
    const x = createFixture();
    x.fail(failed);
    x.switchWorkflow('workflow_b', 1);
    assert.equal(x.allVerified(), false, failed + ' must not verify the new epoch');
    assert.equal(x.consumer.viewResults.get(failed), false);
    assert.equal(x.shell.dataset.cbDesignShellSaveOutcomeUnknown, 'true');
    assert.equal(x.notice.hidden, false, 'show recovery on a critical adapter failure');
    assertSaveBlocked(x);
    x.controller.destroy();
  }
});
test('A-B2-R01-T03: missing, duplicate or stale/mixed-context adapter result fails closed', () => {
  const missing = createFixture();
  missing.deleteAdapter('inspector');
  missing.switchWorkflow('workflow_b', 1);
  assert.equal(missing.allVerified(), false);
  assertSaveBlocked(missing);
  missing.controller.destroy();

  const duplicate = createFixture();
  duplicate.switchWorkflow('workflow_b', 1);
  duplicate.consumer.renderView('inspector', {
    selection: { contextEpoch: 1 }, project,
  });
  assert.equal(duplicate.allVerified(), false);
  assertSaveBlocked(duplicate);
  duplicate.controller.destroy();

  const stale = createFixture();
  stale.setEpoch(1);
  stale.consumer.renderView('layers', {
    selection: { contextEpoch: 0 }, project,
  });
  assert.equal(stale.allVerified(), false);
  assertSaveBlocked(stale);
  stale.controller.destroy();

  const mixed = createFixture();
  mixed.setEpoch(1);
  mixed.consumer.renderView('layers', {selection: {contextEpoch: 1}, project});
  mixed.setEpoch(2);
  mixed.consumer.renderView('inspector', {selection: {contextEpoch: 2}, project});
  assert.equal(mixed.allVerified(), false);
  assertSaveBlocked(mixed);
  mixed.controller.destroy();
});
test('A-B2-R01-T04: UNKNOWN Save remains blocked despite successful subsequent view flush', () => {
  const x = createFixture();
  x.shell.dataset.cbDesignShellSaveOutcomeUnknown = 'true';
  x.switchWorkflow('workflow_b', 1);
  assert.equal(x.allVerified(), false);
  assertSaveBlocked(x);
  x.controller.destroy();
});

const realListener = (source, start, end) => {
  const i = source.indexOf(start), j = source.indexOf(end, i);
  assert.ok(i !== -1 && j > i, 'existing critical ACK callback must be present');
  return source.slice(i, j + end.length);
};
const inspectorListener = realListener(inspector,
  "shell.addEventListener('cb-automations:contextloaded', (event) => {", '\n  });');
const layersListener = realListener(layers,
  "shell.addEventListener('cb-automations:contextloaded', (event) => {", '\n  }, { signal });');
const stagesListener = realListener(shellSource,
  "\t\troot.addEventListener('cb-automations:contextloaded', (event) => {", '\n\t\t});');
class DetailEvent extends Event {
  constructor(type, options) {
    super(type);
    this.detail = options.detail;
  }
}
test('A-B2-R01-T05: real Inspector/Layers/Stages postcommit ACKs succeed only after F02 final epoch is verified', () => {
  const x = createFixture();
  x.switchWorkflow('workflow_b', 1);
  const inspectorTarget = new EventTarget(), layersTarget = new EventTarget(), stagesTarget = new EventTarget();
  new Function('shell', 'selection', inspectorListener)(inspectorTarget, x.consumer);
  new Function('shell', 'selection', 'signal', layersListener)(
    layersTarget, x.consumer, new AbortController().signal);
  new Function('root', 'canvas', 'navigation',
    'let activeStage = "action";\n' + stagesListener)(
    stagesTarget, {scrollTop: 1}, {setActive() {}});
  const views = new Map([['inspector', null], ['layers', null], ['stages', null]]);
  let invalid = false;
  const acknowledgeView = (name, ok) => {
    if (!views.has(name) || views.get(name) !== null) invalid = true;
    else views.set(name, ok === true);
  };
  const event = () => new DetailEvent('cb-automations:contextloaded', {
    detail: { acknowledgeView },
  });
  inspectorTarget.dispatchEvent(event());
  layersTarget.dispatchEvent(event());
  stagesTarget.dispatchEvent(event());
  assert.equal(invalid, false);
  assert.deepEqual(Array.from(views.entries()),
    [['inspector', true], ['layers', true], ['stages', true]]);
  assert.equal(x.consumer.captureSave().token, 'checkpoint');
  x.controller.destroy();
});
