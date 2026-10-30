import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {test} from 'node:test';

const template = readFileSync(new URL('../../templates/admin/advanced-workflow-editor.php',import.meta.url),'utf8');
const css = readFileSync(new URL('../../assets/css/admin-advanced-editor.css',import.meta.url),'utf8');

test('ADV-03D minimap is a sibling of viewport inside its own positioned canvas frame',()=>{
  const main = template.match(/<main class="cb-core-design-shell__canvas cb-advanced-editor-canvas"[^>]*>([\s\S]*?)<\/main>/);
  assert.ok(main,'main canvas must exist');
  const html = main[1].replace(/<\?php[\s\S]*?\?>/g,'');
  const tags = /<\/?div\b[^>]*>/g;
  const stack = [];
  const found = {};
  for(const match of html.matchAll(tags)){
    const tag = match[0];
    if(tag.startsWith('</')) {
      assert.ok(stack.length,'no unmatched closing div');
      stack.pop();
      continue;
    }
    const className = /class="([^"]+)"/.exec(tag)?.[1]||'';
    const attrs = /data-cb-[a-z0-9-]+(?:="[^"]*")?/.exec(tag)?.[0]||'';
    const classList=className.split(/\s+/);
    if(classList.includes('cb-advanced-editor-canvas-frame')) {
      assert.deepEqual(stack,[],'frame is directly inside main');
      found.frame=true;
    }
    if(tag.includes('data-cb-design-canvas-viewport')) {
      assert.deepEqual(stack,['cb-advanced-editor-canvas-frame'],
        'viewport directly inside frame and not surrounding minimap');
      found.viewport=true;
    }
    if(tag.includes('data-cb-advanced-minimap ')) {
      assert.deepEqual(stack,['cb-advanced-editor-canvas-frame'],
        'minimap is directly inside frame; never inside zoomed scene');
      found.minimap=true;
    }
    stack.push(classList.includes('cb-advanced-editor-canvas-frame')
      ? 'cb-advanced-editor-canvas-frame' : classList[0]||'div');
  }
  assert.deepEqual(stack,[],'canvas divs must be balanced');
  assert.deepEqual(found,{frame:true,viewport:true,minimap:true});
});

test('ADV-03D minimap anchors and clips to consumer frame, not Base static canvas',()=>{
  assert.match(css,/\.cb-advanced-editor-canvas-frame\s*\{[^}]*position:\s*relative;/s);
  assert.match(css,/\.cb-advanced-editor-canvas-frame\s*\{[^}]*isolation:\s*isolate;/s);
  assert.match(css,/\.cb-advanced-editor-canvas-frame\s*\{[^}]*overflow:\s*hidden;/s);
  assert.match(css,/\.cb-advanced-minimap\s*\{[^}]*position:\s*absolute;\s*right:\s*var\(--cb-space-3\);\s*top:\s*var\(--cb-space-3\)/s);
  assert.doesNotMatch(css,/\.cb-advanced-editor-canvas\s*\{[^}]*position:\s*relative;/s);
  assert.match(css,/\.cb-advanced-editor-canvas-scroll\s*\{[^}]*height:\s*100%;/s);
});
