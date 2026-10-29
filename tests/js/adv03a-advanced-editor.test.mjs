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
  assert.match(source, /nodes: new Set\(json\.data\.nodes\)/);
  assert.match(source, /edges: tracedEdges\(json\.data\.nodes, decisions\)/);
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

// Behavior regression: only the chosen branch's actual edge IDs may light up.
test('ADV-03B route preview highlights exact consecutive graph transitions', () => {
  const match = source.match(/const tracedEdges = \(nodes, decisions\) => \{([\s\S]*?)\n\t\};\n\tconst renderEdges/);
  assert.ok(match, 'Route projector source is available');
  const edges = [
    {id:'t',from:'trigger',to:'choice',from_port:'next'},
    {id:'yes',from:'choice',to:'yesNode',from_port:'true'},
    {id:'no',from:'choice',to:'noNode',from_port:'false'},
    {id:'ymerge',from:'yesNode',to:'merge',from_port:'next'},
    {id:'nmerge',from:'noNode',to:'merge',from_port:'next'},
    {id:'end',from:'merge',to:'end',from_port:'next'},
    {id:'shortcut',from:'trigger',to:'merge',from_port:'unused'},
  ];
  const nodeSet=['trigger','choice','yesNode','noNode','merge','end']
    .map(id=>({id,type:id==='choice'?'if_else':'action'}));
  const draft={graph:{edges}};
  const find=id=>nodeSet.find(n=>n.id===id);
  const trace=new Function('draft','find','nodes','decisions',match[1]);
  const yes=trace(draft,find,['trigger','choice','yesNode','merge','end'],{choice:true});
  const no=trace(draft,find,['trigger','choice','noNode','merge','end'],{choice:false});
  assert.deepEqual([...yes].sort(),['t','yes','ymerge','end'].sort());
  assert.deepEqual([...no].sort(),['t','no','nmerge','end'].sort());
  assert.ok(!yes.has('shortcut')&&!no.has('shortcut'));
});
test('ADV-03A pointer cancellation rolls back instead of dirtying history', () => {
  assert.match(source,/if \(e\.type === 'pointercancel'\) \{[\s\S]*?point\.x = origin\.left; point\.y = origin\.top;/);
  assert.match(source,/if \(e\.pointerId !== event\.pointerId\) return;/);
});
test('ADV-03C presentation CSS contains no JavaScript comments', () => {
  assert.doesNotMatch(stylesheet,/^\s*\/\/.*$/m);
});

test('ADV-03B discards late preview responses after graph edit or undo', () => {
  assert.match(source, /let previewEpoch = 0/);
  assert.match(source, /const requestEpoch = previewEpoch/);
  assert.match(source, /if \(requestEpoch !== previewEpoch \|\| changed\)/);
  assert.match(source, /previewEpoch\+\+/);
  assert.match(source, /Workflow changed while testing/);
});

test('ADV-03B fixture input and presets invalidate pending requests without dirtying a workflow', () => {
  assert.match(source, /const invalidateSamplePreview = \(\) => \{/);
  assert.match(source, /previewFixture\?\.addEventListener\('input', invalidateSamplePreview\)/);
  assert.match(source, /previewFixture\.value = JSON\.stringify\(samples, null, 2\);\n\t\tinvalidateSamplePreview\(\)/);
  assert.match(source, /if \(expert\) expert\.open = true/);
  const block = source.split('const invalidateSamplePreview = () => {')[1].split('\n\t};')[0];
  assert.doesNotMatch(block, /mark\(\)|historySession\.execute|draft\./);
});

test('ADV-03 R4 keyboard and pointer connection gestures do not race', () => {
  assert.match(source, /const isKeyboardPortClick = event => event\.detail === 0/);
  const keyboardClick = new Function('event', 'return event.detail === 0;');
  assert.ok(keyboardClick({detail:0}));
  assert.ok(!keyboardClick({detail:1}));
  assert.doesNotMatch(source, /suppressPortClick|setTimeout\(\(\)=>\{suppressPortClick/);
  assert.match(source, /if \(isKeyboardPortClick\(event\)\) armPort\(node\.id,port\)/);
  assert.match(source, /control\.addEventListener\('pointerup'/);
});
test('ADV-03 R4 typed graph connection validation refuses occupancy and cycles', () => {
  const found = source.match(/const canConnect = \(from, fromPort, to, toPort, allowRewire = false\) => \{([\s\S]*?)\n\t\};\n\tconst connect/);
  assert.ok(found, 'real graph connection policy found');
  const nodes = ['trigger','actionA','actionB','end'].map(id =>
    ({id,type:id==='trigger'?'trigger':id==='end'?'end':'action'}));
  const draft={graph:{edges:[]}};
  const find=id=>nodes.find(n=>n.id===id);
  const outgoing=type=>type==='end'?[]:type==='if_else'?['true','false']:['next'];
  const incoming=type=>type==='trigger'?[]:type==='exclusive_merge'?['true','false']:['in'];
  const canConnect = new Function('find','draft','outgoing','incoming',
    'return (from,fromPort,to,toPort,allowRewire=false)=> {'+found[1]+'};')(find,draft,outgoing,incoming);
  assert.ok(canConnect('trigger','next','actionA','in'));
  draft.graph.edges.push({from:'trigger',from_port:'next',to:'actionA',to_port:'in'});
  assert.ok(!canConnect('trigger','next','actionB','in'), 'occupied output');
  assert.ok(!canConnect('actionB','next','actionA','in'), 'occupied input');
  assert.ok(canConnect('actionA','next','actionB','in'));
  assert.ok(!canConnect('end','next','actionA','in'));
  assert.ok(!canConnect('actionB','next','trigger','in'));
  draft.graph.edges=[{from:'actionA',from_port:'next',to:'actionB',to_port:'in'}];
  assert.ok(!canConnect('actionB','next','actionA','in'), 'cycle even when input is free');
  assert.ok(!canConnect('actionB','next','actionB','in'), 'self link');
});
test('ADV-03 R4 port status is accessible without browser alerts', () => {
  assert.match(advancedTemplate,/id="cb-advanced-connection-status"/);
  assert.match(advancedTemplate,/data-cb-advanced-connection-status role="status" aria-live="polite"/);
  assert.match(source,/aria-describedby', 'cb-advanced-connection-status'/);
  assert.match(source,/announceConnection\('Choose a highlighted input port/);
  assert.match(source,/cancelPort\('Connection cancelled\.'/);
  const portSection=source.split('const createPorts = (card, node) => {')[1].split('const draw = () => {')[0];
  assert.doesNotMatch(portSection,/window\.alert/);
});

test('ADV-03 R4 rewires connected outputs without stealing inputs', () => {
  const captured=source.match(/const canConnect = \(from, fromPort, to, toPort, allowRewire = false\) => \{([\s\S]*?)\n\t\};\n\tconst connect/);
  assert.ok(captured);
  const nodes=['trigger','a','b','end'].map(id=>({id,type:id==='trigger'?'trigger':id==='end'?'end':'action'}));
  const find=id=>nodes.find(n=>n.id===id);
  const draft={graph:{edges:[{id:'original',from:'trigger',from_port:'next',to:'a',to_port:'in'}]}};
  const outgoing=type=>type==='end'?[]:['next'];
  const incoming=type=>type==='trigger'?[]:['in'];
  const check=new Function('find','draft','outgoing','incoming',
    'return (from,fromPort,to,toPort,allowRewire=false)=> {'+captured[1]+'};')(find,draft,outgoing,incoming);
  assert.ok(!check('trigger','next','b','in'));
  assert.ok(check('trigger','next','b','in',true));
  assert.ok(!check('trigger','next','a','in',true));
  draft.graph.edges.push({id:'occupied',from:'a',from_port:'next',to:'end',to_port:'in'});
  assert.ok(!check('trigger','next','end','in',true));
  assert.match(source,/draft\.graph\.edges\[index\] = \{\.\.\.old, to, to_port: toPort\}/);
  assert.match(source,/connect\(request\.id,request\.port,target\.dataset\.cbAdvancedPortNode,target\.dataset\.cbAdvancedPort,true\)/);
});
test('ADV-03 R4 link selection retains keyboard focus and pressed state', () => {
  assert.match(source,/hit\.setAttribute\('aria-pressed', selectedEdge === edge\.id \? 'true' : 'false'\)/);
  assert.match(source,/hit\.addEventListener\('click', selectConnection\)/);
  const body=source.split('const selectConnection = () => {')[1].split('\n\t\t\t};')[0];
  assert.ok(body);
  assert.doesNotMatch(body,/renderEdges\(\)|replaceChildren/);
  assert.match(body,/target\.setAttribute\('aria-pressed'/);
});

test('ADV-03C keyboard node movement shares bounded pointer layout and preserves focus', () => {
  const snippet = source.match(/const moveNodeBy = \(point, dx, dy\) => \{([\s\S]*?)\n\t\};\n\tconst contentBounds/);
  assert.ok(snippet, 'shared node coordinate policy exists');
  const move = new Function('return (point,dx,dy) => {'+snippet[1]+'};')();
  const point={x:45,y:80};
  assert.ok(move(point,1,0));
  assert.deepEqual(point,{x:46,y:80});
  assert.ok(move(point,-2000,-2000));
  assert.deepEqual(point,{x:0,y:0});
  assert.ok(!move(point,-1,0), 'no History entry when clamped at boundary');
  assert.ok(move(point,10000,10000));
  assert.deepEqual(point,{x:1216,y:790});
  assert.match(source,/title\.setAttribute\('aria-keyshortcuts'/);
  assert.match(source,/event\.shiftKey \? 10 : 1/);
  assert.match(source,/title\.addEventListener\('keydown', event =>/);
  const selectBlock=source.split('const selectNode = id => {')[1].split('\n\t};')[0];
  assert.ok(selectBlock);
  assert.doesNotMatch(selectBlock,/\brebuild\(\)|layer\.replaceChildren/);
  assert.match(selectBlock,/renderEdges\(\); inspect\(\)/);
});

test('ADV-03C selected node and edge state stays accurate without replacing focus', () => {
  assert.match(source,/const selectNode = id => \{/);
  assert.match(source,/else selectNode\(node\.id\)/);
  assert.match(source,/const selectCard = \(\) => selectNode\(node\.id\)/);
  const selected=source.split('const selectNode = id => {')[1].split('\n\t};')[0];
  assert.doesNotMatch(selected,/rebuild\(\)|layer\.replaceChildren/);
  assert.match(selected,/element\.classList\.toggle\('is-selected'/);
  const edgeSelect=source.split('const selectConnection = () => {')[1].split('\n\t\t\t};')[0];
  assert.match(edgeSelect,/card\.classList\.remove\('is-selected'\)/);
});

test('ADV-03B-R2 status projection uses only allowlisted server metadata', () => {
  assert.match(source, /const testStatusLabels = Object\.freeze/);
  assert.match(source, /const testSummary = node =>/);
  assert.match(source, /inspector\.append\(summary\)/);
  assert.match(source, /results\[node\.id\] = \{status:result\.status,reason:result\.reason,fixture:result\.fixture\}/);
  assert.match(source, /Simulation incomplete\. No actions were executed/);
  assert.match(stylesheet, /\.cb-advanced-node\.is-test-blocked/);
  assert.match(stylesheet, /\.cb-advanced-node\.is-test-failed/);
});

test('ADV-03B-R2 refreshes Inspector and canvas together after new/invalidated simulation', () => {
  assert.match(source, /decisions, results, complete: json\.data\.complete,[\s\S]*?draw\(\); inspect\(\);/);
  assert.match(source, /const invalidateSamplePreview = \(\) => \{[\s\S]*?simulatedRoute = null;\n\t\t\tdraw\(\); inspect\(\);/);
});
