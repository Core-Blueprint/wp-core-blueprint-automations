import assert from 'node:assert/strict';
import test from 'node:test';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';

// FROZEN NEGATIVE SOURCE WITNESS ONLY. The original Base B2 can flush a
// same-project context reset before final History. This is NEVER the final
// A-B2 runtime pairing after Base F02 SOURCE ACCEPT.
const B2 = '9ce68f14c12ceac88d20aa692be8a5bb064c56aa';
const base = process.env.CB_BASE_DESIGNER_B2_REPO
  || join(process.env.HOME || '', 'Downloads/wp-core-blueprint-b2-pairing');

test('A-B2-OLD-01: frozen original Base B2 shows premature equal-project view projection', () => {
  assert.equal(execFileSync('git', ['-C', base, 'rev-parse', 'HEAD'], { encoding: 'utf8' }).trim(), B2);
  const source = readFileSync(join(base, 'assets/js/design/shell/selection-controller.js'), 'utf8');
  const make = new Function(source.replace(
    'export const createDesignerSelectionController =',
    'const createDesignerSelectionController =',
  ) + '\nreturn createDesignerSelectionController;')();
  const listeners = { project: [], selection: [], history: [] };
  const visible = [];
  const state = { refs: [], primary: null, contextEpoch: 0 };
  const session = {
    subscribe: fn => { listeners.project.push(fn); return () => {}; },
    subscribeSelection: fn => { listeners.selection.push(fn); return () => {}; },
    subscribeHistory: fn => { listeners.history.push(fn); return () => {}; },
    select: () => true,
    selection: () => state,
    project: () => ({ root: { type: 'automations.workflow' } }),
  };
  const controller = make({
    typed: 'domain', session,
    renderLayers: () => visible.push('layers'),
    renderInspector: () => visible.push('inspector'),
    syncCanvas: () => visible.push('canvas'),
  });
  visible.length = 0; // Discard first initial projection, not a commit.
  const next = { ...state, contextEpoch: 1 };
  for (const listener of listeners.selection) {
    listener(next, {event:{source:'context',action:'replace'},projectRevision:0});
  }
  assert.deepEqual(visible, ['layers','inspector','canvas'],
    'original B2 publishes a view before History when project is unchanged');
  controller.destroy();
});
