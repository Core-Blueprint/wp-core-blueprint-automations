import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const source = path => readFileSync(new URL(path, import.meta.url),'utf8');
const minimap = source('../../assets/js/admin-advanced-minimap.js');
const adapter = source('../../assets/js/admin-advanced-designer-shell.js');
const graph = source('../../assets/js/admin-advanced-editor.js');
const template = source('../../templates/admin/advanced-workflow-editor.php');
const php = source('../../src/Admin/DesignerAssets.php');
const css = source('../../assets/css/admin-advanced-editor.css');

// Use isolated ESM without relying on Node's package-wide module mode.
const {worldViewport,minimapBounds} = await import(
  'data:text/javascript;charset=utf-8,'+encodeURIComponent(minimap)
);

test('ADV-03D viewport projection handles signed world positions and zoom',()=>{
  assert.deepEqual(worldViewport(
    {x:200,y:-80,scale:2,scrollLeft:0,scrollTop:0},1000,600
  ),{x:-100,y:40,width:500,height:300});
  const bounds=minimapBounds([
    {id:'left',x:-6000,y:-3500},{id:'right',x:7000,y:4400}
  ],{x:-100,y:40,width:500,height:300});
  assert.deepEqual(bounds,{x:-6120,y:-3620,width:13424,height:8250});
  assert.ok(bounds.width>13000);
});
test('ADV-03D uses one public Base navigation controller with cursor-zoom',()=>{
  assert.match(php,/CANVAS_NAVIGATION_API' \) >= 2/);
  assert.match(adapter,/typeof canvasNavigation\.zoomAt !== 'function'/);
  assert.match(adapter,/typeof canvasNavigation\.zoomBy !== 'function'/);
  assert.match(adapter,/canvasNavigation\?\.zoomBy\(\{/);
  assert.match(adapter,/Math\.exp\(-dy \* 0\.002\)/);
  assert.match(adapter,/clientX: event\.clientX, clientY: event\.clientY/);
  assert.match(adapter,/event\.preventDefault\(\)/);
  assert.match(adapter,/event\.ctrlKey \|\| event\.metaKey/);
  assert.match(adapter,/canvasNavigation\?\.zoomAt\(\{scale:1\}\)/);
  assert.match(adapter,/minimap = createAutomationsGraphMinimap\(/);
  assert.doesNotMatch(adapter,/scene\.style\.transform\s*=/);
});
test('ADV-03D minimap renders graph snapshots, not persisted camera or History',()=>{
  assert.match(graph,/getMinimapGraph\(\)/);
  assert.match(graph,/cb:automations-advanced:graphchange/);
  assert.match(minimap,/svg\.replaceChildren\(\.\.\.children\)/);
  assert.match(minimap,/navigation\.panBy\(dx,dy\)/);
  assert.match(minimap,/navigation\.panBy\(-dx,-dy\)/);
  assert.match(minimap,/frozenBounds = lastBounds/);
  assert.match(minimap,/sessionStorage\.setItem\(preferenceKey/);
  assert.doesNotMatch(minimap,/historySession|documentField|save_workflow|draft\.layout/);
  assert.match(template,/data-cb-advanced-minimap-svg tabindex="0"/);
  assert.match(template,/data-cb-advanced-minimap-toggle aria-expanded="true"/);
  assert.match(css,/\.cb-advanced-minimap\.is-large/);
  assert.match(css,/@media \(max-width: 760px\)/);
});
test('ADV-03D canvas zoom and minimap do not affect Standard v1 UI',()=>{
  assert.match(template,/data-cb-advanced-zoom-out/);
  assert.match(template,/data-cb-advanced-zoom-reset/);
  assert.match(template,/data-cb-advanced-zoom-in/);
  assert.match(template,/data-cb-advanced-fit/);
  assert.match(graph,/getHistoryProject: historyProject/);
  assert.match(adapter,/historySession = createDomainSession/);
});
