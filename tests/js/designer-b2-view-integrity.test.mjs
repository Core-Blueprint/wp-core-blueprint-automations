import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const history = readFileSync(new URL('../../assets/js/admin-builder-history.js', import.meta.url), 'utf8');
const match = history.match(/const renderView = \(name, context\) => \{[\s\S]*?\n  \};/);
assert.ok(match, 'test the actual adapter result boundary, not a duplicate History engine');

const fixture = (fault = null) => {
  const viewResults = new Map();
  const called = [];
  let fenced = false;
  const views = new Map(['layers','inspector','canvas'].map(name => [
    name, () => {
      called.push(name);
      if (name === fault) throw new Error('Synthetic '+name+' view render failure');
      return true;
    },
  ]));
  const origin = { expectedEpoch: 0, workflowId: '1' };
  const sync = runInNewContext(match[0] + '\nrenderView;', {
    views, viewResults, viewOrder: ['layers','inspector','canvas'],
    flushStep: 0, renderedEpoch: 0,
    captureOrigin: () => origin, boundCanvasOrigin: origin,
    api: { replace: () => {throw Error('No editor render necessary');},snapshot:()=>({}) },
    same: (a,b) => JSON.stringify(a) === JSON.stringify(b),
    fenceView: () => { fenced = true; },
  });
  return {
    sync, called, viewResults,
    get fenced() {return fenced;},
  };
};
const viewContext = {selection:{contextEpoch:0,revision:1},
  event:{action:'select'},project:{root:{}}};

test('A-B2-T08: each critical view failure is witnessed without preventing others', () => {
  for(const failed of ['layers','inspector','canvas']){
    const x=fixture(failed);
    for(const view of ['layers','inspector','canvas']) x.sync(view,viewContext);
    assert.deepEqual(x.called,['layers','inspector','canvas']);
    assert.equal(x.viewResults.get(failed),false);
    assert.equal(x.fenced,true,failed+' must trigger fail-closed Save recovery');
    for(const view of ['layers','inspector','canvas']){
      if(view!==failed)assert.equal(x.viewResults.get(view),true);
    }
  }
});

test('A-B2-T08: missing and duplicate view acknowledgements never count as success', () => {
  const missing=fixture();
  missing.sync('layers',viewContext);
  missing.sync('canvas',viewContext);
  assert.equal(missing.fenced,true);
  assert.equal(missing.viewResults.has('canvas'),false);
  const duplicate=fixture();
  duplicate.sync('layers',viewContext);
  duplicate.sync('inspector',viewContext);
  duplicate.sync('inspector',viewContext);
  assert.equal(duplicate.fenced,true);
});

test('A-B2-F02-T06: consumer never repeats a correlated final Base F02 flush', () => {
  assert.doesNotMatch(history, /controller\.refresh\(/);
  assert.match(history, /createDesignerSelectionController\(/);
  const x = fixture();
  for (const view of ['layers','inspector','canvas']) x.sync(view,viewContext);
  assert.deepEqual(x.called, ['layers','inspector','canvas']);
  assert.equal(x.fenced, false);
  assert.deepEqual([...x.viewResults.values()], [true,true,true]);
});
