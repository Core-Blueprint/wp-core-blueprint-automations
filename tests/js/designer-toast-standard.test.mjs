import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join } from 'node:path';
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

test('TOAST-A02: exact Base F02 owns Modal, Toast and fail-closed Exit Guard; consumer registers only its adapter', async () => {
  // Pin the real public Base pairing, not an inlined copy of Base's private
  // Modal/Toast/Exit Guard logic. A missing or wrong checkout fails closed.
  const base = process.env.CB_BASE_DESIGNER_F02_REPO
    || join(process.env.HOME || '', 'Downloads/wp-core-blueprint-f02-pairing');
  assert.equal(execFileSync('git', ['-C', base, 'rev-parse', 'HEAD'], { encoding: 'utf8' }).trim(),
    '11622c6224862b0b414352b0fda6e895e2cf153c');
  const baseRead = (path) => readFileSync(join(base, path), 'utf8');
  const baseDesigner = baseRead('src/Design/Editor/Assets.php');
  const baseUI = baseRead('src/UI/Assets.php');
  const baseGuard = baseRead('assets/js/features/designer-exit-guard.js');
  const baseToast = baseRead('assets/js/core/toast.js');

  // WordPress asset ownership: public Base enqueues BOTH Modal and Toast in
  // Core presentation. Automations never imports or implements a second Modal.
  assert.match(designerAssets, /DesignEditorAssets::enqueue_designer_mode\(/);
  assert.match(designerAssets, /UIAssets::enqueue_modals\( 'core' \)/);
  assert.match(designerAssets, /\[\s*'@cb-core\/design-editor',\s*'@cb-core\/toast'\s*\]/);
  assert.doesNotMatch(designerAssets, /\[\s*'@cb-core\/design-editor',\s*'@cb-core\/modal'/);
  assert.match(baseDesigner, /Assets::enqueue_toasts\(\s*\\CoreBlueprint\\Core\\UI\\Assets::TOAST_PRESENTATION_CORE\s*\)/);
  assert.match(baseDesigner, /Assets::enqueue_modals\(\s*\\CoreBlueprint\\Core\\UI\\Assets::MODAL_PRESENTATION_CORE\s*\)/);
  assert.match(baseDesigner, /self::DESIGNER_EXIT_GUARD_SCRIPT/);
  assert.match(baseUI, /'@cb-core\/modal',\s*CB_CORE_URL \. 'assets\/js\/core\/modal\.js'/);
  assert.match(baseUI, /'@cb-core\/toast',\s*CB_CORE_URL \. 'assets\/js\/core\/toast\.js'/);
  assert.match(baseToast, /window\.cbCore\.toast = toast/);

  // Base alone owns modal decisions, transient warning/error toasts, unload
  // protection and the synchronous transition authority.
  assert.match(baseGuard, /window\.cbCore\?\.toast\?\.\[variant\]\?\./);
  assert.match(baseGuard, /window\.cbCore\?\.modal\?\.show/);
  assert.match(baseGuard, /window\.addEventListener\('beforeunload'/);
  assert.match(baseGuard, /transitionDispatch\.add\(shell\)/);
  assert.match(baseGuard, /transitionDispatch\.delete\(shell\)/);
  assert.match(baseGuard, /window\.cbCore\.designerExitGuard = Object\.freeze/);
  assert.match(shell, /const exitGuard = window\.cbCore\?\.designerExitGuard/);
  assert.match(shell, /typeof exitGuard\?\.register !== 'function' \|\| typeof exitGuard\?\.requestTransition !== 'function'/);
  assert.match(shell, /exitGuard\.register\(root, \{/);
  assert.match(shell, /isUncertain: \(\) => root\.dataset\.cbDesignShellSaveOutcomeUnknown === 'true'/);
  assert.match(shell, /await saveBeforeSwitch\(\)/);
  assert.match(shell, /return !isDirty\(\)/);
  assert.match(shell, /busy: String\(labels\.saveInProgress/);
  assert.match(shell, /event\.detail\.respondWith\(switchContext\(url\)\)/);
  assert.match(save, /shell\.dispatchEvent\(new CustomEvent\('cb:design-shell:savechange'/);
  assert.match(save, /emit\(unverified \? 'unknown' : 'error'/);

  assert.doesNotMatch(shell, /(?:const notify =|showModal\(|@cb-core\/modal|cbCore\?\.toast|window\.addEventListener\('beforeunload'|const requestExit = async|window\.confirm\(|\balert\()/);
  assert.doesNotMatch(shell, /wp\.data\.dispatch|wp\.notices/);

  // Behavioral smoke of the ACTUAL pinned Base guard, not a consumer
  // replacement. A clean close may use Base Modal; busy and UNKNOWN must
  // block before any modal/transition and emit Base-owned Toast feedback.
  const toasts = [];
  const modals = [];
  const app = {
    cbCore: {
      toast: {
        error: (message) => toasts.push(['error', message]),
        warning: (message) => toasts.push(['warning', message]),
      },
      modal: { show: async (options) => { modals.push(options); return true; } },
    },
    addEventListener() {},
  };
  class ShellElement {
    constructor() {
      this.dataset = { cbDesignShellSaveState: 'idle', cbDesignShellSaveOutcomeUnknown: 'false' };
      this.isConnected = true;
      this.busy = false;
    }
    hasAttribute(name) { return name === 'aria-busy' && this.busy; }
  }
  runInNewContext(baseGuard, {
    window: app,
    document: { querySelector: () => null },
    Element: ShellElement,
  });
  const guard = app.cbCore.designerExitGuard;
  assert.equal(typeof guard?.register, 'function');
  assert.equal(typeof guard?.requestTransition, 'function');
  const target = new ShellElement();
  let clean = true;
  guard.register(target, {
    isDirty: () => !clean,
    isUncertain: () => target.dataset.cbDesignShellSaveOutcomeUnknown === 'true',
    save: async () => true,
    labels: { busy: 'Wait for save', saveFailure: 'Save unverified', unavailable: 'Unavailable' },
  });

  let exitCount = 0;
  assert.equal(await guard.request(target, () => { exitCount++; return true; }), true);
  assert.equal(exitCount, 1);
  assert.equal(modals.length, 1, 'only Base may show clean close confirmation');

  target.dataset.cbDesignShellSaveState = 'saving';
  assert.equal(await guard.request(target, () => { exitCount++; return true; }), false);
  assert.equal(exitCount, 1);
  assert.equal(modals.length, 1, 'busy Save cannot open a modal');
  assert.deepEqual(toasts.at(-1), ['warning', 'Wait for save']);

  target.dataset.cbDesignShellSaveState = 'idle';
  target.dataset.cbDesignShellSaveOutcomeUnknown = 'true';
  let applied = 0;
  const result = await guard.requestTransition(target, () => { applied++; return true; });
  assert.equal(result.status, 'blocked', 'UNKNOWN Save must deny transition');
  assert.equal(applied, 0);
  assert.equal(await guard.request(target, () => { exitCount++; return true; }), false);
  assert.deepEqual(toasts.at(-1), ['error', 'Save unverified']);
  assert.equal(modals.length, 1, 'UNKNOWN Save cannot open a modal');

  target.dataset.cbDesignShellSaveOutcomeUnknown = 'false';
  const allowed = await guard.requestTransition(target, () => { applied++; return true; });
  assert.equal(allowed.status, 'applied');
  assert.equal(applied, 1, 'Base authorizes exactly one clean context transition');
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
  assert.match(save, /if \(error\?\.code === 'REVISION_CONFLICT' \|\| unverified\)/);
  assert.match(save, /emit\(unverified \? 'unknown' : 'error'/);
});

test('TOAST-A04: no persistence, CAS or execution authority rewrite in presentation patch', () => {
  assert.doesNotMatch(shell, /(?:WorkflowRepository|WorkflowService|DefinitionCodec)\s*\(/);
  assert.match(save, /BigInt\(acknowledgedRevision\) !== BigInt\(sentRevision\) \+ 1n/);
  assert.match(save, /if \(!recovery\) return/);
});
