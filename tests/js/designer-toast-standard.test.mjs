import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const read = (path) => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const save = read('assets/js/admin-builder-save.js');
const shell = read('assets/js/admin-designer-shell.js');
const template = read('templates/admin/workflow-editor.php');
const designerAssets = read('src/Admin/DesignerAssets.php');
const css = read('assets/css/admin-designer-shell.css');

test('TOAST-A01: Base owns Save state and Toast presentation, no consumer toolbar or duplicate Toast', () => {
  assert.match(save, /shell\.dispatchEvent\(new CustomEvent\('cb:design-shell:savechange'/);
  assert.match(save, /history\.acknowledgeSave\(checkpoint, submittedDefinition\)/);
  assert.ok(save.indexOf('history.acknowledgeSave(checkpoint') < save.indexOf("emit(\n\t\t\t\t'saved'"), 'Base rebase must precede confirmed Saved');
  assert.match(save, /persisted\.submitted_sha256 !== submissionSha256/);
  assert.doesNotMatch(save, /cbCore\??\.toast|new\s+Notification|status\.textContent|status\.append/);
  assert.doesNotMatch(shell, /\[data-cb-design-shell-status\]|status\.textContent/);
  assert.doesNotMatch(template, /data-cb-design-shell-status|cb-automations-builder-status/);
  assert.doesNotMatch(css, /\.cb-automations-builder-status/);
});

test('TOAST-A02: context failures use public Toast; Exit Guard owns close feedback', () => {
  assert.match(designerAssets, /enqueue_designer_mode\(/);
  assert.match(designerAssets, /\[ '@cb-core\/design-editor', '@cb-core\/modal', '@cb-core\/toast' \]/);
  const match = shell.match(/const notify = \(variant, message\) => \{([\s\S]*?)\n\t\t\};/);
  assert.ok(match, 'one public Toast adapter must be declared in Designer');
  const calls = [];
  runInNewContext(match[0] + "\nnotify('warning', 'Wait for save');\nnotify('error', 'Context failed');", {
    window: { cbCore: { toast: {
      warning: (message) => calls.push(['warning', message]),
      error: (message) => calls.push(['error', message]),
    } } },
  });
  assert.deepEqual(calls, [
    ['warning', 'Wait for save'],
    ['error', 'Context failed'],
  ]);
  // Closing a Designer is Base-owned after Exit Guard migration.
  assert.match(shell, /exitGuard\.register\(root/);
  assert.match(shell, /busy: String\(labels\.saveInProgress/);
  assert.match(designerAssets, /DESIGNER_EXIT_GUARD_API/);
  assert.doesNotMatch(shell, /notify\('warning', window\.cbAutomationsInspectorStrings\?\.saveInProgress/);
  assert.doesNotMatch(shell, /const requestExit|const chooseExitAction|data-cb-design-shell-close/);
  assert.match(shell, /notify\('error', error instanceof Error \? error\.message : String\(error\)\)/);
  assert.doesNotMatch(shell, /wp\.data\.dispatch|wp\.notices|alert\(|window\.confirm\(/);
});

test('TOAST-A03: persistent conflict recovery moved to Settings, never transient toolbar', () => {
  const settingsIndex = template.indexOf('data-cb-design-shell-panel="settings"');
  const recoveryIndex = template.indexOf('data-cb-automations-save-recovery');
  assert.ok(settingsIndex >= 0 && recoveryIndex > settingsIndex, 'recovery surface must be in Settings panel');
  assert.match(template, /data-cb-automations-save-recovery hidden/);
  assert.match(css, /\[data-cb-automations-save-recovery\]\[hidden\]\s*\{[^}]*display:\s*none\s*!important/s);
  assert.match(save, /review\.target = '_blank'/);
  assert.match(save, /review\.rel = 'noopener noreferrer'/);
  assert.match(save, /shell\.addEventListener\('cb-automations:contextloaded', clearRecovery\)/);
  assert.match(save, /if \(error\?\.code === 'REVISION_CONFLICT' \|\| error\?\.code === 'SAVE_UNVERIFIED'\)/);
});

test('TOAST-A04: no persistence, CAS or execution authority rewrite in presentation patch', () => {
  assert.doesNotMatch(shell, /(?:WorkflowRepository|WorkflowService|DefinitionCodec)\s*\(/);
  assert.match(save, /BigInt\(acknowledgedRevision\) !== BigInt\(sentRevision\) \+ 1n/);
  assert.match(save, /if \(!recovery\) return/);
});
