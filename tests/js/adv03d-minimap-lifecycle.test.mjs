import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {test} from 'node:test';

const source = readFileSync(new URL('../../assets/js/admin-advanced-minimap.js', import.meta.url), 'utf8');
const {createAutomationsGraphMinimap} = await import(
  'data:text/javascript;charset=utf-8,' + encodeURIComponent(source)
);

const createMockElement = () => {
  const listeners = new Map(), attr = Object.create(null), classes = new Set();
  return {
    listeners, attr, children: [], dataset: {},
    classList: {
      toggle(name,value){ if (value) classes.add(name); else classes.delete(name); },
      contains(name){ return classes.has(name); },
    },
    setAttribute(name,value){ attr[name] = String(value); },
    addEventListener(name,fn){ listeners.set(name,fn); },
    removeEventListener(name,fn){ if (listeners.get(name) === fn) listeners.delete(name); },
    replaceChildren(...children){ this.children = children; },
    getBoundingClientRect(){ return {left:0,top:0,width:200,height:150}; },
    setPointerCapture(){},
    hasPointerCapture(){ return false; },
  };
};

const harness = () => {
  const callbacks = [], pan = [];
  const win = {
    requestAnimationFrame(callback) {
      assert.equal(this,win,'requestAnimationFrame must retain its Window receiver');
      callbacks.push(callback);
    },
    matchMedia:() => ({matches:false}),
  };
  const doc = {
    defaultView:win,
    createElementNS(_namespace,_kind){ return createMockElement(); },
  };
  const container=createMockElement(),svg=createMockElement(),body=createMockElement();
  const toggle=createMockElement(),size=createMockElement();
  svg.ownerDocument=doc;
  const map={
    '[data-cb-advanced-minimap]':container,
    '[data-cb-advanced-minimap-svg]':svg,
    '[data-cb-advanced-minimap-body]':body,
    '[data-cb-advanced-minimap-toggle]':toggle,
    '[data-cb-advanced-minimap-size]':size,
  };
  container.querySelector=s=>map[s];
  const root=createMockElement();
  root.isConnected=true;
  root.hidden=false;
  root.querySelector=s=>map[s];
  const viewport={clientWidth:800,clientHeight:500};
  const graph={
    nodes:[{id:'trigger',type:'trigger',x:-500,y:-300},{id:'end',type:'end',x:1800,y:1300}],
    edges:[{from:'trigger',to:'end'}],
  };
  let disposed=false;
  const navigation={
    getView(){
      if (disposed) throw Error('Base navigation already disposed');
      return {x:120,y:-80,scale:1.5,scrollLeft:0,scrollTop:0};
    },
    panBy(dx,dy){pan.push({dx,dy});return true;},
  };
  const ctrl=createAutomationsGraphMinimap({
    root,viewport,navigation,getGraphSnapshot:()=>graph,
  });
  return {ctrl, callbacks, pan, svg, root, graph,
    flush(){ assert.ok(callbacks.length,'expected queued animation frame');callbacks.shift()(); },
    disposeBase(){disposed=true;},
  };
};

test('minimap lifecycle invokes browser RAF as method and renders non-mutating graph projection',()=>{
  const t=harness();
  const prior=JSON.stringify(t.graph);
  t.flush();
  assert.equal(t.svg.children.length,4);
  assert.equal(t.svg.children.at(-1).attr.class,'cb-minimap-viewport');
  assert.equal(JSON.stringify(t.graph),prior,'rendering must not change domain graph');
  t.ctrl.destroy();
  assert.equal(JSON.stringify(t.graph),prior);
});

test('minimap pointer drag pans only through Base and disposes safely with pending frame',()=>{
  const t=harness();
  t.flush();
  const viewportRect=t.svg.children.at(-1);
  const down=t.svg.listeners.get('pointerdown');
  const move=t.svg.listeners.get('pointermove');
  const up=t.svg.listeners.get('pointerup');
  assert.equal(typeof down,'function');
  let prevented=0;
  down({button:0,target:{classList:{contains:s=>s==='cb-minimap-viewport'}},
    pointerId:42,clientX:100,clientY:80,preventDefault(){prevented++;}});
  move({pointerId:42,clientX:120,clientY:90});
  assert.equal(prevented,1);
  assert.equal(t.pan.length,1);
  assert.ok(t.pan[0].dx<0 && t.pan[0].dy<0);
  up({pointerId:42});
  t.ctrl.render();
  t.disposeBase();
  t.ctrl.destroy();
  t.flush(); // A canceled frame must not read a destroyed Base controller.
  assert.equal(viewportRect.attr.class,'cb-minimap-viewport');
});

test('minimap click re-centers through Base; graph content never changes',()=>{
  const t=harness();
  t.flush();
  const before=JSON.stringify(t.graph);
  t.svg.listeners.get('pointerdown')({
    button:0,target:{classList:{contains:()=>false}},pointerId:7,
    clientX:75,clientY:52,preventDefault(){},
  });
  assert.equal(t.pan.length,1);
  assert.equal(JSON.stringify(t.graph),before);
  t.ctrl.destroy();
});
