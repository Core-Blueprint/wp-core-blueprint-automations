import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const source = readFileSync(new URL('../../assets/js/admin-advanced-editor.js', import.meta.url), 'utf8');
test('ADV-03A editor remains scoped to Advanced root and does not touch Standard session', () => {
  assert.match(source, /data-cb-advanced-root/);
  assert.doesNotMatch(source, /data-cb-automations-editor-form|@cb-core\/design-editor|GraphSandboxAdmission/);
  assert.match(source, /definition_version !== 2/);
});
test('ADV-03A supports canonical editor graph operations', () => {
  for (const value of ['trigger', 'state', 'if_else', 'action', 'exclusive_merge', 'end']) {
    assert.match(source, new RegExp('\\b' + value + '\\b'));
  }
  for (const value of ['setPointerCapture', 'canConnect(', 'outgoing(', 'incoming(', 'removeNode(', 'JSON.stringify(draft)', 'beforeunload']) {
    assert.ok(source.includes(value), value);
  }
});
test('ADV-03A keeps type, cap and precision fences on serialized data', () => {
  assert.match(source, /Number\.isSafeInteger/);
  assert.match(source, /field\?\.sensitive/);
  assert.match(source, /documentField\.checkValidity\(\)/);
  assert.match(source, /size > 262144/);
  assert.doesNotMatch(source, /RuntimeWorker|wp\.ajax|admin-ajax\.php/);
});
const advancedTemplate = readFileSync(new URL('../../templates/admin/advanced-workflow-editor.php', import.meta.url), 'utf8');
const shellAdapter = readFileSync(new URL('../../assets/js/admin-advanced-designer-shell.js', import.meta.url), 'utf8');
const stylesheet = readFileSync(new URL('../../assets/css/admin-advanced-editor.css', import.meta.url), 'utf8');
const assets = readFileSync(new URL('../../src/Admin/DesignerAssets.php', import.meta.url), 'utf8');

test('ADV-03A-R2 Base launch: no inline canvas before the native Designer launch button', () => {
  for (const key of ['data-cb-design-launch-root', 'data-cb-design-launch-context', 'data-cb-design-shell', 'data-cb-design-shell-fullscreen', 'data-cb-design-shell-primary-action']) {
    assert.ok(advancedTemplate.includes(key), key);
  }
  assert.match(advancedTemplate, /data-cb-design-shell data-cb-advanced-root hidden/);
  assert.match(advancedTemplate, /cb-core-design-shell__workspace--collapsible/);
  assert.match(advancedTemplate, /cb-core-design-shell__sidebar--composed/);
  const sidebarStart = advancedTemplate.indexOf('cb-core-design-shell__sidebar--composed');
  const sidebarName = advancedTemplate.indexOf('name="name"', sidebarStart);
  const sidebarHealth = advancedTemplate.indexOf('cb-advanced-editor-validation', sidebarStart);
  assert.ok(sidebarStart >= 0 && sidebarName > sidebarStart && sidebarHealth > sidebarName, 'Name and health remain in the native fullscreen sidebar');
});

