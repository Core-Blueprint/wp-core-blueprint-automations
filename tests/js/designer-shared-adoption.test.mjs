import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, existsSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';

const read = (path) => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const template = read('templates/admin/workflow-editor.php');
const shell = read('assets/js/admin-designer-shell.js');
const layers = read('assets/js/admin-builder-layers.js');
const editor = read('assets/js/admin-editor.js');
const inspector = read('assets/js/admin-builder-inspector.js');
const history = read('assets/js/admin-builder-history.js');
const assets = read('src/Admin/DesignerAssets.php');

test('DSA-01: Automations uses two Base-owned palette tabs and keeps Inspector/Settings', () => {
  assert.match(template, /cb-core-design-shell__palette--tabbed/);
  assert.match(template, /data-cb-design-shell-group="palette" data-cb-design-shell-palette-role="navigation"/);
  assert.match(template, /data-cb-design-shell-palette-role="navigation"><\?php esc_html_e\( 'Stages', 'core-blueprint-automations' \); \?><\/button>/);
  assert.doesNotMatch(template, /esc_html_e\( 'Elements', 'core-blueprint' \)/);
  assert.match(template, /data-cb-design-shell-group="palette" data-cb-design-shell-palette-role="layers"/);
  assert.match(template, /data-cb-design-shell-panel="stages" data-cb-design-shell-group="palette" data-cb-design-shell-palette-label="<\?php echo esc_attr__\( 'Stages', 'core-blueprint-automations' \); \?>"/);
  assert.match(template, /data-cb-design-shell-panel="layers" data-cb-design-shell-group="palette"/);
  assert.match(template, /data-cb-automations-elements-panel/);
  assert.match(template, /data-cb-automations-layers-host/);
  assert.match(template, /data-cb-design-shell-sidebar-role="inspector"/);
  assert.match(template, /data-cb-design-shell-sidebar-role="settings"/);
  assert.match(shell, /stageHost\.append\(navigation\.element\)/);
  assert.match(shell, /defaultPanels: \{ palette: 'stages' \}/);
  assert.doesNotMatch(template, /data-cb-design-shell-status/);
});

