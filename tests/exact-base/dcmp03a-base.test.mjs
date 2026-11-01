import assert from 'node:assert/strict';
import { cp, mkdtemp, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { execFileSync } from 'node:child_process';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { pathToFileURL } from 'node:url';
import { before, after, test } from 'node:test';

const REQUIRED_BASE = '1c23b41b8e893f25e854f101e5ee0be44e707492';
const base = process.env.CB_BASE_DESIGNER_EXACT_REPO;
if (!base) throw new Error('Set CB_BASE_DESIGNER_EXACT_REPO to the frozen Base checkout.');
const head = execFileSync('git', ['-C', base, 'rev-parse', 'HEAD'], { encoding: 'utf8' }).trim();
assert.equal(head, REQUIRED_BASE, 'Exact Base SOURCE commit must match Overseer DCMP-03.');
const read = path => readFile(join(base, path), 'utf8');

let temp;
let editor;
const convertToModules = async directory => {
  for (const entry of await readdir(directory, { withFileTypes: true })) {
    const file = join(directory, entry.name);
    if (entry.isDirectory()) { await convertToModules(file); continue; }
    if (!entry.isFile() || !file.endsWith('.js')) continue;
    let source = await readFile(file, 'utf8');
    source = source.replaceAll(/(['"])(\.\.?\/[^'"]+)\.js\1/g, '$1$2.mjs$1');
    if (entry.name === 'editor.js') {
      source = source.replace("'@cb-core/design-motion'", "'./core/motion.mjs'");
    }
    await writeFile(file.replace(/\.js$/, '.mjs'), source);
  }
};

before(async () => {
  temp = await mkdtemp(join(tmpdir(), 'cb-dcmp03a-exact-base-'));
  const design = join(temp, 'design');
  await cp(join(base, 'assets/js/design'), design, { recursive: true });
  await convertToModules(design);
  globalThis.window = {};
  editor = await import(pathToFileURL(join(design, 'editor.mjs')).href);
});
after(async () => {
  delete globalThis.window;
  if (temp) await rm(temp, { recursive: true, force: true });
});

test('DCMP-03A: exact Base PHP capability and v3 synchronous Discard registration', async () => {
  const php = await read('src/Design/Editor/Assets.php');
  const exit = await read('assets/js/features/designer-exit-guard.js');
  const launch = await read('assets/js/features/designer-launch.js');
  for (const [api, version] of [
    ['DOMAIN_SESSION_API', 3],
    ['DESIGNER_EXIT_GUARD_API', 3],
    ['CANVAS_NAVIGATION_API', 2],
    ['DESIGNER_CONTEXT_MENU_API', 1],
  ]) {
    assert.match(php, new RegExp('public const ' + api + ' = ' + version + ';'));
  }
  assert.match(exit, /typeof adapter\.discard !== 'function'/);
  assert.match(exit, /adapter\.discard\(\) !== true/);
  assert.match(exit, /adapter\.isDirty\(\) !== false/);
  assert.match(launch, /enhanceHeaderContext/);
});

test('DCMP-03A: real public Base ESM resolves all required Standard and Advanced exports', () => {
  for (const name of [
    'createDesignerShell', 'configureDesignerSidebar', 'createDesignerNavigation',
    'createDesignerLayerTree', 'createDesignerSelectionController',
    'createDomainSession', 'createDesignerCanvasNavigation',
    'createDesignerContextMenu', 'animateLayoutChange',
  ]) {
    assert.equal(typeof editor[name], 'function', name);
  }
  assert.equal(typeof globalThis.window.cbCore?.designEditor?.createDomainSession, 'function');
  assert.equal(typeof globalThis.window.cbCore?.designEditor?.shell?.contextMenu?.create, 'function');
});

test('DCMP-03A: real Base Discard commits one transaction and publishes discard source', () => {
  const project = {
    schema_version: 0,
    design_type: 'automations-advanced-graph',
    root: {
      definition: { definition_version: 2, mode: 'advanced',
        graph: { nodes: [], edges: [] }, layout: { nodes: {} } },
      name: 'Accepted',
    },
  };
  const events = [];
  const session = editor.createDomainSession({
    project,
    validateProject: value => {
      assert.equal(value.schema_version, 0);
      assert.equal(value.root.definition.definition_version, 2);
    },
    onChange: (_, event) => { events.push(event.source); },
  });
  try {
    session.execute({
      label: 'Edit Advanced workflow',
      apply: current => ({ project: {
        ...current, root: { ...current.root, name: 'Unsaved' },
      } }),
    });
    assert.equal(session.snapshot().root.name, 'Unsaved');
    assert.equal(session.historyStatus().canUndo, true);
    assert.equal(session.discardToBaseline(), true);
    assert.equal(events.at(-1), 'discard');
    assert.equal(session.snapshot().root.name, 'Accepted');
    assert.deepEqual(session.snapshot(), session.baselineSnapshot());
    const history = session.historyStatus();
    assert.equal(history.size, 0);
    assert.equal(history.canUndo, false);
    assert.equal(history.canRedo, false);
    const selection = session.selection();
    assert.deepEqual(selection.refs, []);
    assert.equal(selection.primary, null);
  } finally {
    session.dispose();
  }
});