test('ADV-03A-R2 Base contract: Base exclusively owns fullscreen, launch and exit guard', () => {
  assert.match(shellAdapter, /import \{ createDesignerShell \} from '@cb-core\/design-editor'/);
  assert.match(shellAdapter, /guard\.register\(root, \{/);
  assert.match(shellAdapter, /createDesignerShell\(root\)/);
  assert.match(shellAdapter, /cbAdvancedDirty/);
  assert.doesNotMatch(shellAdapter, /requestFullscreen\(|exitFullscreen\(|\.classList\.add\('is-fullscreen'\)/);
  assert.match(assets, /admin-advanced-designer-shell\.js/);
  assert.match(assets, /wp_enqueue_script_module\(/);
  assert.match(assets, /'@cb-core\/design-editor'/);
  assert.match(source, /root\.dataset\.cbAdvancedDirty = changed \? 'true' : 'false'/);
  assert.match(source, /root\.dataset\.cbAdvancedDirty = 'false'/);
});

test('ADV-03A-R2 Base theme: canonical semantic dark/light tokens, no white custom fallback', () => {
  for (const token of ['--cb-surface-0', '--cb-surface-1', '--cb-surface-2', '--cb-text-strong', '--cb-text-muted', '--cb-border', '--cb-accent']) {
    assert.ok(stylesheet.includes(token), token);
  }
  assert.doesNotMatch(stylesheet, /#fff\b|#f6f7f7\b|var\(--cb-surface,|var\(--cb-canvas,/i);
  assert.doesNotMatch(stylesheet, /position:\s*fixed\b/);
  assert.match(stylesheet, /\.cb-advanced-editor-shell\[hidden\]\s*\{\s*display:\s*none/);
});

test('ADV-03A-R3 uses only public Base CN-1 and CM-1 controllers', () => {
  assert.match(shellAdapter, /import \{ createDesignerCanvasNavigation, createDesignerContextMenu, createDomainSession \} from '@cb-core\/design-editor'/);
  assert.match(shellAdapter, /createDesignerCanvasNavigation\(/);
  assert.match(shellAdapter, /createDesignerContextMenu\(/);
  for (const name of ['CANVAS_NAVIGATION_API', 'DESIGNER_CONTEXT_MENU_API']) assert.ok(assets.includes(name));
  for (const marker of ['data-cb-design-canvas-viewport', 'data-cb-design-canvas-scene', 'data-cb-design-canvas-background', 'data-cb-advanced-fit']) {
    assert.ok(advancedTemplate.includes(marker), marker);
  }
  assert.doesNotMatch(shellAdapter, /assets\/js\/design\/shell\/|\/canvas-navigation\.js|\/context-menu\.js/);
});
test('ADV-03A-R3 preserves scaled canvas geometry and consumer graph authority', () => {
  assert.match(source, /canvasNavigation\.clientToCanvas\(/);
  assert.match(source, /getContentBounds: contentBounds/);
  assert.match(source, /registerTarget\(card, \{kind: 'node', id: node\.id\}\)/);
  assert.match(source, /const canInvokeMenu = /);
  assert.match(source, /window\.confirm\(/);
  assert.match(shellAdapter, /canvasNavigation\.fitToScreen\(/);
  assert.doesNotMatch(source, /createDesignerCanvasNavigation|createDesignerContextMenu|createDomainSession/);
  // ADV-03C adopted node duplication on the existing public Base menu.
  assert.match(source, /const duplicateNode = \(id\) =>/);
});

test('ADV-03A-R3 context actions preserve keyboard focus on configure and delete', () => {
  assert.match(advancedTemplate, /data-cb-advanced-inspector tabindex="-1"/);
  assert.match(source, /removeNode\(target\.id\);/);
  assert.match(source, /\?\.focus\(\{preventScroll: true\}\)/);
});

test('ADV-03A-R4 visual input and output ports support pointer and keyboard connections', () => {
  for (const piece of ['cb-advanced-node-port', 'createPorts(', 'setPointerCapture', 'canvasPoint(event)', 'inputAt(', 'renderEdges()', 'refreshPorts()', 'deleteEdge(', 'const seen = new Set(), stack = [to]']) {
    assert.ok((source + stylesheet).includes(piece), piece);
  }
  assert.match(advancedTemplate, /role="group" aria-label="Workflow connections"/);
  assert.match(source, /event\.key === 'Delete' \|\| event\.key === 'Backspace'/);
});

test('ADV-03C duplicating nodes uses a new ID, safe branch config and no cloned edges', () => {
  assert.match(source, /const duplicateNode = \(id\) =>/);
  assert.match(source, /const newId = uid\('n'\)/);
  assert.match(source, /if \(original\.type === 'if_else'\)/);
  assert.match(source, /Edges are NOT duplicated/);
  assert.match(source, /\{id: 'duplicate', label: 'Duplicate node'/);
  assert.match(source, /duplicateNode\(target\.id\)/);
});

test('ADV-03B preview is read-only, revision-bound and never invokes providers', () => {
  for (const marker of ['data-cb-advanced-preview-run', 'data-cb-advanced-preview-fixtures', 'data-cb-advanced-preview-nonce']) {
    assert.ok(advancedTemplate.includes(marker), marker);
  }
  assert.match(source, /action: 'cb_automations_preview_advanced'/);
  assert.match(source, /Simulating saved workflow route/);
  assert.match(source, /Save and reopen this workflow before testing/);
  assert.doesNotMatch(source, /GraphProviderExecutor|GraphRunPipeline|GraphSandboxAdmission/);
});

test('ADV-03B creates only non-sensitive schema-shaped local simulation samples', () => {
  assert.match(advancedTemplate, /data-cb-advanced-preview-examples/);
  assert.match(source, /previewExamples\?\.addEventListener\('click'/);
  assert.match(source, /if \(field\?\.sensitive\) continue/);
  assert.match(source, /previewFixture\.value = JSON\.stringify\(samples, null, 2\)/);
});

test('ADV-03C history uses public Base domain session and no parallel undo engine', () => {
  assert.match(shellAdapter, /historySession = createDomainSession\(/);
  assert.match(shellAdapter, /historySession\.subscribeHistory\(/);
  assert.match(shellAdapter, /historySession\?\.dispose\(\)/);
  assert.match(advancedTemplate, /data-cb-advanced-undo disabled/);
  assert.match(advancedTemplate, /data-cb-advanced-redo disabled/);
  assert.match(assets, /DOMAIN_SESSION_API/);
  assert.match(source, /historySession\.execute\(\{/);
  assert.match(source, /historySession\.undo\(\)/);
  assert.match(source, /historySession\.redo\(\)/);
  assert.match(source, /restoreHistory\(rootData\)/);
  assert.doesNotMatch(source, /const undoStack|const redoStack|new CommandHistory/);
});

test('ADV-03B clearly labels and highlights a simulated route without persisting it', () => {
  assert.match(source, /let simulatedRoute = null/);
  assert.match(source, /simulatedRoute = \{nodes: new Set\(json\.data\.nodes\), decisions:/);
  assert.match(source, /Route simulated\. No actions were executed/);
  assert.match(source, /not on the simulated route/);
  assert.match(source, /\bis-simulated\b/);
  assert.match(stylesheet, /\.cb-advanced-node\.is-not-on-route/);
  assert.doesNotMatch(source, /draft\.simulatedRoute\s*=/);
});

test('ADV-03C fullscreen scrollbar polish retains scroll fallback', () => {
  assert.match(stylesheet, /\.cb-advanced-editor-shell\.is-fullscreen \.cb-advanced-editor-canvas-scroll \{/);
  assert.match(stylesheet, /scrollbar-width: none/);
  assert.match(stylesheet, /::-webkit-scrollbar/);
  assert.match(stylesheet, /\.cb-advanced-editor-canvas-scroll \{[^}]*overflow: auto/s);
});

test('ADV-03B preview offers simple Boolean branch switching and hides expert JSON by default', () => {
  assert.match(advancedTemplate, /<details class="cb-advanced-preview-fixtures">/);
  assert.match(advancedTemplate, /data-cb-advanced-preview-toggle/);
  assert.match(source, /previewToggle\?\.addEventListener\('click'/);
  assert.match(source, /field\?\.sensitive \|\| field\?\.type !== 'boolean'/);
  assert.match(source, /fields\[name\] = !fields\[name\]/);
  assert.match(stylesheet, /\.cb-advanced-preview-actions/);
});
