import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync, existsSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
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
  assert.match(template, /data-cb-design-shell-group="palette" data-cb-design-shell-palette-role="elements"/);
  assert.match(template, /data-cb-design-shell-palette-role="elements"><\\?php esc_html_e\\( 'Stages', 'core-blueprint-automations' \\); \\?><\\/button>/);
  assert.doesNotMatch(template, /esc_html_e\\( 'Elements', 'core-blueprint' \\)/);
  assert.match(template, /data-cb-design-shell-group="palette" data-cb-design-shell-palette-role="layers"/);
  assert.match(template, /data-cb-design-shell-panel="elements" data-cb-design-shell-group="palette"/);
  assert.match(template, /data-cb-design-shell-panel="layers" data-cb-design-shell-group="palette"/);
  assert.match(template, /data-cb-automations-elements-panel/);
  assert.match(template, /data-cb-automations-layers-host/);
  assert.match(template, /data-cb-design-shell-sidebar-role="inspector"/);
  assert.match(template, /data-cb-design-shell-sidebar-role="settings"/);
  assert.match(shell, /stageHost\.append\(navigation\.element\)/);
  assert.match(shell, /defaultPanels: \{ palette: 'elements' \}/);
  assert.doesNotMatch(template, /data-cb-design-shell-status/);
});

test('DSA-02: one canonical Base Domain Session, no second renderer or private imports', () => {
  assert.match(history, /import \{ createDomainSession, animateLayoutChange \} from '@cb-core\/design-editor'/);
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
  if (baseDir) {
    const exported = join(baseDir, 'assets/js/design/editor.js');
    assert.ok(existsSync(exported), 'pinned Base source must exist when explicitly supplied');
    const base = readFileSync(exported, 'utf8');
    assert.match(base, /createDesignerLayerTree/);
    assert.match(base, /createDesignerShell/);
    assert.match(base, /createDomainSession/);
  }
});

test('DSA-03: Layers selection is presentation-only and invokes the one Inspector', () => {
  assert.match(layers, /cb-automations:layerselect/);
  assert.match(inspector, /addEventListener\('cb-automations:layerselect'/);
  assert.match(inspector, /if \(card\) selectCard\(card\)/);
  assert.match(inspector, /cb-automations:inspector-selection/);
  assert.match(layers, /addEventListener\('cb-automations:inspector-selection'/);
  assert.match(layers, /cb-automations:contextloaded/);
  assert.match(layers, /tree\.render\(groups\)/);
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
  assert.match(layers, /if \(!id \|\| values\.findIndex/);
  assert.doesNotMatch(layers, /binding\.value|literal|credential|password|contextmenu|contextMenu/);
  assert.doesNotMatch(layers, /createElement\('(?:div|ul|li)'\)/);
  assert.match(editor, /renderAll\(\);\n\t\t\treturn true;/);
  assert.match(history, /workflowCommands\.editorChange/);
});

test('DSA-06: merged Base owns context menu and fail-closed Exit Guard; Automations registers an adapter', () => {
  assert.match(shell, /exitGuard\.register\(root, \{/);
  assert.match(shell, /isUncertain: \(\) => root\.dataset\.cbDesignShellSaveOutcomeUnknown/);
  assert.match(shell, /save: async \(\) => \{/);
  assert.doesNotMatch(shell, /allowConfirmedExit|const requestExit = async|window\.addEventListener\('beforeunload'/);
  const baseDir = process.env.CB_BASE_DESIGNER_REPO;
  if (baseDir) {
    const guardPath = join(baseDir, 'assets/js/features/designer-exit-guard.js');
    const launchPath = join(baseDir, 'assets/js/features/designer-launch.js');
    const layerPath = join(baseDir, 'assets/js/design/shell/layers.js');
    for (const path of [guardPath, launchPath, layerPath]) {
      assert.ok(existsSync(path), 'merged Base source must provide the shared Designer contract');
    }
    const guard = readFileSync(guardPath, 'utf8');
    const launch = readFileSync(launchPath, 'utf8');
    const layer = readFileSync(layerPath, 'utf8');
    assert.match(guard, /window\.cbCore\.designerExitGuard = Object\.freeze\(\{ observe, register, request, canTransition/);
    assert.match(guard, /const unresolved = \(shell, adapter\)/);
    assert.match(launch, /designerExitGuard\?\.canTransition\?\.\(shell\)/);
    assert.match(launch, /const bindSaveState = \(shell, save\)/);
    assert.match(layer, /row\.addEventListener\('contextmenu'/);
    assert.match(layer, /row\.addEventListener\('dragstart'/);
  }
});

test('DSA-07: Stages is translated in the Automations domain for all six locales', () => {
  assert.match(read('languages/core-blueprint-automations.pot'), /msgid "Stages"\\nmsgstr ""/);
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
      po.includes(`msgid "Stages"\\nmsgstr "${expected}"`),
      `localized Stages label must be present and reviewed for ${locale}`,
    );
  }
});
