import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { join, resolve } from 'node:path';

// Pair in a SEPARATE checkout. Never checkout B1 in Base Luna's working tree.
const BASE_B1 = 'ff8d62acf0360025ac23482c08017d99104abe9b';
const baseDir = resolve(process.env.CB_BASE_DESIGNER_B1_REPO || join(process.env.HOME || '', 'Downloads/wp-core-blueprint-a2-pairing'));
const read = (path) => readFileSync(join(baseDir, path), 'utf8');
const local = (path) => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

test('A2-B1-01: isolated Base checkout must contain exactly accepted public B1 SHA', () => {
  const sha = execFileSync('git', ['-C', baseDir, 'rev-parse', 'HEAD'], { encoding: 'utf8' }).trim();
  assert.equal(sha, BASE_B1, 'Base B1 pairing must be source-pinned and never use main');
});

test('A2-B1-02: Base owns Save/Discard/Cancel approval and synchronous one-dispatch authorization', () => {
  const exit = read('assets/js/features/designer-exit-guard.js');
  const context = read('assets/js/features/designer-context.js');
  const assets = read('src/Design/Editor/Assets.php');
  assert.match(assets, /DESIGNER_EXIT_GUARD_API\s*=\s*2/);
  assert.match(exit, /const requestTransition = async \(shell, apply\) =>/);
  assert.match(exit, /transitionDispatch\.add\(shell\)/);
  assert.match(exit, /target = apply\(\)/);
  assert.match(exit, /transitionDispatch\.delete\(shell\)/);
  assert.match(exit, /const result = await target/);
  assert.match(context, /guard\.requestTransition\(shell, apply\)/);
  assert.match(context, /respondWith\(promise\)/);
  assert.match(context, /root\.dispatchEvent\(new CustomEvent\(REQUEST_EVENT/);
  assert.match(context, /if \(outcome\?\.status === 'applied'\)/);
});

test('A2-B1-03: Automations enqueues only when the public API v2 gate is available', () => {
  const php = local('src/Admin/DesignerAssets.php');
  const shell = local('assets/js/admin-designer-shell.js');
  assert.match(php, /DESIGNER_EXIT_GUARD_API' \) >= 2/);
  assert.match(php, /admin_notices/);
  assert.match(shell, /typeof exitGuard\?\.requestTransition !== 'function'/);
  assert.match(shell, /event\.detail\.respondWith\(switchContext\(url\)\)/);
  assert.doesNotMatch(shell, /chooseSwitchAction|approvedSwitchUrl|awaitingChoice|showModal|new Event\('change'/);
});
