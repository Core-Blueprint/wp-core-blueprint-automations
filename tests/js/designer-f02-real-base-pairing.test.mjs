import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

const F02 = '11622c6224862b0b414352b0fda6e895e2cf153c';
const base = process.env.CB_BASE_DESIGNER_F02_REPO
  || join(process.env.HOME || '', 'Downloads/wp-core-blueprint-f02-pairing');
const local = path => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');

test('A-B2-F02-01: exact corrected Base F02 source and unchanged PHP capability gates', () => {
  assert.equal(execFileSync('git', ['-C', base, 'rev-parse', 'HEAD'], {encoding:'utf8'}).trim(), F02);
  const editor = readFileSync(join(base, 'assets/js/design/editor.js'), 'utf8');
  const history = readFileSync(join(base, 'assets/js/design/core/history.js'), 'utf8');
  const controller = readFileSync(join(base, 'assets/js/design/shell/selection-controller.js'), 'utf8');
  const php = readFileSync(join(base, 'src/Design/Editor/Assets.php'), 'utf8');
  assert.match(editor, /createDomainSession/);
  assert.match(editor, /createDesignerSelectionController/);
  assert.match(controller, /pendingRevision/);
  assert.match(controller, /historyRevision/);
  assert.match(history, /historyRevision/);
  assert.match(php, /DOMAIN_SESSION_API\s*=\s*3/);
  assert.match(php, /DESIGNER_EXIT_GUARD_API\s*=\s*2/);
  const consumer = local('assets/js/admin-builder-history.js');
  assert.equal((consumer.match(/createDomainSession\(\{/g) || []).length, 1);
  assert.equal((consumer.match(/createDesignerSelectionController\(\{/g) || []).length, 1);
  assert.doesNotMatch(consumer, /controller\.refresh\(/);
});

test('A-B2-F02-02: execute real Base F02 source regressions for S→H→V and identical-content context', () => {
  assert.equal(execFileSync('git', ['-C', base, 'rev-parse', 'HEAD'], {encoding:'utf8'}).trim(), F02);
  const file = join(base, 'tests/js/design-b2-f02-history-final-view.test.mjs');
  assert.equal(existsSync(file), true);
  // This is Base's own real-module suite; it covers the public controller
  // with canonical ProjectState, Selection and CommandHistory, not copied mocks.
  const output = execFileSync(process.execPath, ['--test', file], {
    cwd: base, encoding: 'utf8',
    env: {...process.env, CB_F02_BASE_SOURCE: ''},
    stdio: ['ignore', 'pipe', 'pipe'],
  });
  assert.match(output, /# pass 19/);
  assert.match(output, /# fail 0/);
});
