import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const source=readFileSync(new URL('../../assets/js/admin-advanced-editor.js',import.meta.url),'utf8');
const styles=readFileSync(new URL('../../assets/css/admin-advanced-editor.css',import.meta.url),'utf8');

// Extract the pure endpoint decision from the real shipping classic-script
// renderer, to exercise actual release behavior without duplicating a planner.
const decisionStart=source.indexOf('const inputDragOutcome = ');
const decisionEnd=source.indexOf('\n\t// Selection is transient',decisionStart);
assert.ok(decisionStart>=0 && decisionEnd>decisionStart,'input drag decision exists');
const decisionBody=source.slice(decisionStart+'const inputDragOutcome = '.length,decisionEnd).trim().replace(/;$/,'');
const inputDragOutcome=Function('return ('+decisionBody+')')();

test('ADV-03F endpoint release classifier never mutates on a click, invalid drop or original input',()=>{
  const source={moved:false,inputNode:'b',inputPort:'in'};
  assert.equal(inputDragOutcome(source,null,true),'cancel');
  assert.equal(inputDragOutcome({...source,moved:true},{node:'b',port:'in'},true),'cancel');
  assert.equal(inputDragOutcome({...source,moved:true},null,false),'cancel');
});
test('ADV-03F release distinguishes rewire from blank-canvas disconnect',()=>{
  const request={moved:true,inputNode:'b',inputPort:'in'};
  assert.equal(inputDragOutcome(request,{node:'c',port:'in'},false),'rewire');
  assert.equal(inputDragOutcome(request,null,true),'disconnect');
});
test('ADV-03F drag starts only for occupied inputs and retains immutable captured edge identity',()=>{
  assert.match(source,/if \(side==='in'\) \{/);
  assert.match(source,/const edge = draft\.graph\.edges\.find\(item => item\.to === node\.id && item\.to_port === port\)/);
  assert.match(source,/if \(!edge\) return;/);
  assert.match(source,/event\.defaultPrevented \|\| pendingPort \|\| draggingPort\) return;/);
  assert.match(source,/detachedEdgeId: edge\.id/);
  assert.match(source,/control\.setPointerCapture\?\.\(event\.pointerId\)/);
  assert.match(source,/request\.moved \|\|= Math\.hypot/);
  assert.match(source,/const stillBound = draft\.graph\.edges\.some/);
  assert.match(source,/if \(!request\.moved\) selectNode\(node\.id\)/);
  assert.match(source,/edge\.id === request\.detachedEdgeId/);
  assert.match(source,/control\.addEventListener\('pointercancel', cancelInputDrag\)/);
  assert.match(source,/control\.addEventListener\('lostpointercapture', cancelInputDrag\)/);
  assert.match(source,/event\.key!=='Escape' \|\| !pendingPort && !draggingPort/);
});
test('ADV-03F transient preview hides only dragged edge, never mutates document in pointermove',()=>{
  assert.match(source,/if \(draggingPort\?\.detachedEdgeId === edge\.id && draggingPort\.moved\) continue/);
  assert.match(source,/source && \(!draggingPort\?\.detachedEdgeId \|\| draggingPort\.moved\)/);
  const inputMove=source.match(/control\.addEventListener\('pointermove', event => \{\n\t{6}if \(!draggingPort\?\.detachedEdgeId[\s\S]*?\n\t{5}\}\);/);
  assert.ok(inputMove,'connected-input move handler exists');
  assert.doesNotMatch(inputMove[0],/deleteEdge\(|connect\(|mark\(|rebuild\(/);
  assert.match(source,/canvasNavigation\.clientToCanvas\(/);
  assert.match(styles,/\.cb-advanced-node-port\s*\{[^}]*touch-action: none/s);
});
test('ADV-03F only blank viewport can delete; occupied or incompatible targets never steal',()=>{
  assert.match(source,/viewport\?\.contains\(target\)/);
  assert.match(source,/!target\.closest\?\.\('\[data-cb-design-canvas-object\], \[data-cb-advanced-edge-id\], \[data-cb-advanced-port-side\]'\)/);
  assert.match(source,/outcome === 'rewire' && canConnect\(/);
  assert.match(source,/outcome === 'disconnect'\) \{\s*deleteEdge\(request\.detachedEdgeId\)/);
  assert.match(source,/connect\(request\.id, request\.port, targetPort\.node, targetPort\.port, true\)/);
  assert.match(source,/const old = draft\.graph\.edges\[index\]/);
  assert.match(source,/draft\.graph\.edges\[index\] = \{\.\.\.old, to, to_port: toPort\}/);
  assert.match(source,/const deleteEdge = id =>/);
  assert.match(source,/mark\(\); rebuild\(\)/);
});

test('ADV-03F uses one authoritative Base History mutation for rewire and disconnect',()=>{
  const start=source.indexOf('const connect = ');
  const end=source.indexOf('const addNode = ',start);
  assert.ok(start>=0 && end>start);
  const realOperations=source.slice(start,end);
  const draft={graph:{edges:[{id:'edge-1',from:'a',from_port:'next',to:'b',to_port:'in'}]}};
  let steps=0,rebuilds=0;
  const operations=Function('draft','canConnect','uid','mark','rebuild','announceConnection',
    'let pendingPort=null;let selectedEdge=null;\n'+realOperations+'\nreturn {connect,deleteEdge};'
  )(draft,()=>true,()=>{throw Error('Unnecessary new edge identity')},
    ()=>{steps++;},()=>{rebuilds++;},()=>{});
  assert.equal(operations.connect('a','next','c','in',true),true);
  assert.deepEqual(draft.graph.edges,[{id:'edge-1',from:'a',from_port:'next',to:'c',to_port:'in'}]);
  assert.equal(steps,1,'rewire is exactly one History step');
  assert.equal(rebuilds,1);
  operations.deleteEdge('edge-1');
  assert.deepEqual(draft.graph.edges,[]);
  assert.equal(steps,2,'disconnect is exactly one further History step');
  operations.deleteEdge('edge-1');
  assert.equal(steps,2,'absent edge is never recorded');
});