test('DSA-02: one canonical Base Domain Session, no second renderer or private imports', () => {
  assert.match(history, /import \{ createDomainSession, createDesignerSelectionController, animateLayoutChange \} from '@cb-core\/design-editor'/);
  assert.equal((history.match(/createDomainSession\(\{/g) || []).length, 1);
  for (const source of [shell, layers, history]) {
    assert.doesNotMatch(source, /from ['"][^'"]*assets\/js\/design\//);
  }
  assert.match(layers, /import \{ createDesignerLayerTree \} from '@cb-core\/design-editor'/);
  assert.match(layers, /const tree = createDesignerLayerTree\(/);
  assert.match(assets, /self::LAYERS_MODULE.*admin-builder-layers\.js/);
  assert.match(assets, /\[ '@cb-core\/design-editor', self::HISTORY_MODULE \]/);
  assert.match(history, /session\.captureSave\(\)/);
  assert.match(history, /session\.rebase\(/);
  assert.match(history, /session\.subscribeHistory\(/);
  const baseDir = process.env.CB_BASE_DESIGNER_REPO;
  assert.ok(baseDir, 'verified Base F02 checkout is mandatory for full regression coverage');
  assert.equal(execFileSync('git', ['-C', baseDir, 'rev-parse', 'HEAD'], { encoding: 'utf8' }).trim(),
    '11622c6224862b0b414352b0fda6e895e2cf153c');
  const exported = join(baseDir, 'assets/js/design/editor.js');
  assert.ok(existsSync(exported), 'pinned Base source must exist');
  const base = readFileSync(exported, 'utf8');
  assert.match(base, /createDesignerLayerTree/);
  assert.match(base, /createDesignerShell/);
  assert.match(base, /createDomainSession/);
});

test('DSA-03: Layers and Inspector project one canonical typed Domain Selection', () => {
  assert.match(history, /createDesignerSelectionController\(/);
  assert.match(history, /typed: 'domain'/);
  assert.match(layers, /selection\.registerView\('layers', render\)/);
  assert.match(inspector, /selection\.registerView\('inspector'/);
  assert.match(inspector, /selection\.registerView\('canvas'/);
  assert.match(layers, /tree\.render\(/);
  assert.doesNotMatch(layers + inspector, /cb-automations:layerselect|cb-automations:inspector-selection/);
  assert.doesNotMatch(layers, /createDomainSession|\.rebase\(|\.captureSave\(|\.execute\(/);
});

test('DSA-04: actual workflow layer actions are guarded and use existing canonical render history', () => {
  const match = editor.match(/const layerCollection = \(kind\) => \(\{[\s\S]*?\n\t\};\n(?=\n\twindow\.cbAutomationsEditorSession)/);
  assert.ok(match, 'production editor must expose one bounded layer action implementation');
  const state = {
    states: [{ step_id: 'a' }, { step_id: 'b' }, { step_id: 'c' }],
    conditions: [{ condition_id: 'c1' }, { condition_id: 'c2' }],
    actions: [{ step_id: 'x' }],
  };
  let changes = 0;
  let busy = false;
  const move = (collection, from, displacement) => {
    const [item] = collection.splice(from, 1);
    collection.splice(from + displacement, 0, item);
    changes++;
  };
  const action = runInNewContext(match[0] + '\nlayerAction;', {
    state,
    root: { closest: () => ({ hasAttribute: () => busy }) },
    reorder: move,
    renderAll: () => { changes++; },
  });
  assert.equal(action('move', 'state', 'a', 2), true);
  assert.deepEqual(state.states.map(x => x.step_id), ['b', 'c', 'a']);
  assert.equal(changes, 1, 'one move must produce one committed editor change');
  assert.equal(action('remove', 'condition', 'c1'), true);
  assert.deepEqual(state.conditions.map(x => x.condition_id), ['c2']);
  assert.equal(changes, 2);
  for (const operation of [
    ['remove', 'trigger', 'trigger_1'], ['remove', 'action', 'missing'],
    ['move', 'state', 'b', -1], ['move', 'state', 'b', 0],
    ['move', 'state', 'missing', 1],
  ]) {
    assert.equal(action(...operation), false, 'unsupported or stale operations fail closed');
  }
  assert.equal(changes, 2);
  busy = true;
  assert.equal(action('remove', 'action', 'x'), false, 'Save busy state prevents mutation');
  assert.equal(state.actions.length, 1);
});

test('DSA-05: only supported layer actions, no secret values or consumer context menu', () => {
  assert.match(layers, /reorder:\s*\{/);
  assert.match(layers, /remove: \(\) => allowed\(kind, id, 'remove'\)/);
  assert.match(layers, /kind === 'condition' \? 'condition_id' : 'step_id'/);
  assert.match(layers, /items\.filter\(\(candidate\) => candidate\?\.\[field\] === id\)\.length !== 1/);
  assert.match(layers, /selection\.isCurrentOrigin\(origin\)/);
  assert.doesNotMatch(layers, /binding\.value|literal|credential|password|contextmenu|contextMenu/);
  assert.doesNotMatch(layers, /createElement\('(?:div|ul|li)'\)/);
  assert.match(editor, /renderAll\(\);\n\t\t\treturn true;/);
  assert.match(history, /workflowCommands\.editorChange/);
});

test('DSA-06: pinned Base B1 owns context menu and guarded dispatch; Automations registers one adapter', () => {
  assert.match(shell, /exitGuard\.register\(root, \{/);
  assert.match(shell, /isUncertain: \(\) => root\.dataset\.cbDesignShellSaveOutcomeUnknown/);
  assert.match(shell, /save: async \(\) => \{/);
  assert.doesNotMatch(shell, /allowConfirmedExit|const requestExit = async|window\.addEventListener\('beforeunload'/);
  const baseDir = process.env.CB_BASE_DESIGNER_REPO;
  assert.ok(baseDir, 'verified Base F02 checkout is mandatory for shared Designer checks');
  const guardPath = join(baseDir, 'assets/js/features/designer-exit-guard.js');
  const launchPath = join(baseDir, 'assets/js/features/designer-launch.js');
  const layerPath = join(baseDir, 'assets/js/design/shell/layers.js');
  for (const path of [guardPath, launchPath, layerPath]) {
    assert.ok(existsSync(path), 'pinned Base source must provide the shared Designer contract');
  }
  const guard = readFileSync(guardPath, 'utf8');
  const launch = readFileSync(launchPath, 'utf8');
  const layer = readFileSync(layerPath, 'utf8');
  assert.match(guard, /window\.cbCore\.designerExitGuard = Object\.freeze\(\{ observe, register, request, requestTransition, canTransition/);
  assert.match(guard, /const unresolved = \(shell, adapter\)/);
  assert.match(launch, /designerExitGuard\?\.isTransitionDispatchAuthorized\?\.\(shell\)/);
  assert.match(launch, /const bindSaveState = \(shell, save\)/);
  assert.match(layer, /row\.addEventListener\('contextmenu'/);
  assert.match(layer, /row\.addEventListener\('dragstart'/);
});

test('DSA-07: Stages is translated in the Automations domain for all six locales', () => {
  assert.match(read('languages/core-blueprint-automations.pot'), /msgid "Stages"\nmsgstr ""/);
  const translations = {
    nl_NL: 'Fasen',
    de_DE: 'Phasen',
    fr_FR: 'Étapes',
    es_ES: 'Etapas',
    it_IT: 'Fasi',
    pt_PT: 'Fases',
  };
  for (const [locale, expected] of Object.entries(translations)) {
    const po = read(`languages/core-blueprint-automations-${locale}.po`);
    assert.ok(
      po.includes('msgid "Stages"\nmsgstr "' + expected + '"'),
      `localized Stages label must be present and reviewed for ${locale}`,
    );
  }
});


test('DSA-08: real pinned Base F02 layout keeps translated Stages and Layers distinct', () => {
  const baseDir = process.env.CB_BASE_DESIGNER_F02_REPO
    || join(process.env.HOME || '', 'Downloads/wp-core-blueprint-f02-pairing');
  assert.equal(
    execFileSync('git', ['-C', baseDir, 'rev-parse', 'HEAD'], { encoding: 'utf8' }).trim(),
    '11622c6224862b0b414352b0fda6e895e2cf153c',
    'stage-label regression requires exact accepted Base F02 SOURCE',
  );
  const baseLayoutPath = join(baseDir, 'assets/js/features/designer-layout.js');
  assert.ok(existsSync(baseLayoutPath), 'exact paired Base F02 layout must be available');
  const baseLayout = readFileSync(baseLayoutPath, 'utf8');

  // Drive the actual Base layout normalizer, not a copied label resolver.
  class FakeElement {
    constructor(dataset = {}, textContent = '') {
      this.dataset = dataset;
      this.textContent = textContent;
      this.children = [];
      this.classList = { add() {} };
      this.lookups = new Map();
    }
    querySelector(selector) { return this.lookups.get(selector) ?? null; }
    querySelectorAll(selector) { return this.lookups.get(selector) ?? []; }
  }

  const stagesTab = new FakeElement(
    { cbDesignShellTab: 'stages', cbDesignShellPaletteRole: 'navigation' }, 'Stages');
  const layersTab = new FakeElement(
    { cbDesignShellTab: 'layers', cbDesignShellPaletteRole: 'layers' }, 'Layers');
  const stagesPanel = new FakeElement(
    { cbDesignShellPanel: 'stages', cbDesignShellPaletteLabel: 'Stages' });
  const layersPanel = new FakeElement({ cbDesignShellPanel: 'layers' });
  const tablist = new FakeElement();
  tablist.children = [stagesTab, layersTab];
  tablist.lookups.set('[data-cb-design-shell-tab]', [stagesTab, layersTab]);
  const palette = new FakeElement();
  palette.children = [tablist, stagesPanel, layersPanel];
  palette.lookups.set(':scope > .cb-core-design-shell__tabs', tablist);
  palette.lookups.set(':scope > [data-cb-design-shell-panel]', [stagesPanel, layersPanel]);
  const workspace = new FakeElement();
  const canvas = new FakeElement();
  const sidebar = new FakeElement();
  workspace.children = [palette, canvas, sidebar];
  workspace.lookups.set(':scope > .cb-core-design-shell__palette', palette);
  workspace.lookups.set(':scope > .cb-core-design-shell__canvas', canvas);
  workspace.lookups.set(':scope > .cb-core-design-shell__sidebar', sidebar);
  const shellRoot = new FakeElement();
  shellRoot.lookups.set('.cb-core-design-shell__workspace', workspace);
  shellRoot.lookups.set(
    '.cb-core-design-shell__workspace > .cb-core-design-shell__palette', palette);
  const launchRoot = new FakeElement();
  launchRoot.lookups.set('[data-cb-design-shell]', shellRoot);
  const document = {
    documentElement: {},
    readyState: 'complete',
    querySelectorAll: selector => selector === '[data-cb-design-launch-root]' ? [launchRoot] : [],
  };
  const observers = [];
  class FakeMutationObserver {
    constructor(callback) { this.callback = callback; observers.push(this); }
    observe() {}
  }

  runInNewContext(baseLayout, {
    window: { cbCoreDesignerLaunch: {
      paletteLabels: { navigation: 'Navigation', elements: 'Elements', layers: 'Layers' },
    } },
    document,
    Element: FakeElement,
    MutationObserver: FakeMutationObserver,
    queueMicrotask: callback => callback(),
  }, { timeout: 3000 });

  assert.deepEqual([stagesTab.textContent, layersTab.textContent], ['Stages', 'Layers']);
  assert.equal(stagesPanel.dataset.cbDesignShellPaletteRole, 'navigation');
  assert.equal(layersPanel.dataset.cbDesignShellPaletteRole, 'layers');
  assert.equal(stagesTab.dataset.cbDesignShellPaletteRole, 'navigation');
  assert.equal(workspace.dataset.cbDesignShellLayout, 'canonical');
  // A later Base normalization must not silently restore the global Elements label.
  assert.equal(observers.length, 2);
  observers[1].callback();
  assert.deepEqual([stagesTab.textContent, layersTab.textContent], ['Stages', 'Layers']);
});
