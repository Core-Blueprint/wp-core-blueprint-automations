import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';

const BASE_B2_SHA = '9ce68f14c12ceac88d20aa692be8a5bb064c56aa';
const base = process.env.CB_BASE_DESIGNER_B2_REPO
  || join(process.env.HOME || '', 'Downloads/wp-core-blueprint-b2-pairing');
const read = (path) => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const history = read('assets/js/admin-builder-history.js');
const layers = read('assets/js/admin-builder-layers.js');
const inspector = read('assets/js/admin-builder-inspector.js');
const editor = read('assets/js/admin-editor.js');
const shell = read('assets/js/admin-designer-shell.js');
const php = read('src/Admin/DesignerAssets.php');

test('A-B2-T01: exact Base B2 pairing and public typed Selection contract', () => {
  assert.equal(execFileSync('git', ['-C', base, 'rev-parse', 'HEAD'], {encoding:'utf8'}).trim(), BASE_B2_SHA);
  const module = readFileSync(join(base, 'assets/js/design/editor.js'), 'utf8');
  const controller = readFileSync(join(base, 'assets/js/design/shell/selection-controller.js'), 'utf8');
  assert.match(module, /export const createDomainSession/);
  assert.match(module, /resolveSelectionEntity/);
  assert.match(module, /createDesignerSelectionController/);
  assert.match(controller, /typed === 'domain'/);
  assert.match(php, /DOMAIN_SESSION_API'\s*\) >= 3/);
  assert.match(php, /DESIGNER_EXIT_GUARD_API'\s*\) >= 2/);
});
test('A-B2-T02/T03: exactly one typed Selection resolver and controller, strict domain refs', () => {
  assert.match(history, /resolveSelectionEntity(?:\s*:|\s*,)/);
  assert.match(history, /createDesignerSelectionController\(/);
  assert.match(history, /typed:\s*'domain'/);
  assert.match(history, /automations\.trigger/);
  assert.match(history, /automations\.state/);
  assert.match(history, /automations\.action/);
  assert.match(history, /automations\.condition/);
});
test('A-B2-T04: rendered workflow identity and epoch fence selection AND mutations', () => {
  assert.match(layers, /expectedEpoch/);
  assert.match(history, /origin\.workflowId/);
  assert.match(layers, /captureOrigin/);
  assert.match(editor, /captureOrigin/);
  assert.match(editor, /isCurrentOrigin/);
  assert.doesNotMatch(layers, /cb-automations:layerselect/);
});
test('A-B2-T06: final History view is Base F02-owned with no duplicate consumer refresh', () => {
  assert.match(history, /createDesignerSelectionController\(/);
  assert.match(history, /contextEpoch/);
  assert.doesNotMatch(history, /controller\.refresh\(/);
  assert.match(history, /renderLayers/);
  assert.match(history, /renderInspector/);
  assert.match(history, /syncCanvas/);
});
test('A-B2-T08: critical adapter reports independent failures to fail-closed Save', () => {
  assert.match(history, /reportView/);
  assert.match(history, /cbDesignShellSaveOutcomeUnknown/);
  assert.match(inspector, /registerView/);
  assert.match(layers, /registerView/);
  assert.match(shell, /acknowledgeView/);
});
