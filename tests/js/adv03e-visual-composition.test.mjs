import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const template=readFileSync(new URL('../../templates/admin/advanced-workflow-editor.php',import.meta.url),'utf8');
const css=readFileSync(new URL('../../assets/css/admin-advanced-editor.css',import.meta.url),'utf8');

const sidebar=(name)=> {
  const start=template.indexOf('<aside class="cb-core-design-shell__'+name);
  assert.ok(start>=0, name+' exists');
  const end=template.indexOf('</aside>',start);
  assert.ok(end>start,name+' closes');
  return template.slice(start,end);
};

test('ADV-03E both sidebars adopt exactly one canonical Base panel + panel body',()=>{
  for(const [name,role] of [['palette','palette'],['sidebar','inspector']]){
    const html=sidebar(name);
    const panelType=role==='palette'
      ? 'cb-advanced-editor-palette-panel' : 'cb-advanced-editor-inspector-panel';
    assert.match(html,new RegExp('class="cb-core-design-shell__panel[^"]*'+panelType+'"'));
    assert.equal((html.match(/class="cb-core-design-shell__panel-body"/g)||[]).length,1);
    assert.equal((html.match(/cb-core-design-shell__panel-header/g)||[]).length,0,
      'Base must inject its own header');
    const panel=html.indexOf(panelType),body=html.indexOf('cb-core-design-shell__panel-body');
    assert.ok(body>panel,'content inset inside panel');
    assert.match(html,/class="cb-core-design-shell__panel-section/);
    assert.equal((html.match(/cb-core-design-shell__tabs/g)||[]).length,0,
      'no decorative tabs without product necessity');
  }
});

test('ADV-03E all existing graph and Inspector interaction hooks survive',()=>{
  for(const marker of [
    'data-cb-advanced-add="trigger"','data-cb-advanced-add="state"',
    'data-cb-advanced-add="if_else"','data-cb-advanced-add="action"',
    'data-cb-advanced-add="exclusive_merge"','data-cb-advanced-add="end"',
    'data-cb-advanced-inspector','data-cb-advanced-dirty',
    'data-cb-advanced-preview-run','data-cb-advanced-preview-fixtures',
    'data-cb-advanced-preview-nonce','data-cb-advanced-preview-result',
    'cb-advanced-editor-validation','data-cb-design-canvas-viewport',
    'data-cb-advanced-minimap','data-cb-advanced-save'
  ]) assert.ok(template.includes(marker),marker);
  assert.match(sidebar('sidebar'),/data-cb-advanced-inspector tabindex="-1"/);
  assert.match(sidebar('sidebar'),/name="name"/);
});

test('ADV-03E Base owns shell layout and single panel content inset',()=>{
  assert.doesNotMatch(css,/\.cb-advanced-editor-palette,\s*\.cb-advanced-editor-inspector\s*\{/);
  assert.doesNotMatch(css,/\.cb-advanced-editor-workspace\s*\{\s*display:\s*grid/);
  assert.doesNotMatch(css,/\.cb-advanced-editor-palette\s*\{[^}]*padding:/s);
  assert.doesNotMatch(css,/\.cb-advanced-editor-inspector\s*\{[^}]*overflow:\s*auto/s);
  assert.match(css,/\.cb-advanced-editor-inspector-panel\s*\{/);
  assert.match(css,/\.cb-advanced-editor-palette-panel,/);
});
