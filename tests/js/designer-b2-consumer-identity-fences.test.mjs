import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import { fromProject, toProject } from '../../assets/js/automations-domain-project.js';

const source = (path) => readFileSync(new URL('../../' + path, import.meta.url), 'utf8');
const history = source('assets/js/admin-builder-history.js');
const layers = source('assets/js/admin-builder-layers.js');
const editor = source('assets/js/admin-editor.js');

const action = (id) => ({
  step_id: id,
  capability: {kind:'action',provider:'core-blueprint-work',id:'action.run',schema_version:'1'},
  bindings: {},
});
const state = (id) => ({
  step_id: id,
  capability: {kind:'state',provider:'core-blueprint-work',id:'state.read',schema_version:'1'},
  bindings: {},
});
const condition = (id) => ({
  condition_id:id, left:{source:'literal',value:'ok'},
  operator:'equals', right:{source:'literal',value:'ok'},
});
const definition = () => ({
  definition_version: 1,
  trigger: {step_id:'trigger_a',capability:{
    kind:'trigger',provider:'core-blueprint-work',id:'trigger.event',schema_version:'1',
  }, bindings:{}},
  states:[state('state_a')],conditions:[condition('condition_a')],
  actions:[action('action_a'),action('action_b')],
});

test('A-B2-T02: actual consumer resolver matches one correct type+stable ID only', () => {
  const segment = history.match(/const types = Object\.freeze\(\{[\s\S]*?\n  \}\);[\s\S]*?const resolveSelectionEntity = \(ref, project\) => \{[\s\S]*?\n  \};/);
  assert.ok(segment, 'real resolver must stay independently testable');
  const resolver = new Function('fromProject', segment[0] + '\nreturn resolveSelectionEntity;')(fromProject);
  const project = toProject(definition());
  const ref = (type,id) => ({kind:'entity',type:'automations.'+type,id});
  assert.equal(resolver(ref('trigger','trigger_a'),project),true);
  assert.equal(resolver(ref('state','state_a'),project),true);
  assert.equal(resolver(ref('action','action_a'),project),true);
  assert.equal(resolver(ref('condition','condition_a'),project),true);
  assert.equal(resolver(ref('state','action_a'),project),false);
  assert.equal(resolver(ref('action','condition_a'),project),false);
  assert.equal(resolver(ref('action','missing'),project),false);
  assert.equal(resolver(ref('trigger','action_a'),project),false);
  assert.equal(resolver(ref('unknown','action_a'),project),false);
  assert.equal(resolver(ref('condition',''),project),false);
  const noTrigger = definition();
  noTrigger.trigger = null;
  assert.equal(resolver(ref('trigger','trigger_a'),toProject(noTrigger)),false);
});

test('A-B2-T04: real Layers callbacks cannot mutate B with callbacks from A, even for the SAME step ID', () => {
  const match = layers.match(/const render = \(\{ selection: selected, project \}\) => \{[\s\S]*?\n  \};/);
  assert.ok(match, 'test actual rendering/callback closure, not copied code');
  const events = [];
  let epoch = 0;
  let workflowId = '1';
  let selectedGroups = [];
  const selection = {
    captureOrigin: () => ({expectedEpoch:epoch,workflowId}),
    isCurrentOrigin: (origin) => origin.expectedEpoch === epoch && origin.workflowId === workflowId,
    select: (_ref, origin) => { if (!selection.isCurrentOrigin(origin)) return false; events.push('select'); return true; },
  };
  const editor = {layerAction: () => {events.push('mutate');return true;}};
  const fake = {render: (groups) => {selectedGroups = groups;}};
  const render = runInNewContext(match[0]+'\nrender;', {
    selection,editor,shell:{hasAttribute:()=>false},tree:fake,
    navigation:{},labels:{},reference:(kind,id)=>({kind:'entity',type:'automations.'+kind,id}),
  });
  const project = {root:{properties:{definition:definition()}}};
  render({selection:{primary:null},project});
  const old = selectedGroups.find((g)=>g.key==='stage:actions').children;
  const remove = old[0].actions.remove, move=old[0].reorder.onMove,select=old[0].onSelect;
  workflowId = '2';
  epoch = 1;
  assert.equal(remove(),false);
  assert.equal(move(1),false);
  assert.equal(select(),false);
  assert.deepEqual(events,[],'old workflow handlers may not touch current project');
  render({selection:{primary:null},project});
  const current=selectedGroups.find((g)=>g.key==='stage:actions').children;
  assert.equal(current[0].actions.remove(),true);
  assert.equal(current[0].reorder.onMove(1),true);
  assert.equal(current[0].onSelect(),true);
  assert.deepEqual(events,['mutate','mutate','select']);
});

test('A-B2-T04: real Canvas card interception refuses precommit and stale mutation events', () => {
  const match = editor.match(/const guardCanvasCard = \(card, type = '', id = ''\) => \{[\s\S]*?\n\t\};/);
  assert.ok(match,'real Canvas card capture fence exists');
  let epoch=0, workflowId='1';
  const window = {cbAutomationsBuilderSelection:{
    captureOrigin:()=>({expectedEpoch:epoch,workflowId}),
    isCurrentOrigin:(origin)=>origin.expectedEpoch===epoch && origin.workflowId===workflowId,
  }};
  const guard = new Function('window',match[0]+'\nreturn guardCanvasCard;')(window);
  const handlers = new Map();
  const card = {
    dataset:{},
    addEventListener(type,fn,capture){assert.equal(capture,true);handlers.set(type,fn);},
  };
  guard(card,'action','action_a');
  assert.equal(card.dataset.cbAutomationsSelectionId,'action_a');
  const event = () => ({
    blocked:false,
    preventDefault(){this.blocked=true;},
    stopImmediatePropagation(){this.blocked=true;},
  });
  let e=event();
  handlers.get('click')(e);
  assert.equal(e.blocked,false);
  workflowId='2';epoch=1;
  for(const kind of ['click','input','change','keydown']){
    e=event();handlers.get(kind)(e);
    assert.equal(e.blocked,true,'old Canvas handler must refuse '+kind);
  }
});

test('A-B2-T05: refused context commit restores workflow identity before old Canvas handlers are rebound', () => {
  const shell = source('assets/js/admin-designer-shell.js');
  assert.match(shell, /currentId\.value = previous\.id;[\s\S]{0,150}currentRevision\.value = previous\.revision;[\s\S]{0,150}session\.replace\(previous\.definition, \{ source: 'context-rollback' \}\)/);
  assert.match(shell, /JSON\.stringify\(history\.contextWitness\(\)\) === JSON\.stringify\(historyBefore\)/);
});
