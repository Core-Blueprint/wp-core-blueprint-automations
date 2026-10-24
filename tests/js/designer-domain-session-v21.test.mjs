import assert from 'node:assert/strict';
import test from 'node:test';
import { readFile } from 'node:fs/promises';
import { existsSync, readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { join } from 'node:path';
import { toProject, fromProject, workflowCommands } from '../../assets/js/automations-domain-project.js';

// Runtime SOURCE pin: merged Base main Host Registry guard (not an installed ZIP receipt).
// Cross-repo execution evidence must cite this exact immutable Base commit.
const BASE_DESIGNER_V2_SOURCE_SHA = '5e9d59a2f7ffe384873a6c0650822bbb0e7eb557';
const read = (file) => readFile(new URL('../../' + file, import.meta.url), 'utf8');
const definition = () => ({
  definition_version: 1, trigger: null, states: [], conditions: [],
  actions: [{ step_id: 'action_1', capability: {
    kind: 'action', provider: 'core-blueprint-work', id: 'action.run', schema_version: '1',
  }, bindings: { label: { source: 'literal', value: 'original' } } }],
});

test('G1a-V21-A01: Base owns the only history/session, with public v2 save checkpoints', async () => {
  const history = await read('assets/js/admin-builder-history.js');
  assert.match(history, /import\s*\{[^}]*createDomainSession[^}]*\}\s*from\s*['"]@cb-core\/design-editor['"]/s);
  assert.match(history, /session\.execute\(/);
  assert.match(history, /session\.subscribeHistory\(/);
  assert.match(history, /session\.captureSave\(/);
  assert.match(history, /session\.rebase\(/);
  assert.match(history, /session\.replace\(/);
  assert.match(history, /workflowCommands\.editorChange\(/);
  assert.doesNotMatch(history, /\bcreateDesignerStateHistory\b|\bCommandHistory\b|\bProjectState\b|\bEditorState\b/);
  assert.doesNotMatch(history, /\.record\(|\.history\.undo\(|\.history\.redo\(/);
});

test('G1a-V21-A02: each authoring event is an immutable checked domain command', () => {
  const before = definition();
  const after = structuredClone(before);
  after.actions[0].bindings.label.value = 'updated';
  const command = workflowCommands.editorChange({ expected: before, next: after });
  const base = toProject(before);
  const updated = command.apply(base).project;
  assert.deepEqual(fromProject(updated), after);
  assert.deepEqual(fromProject(base), before);
  assert.throws(() => command.apply(toProject(after)), /conflict/i);
  after.actions[0].bindings.label.value = 'mutated later';
  assert.equal(fromProject(command.apply(base).project).actions[0].bindings.label.value, 'updated');
  assert.ok(Object.isFrozen(updated));
});

test('G1a-V21-A03: save never refreshes on ambiguous responses and keeps the local draft on conflicts', async () => {
  const save = await read('assets/js/admin-builder-save.js');
  assert.match(save, /captureSave\(/);
  assert.match(save, /acknowledgeSave\(/);
  assert.match(save, /409/);
  assert.doesNotMatch(save, /window\.location\.reload\(\)/);
  assert.match(save, /currentDirty/);
});

test('G1a-V21-A04: only same-build advertised Domain Session v2 enables Designer', async () => {
  assert.equal(BASE_DESIGNER_V2_SOURCE_SHA, '5e9d59a2f7ffe384873a6c0650822bbb0e7eb557');
  const assets = await read('src/Admin/DesignerAssets.php');
  assert.match(assets, /DOMAIN_SESSION_API/);
  assert.match(assets, />=\s*2/);
  assert.match(assets, /wp_enqueue_script_module\(\s*self::HISTORY_MODULE/);
});

test('G1a-V21-A05: underlying privacy and persistence guarantees are not bypassed', async () => {
  const controller = await read('src/Admin/WorkflowController.php');
  const service = await read('src/Workflow/WorkflowService.php');
  assert.match(controller, /self::guard\( 'cb_automations_save_workflow' \)/);
  assert.match(service, /EditorSaveBoundary::reconcile/);
  assert.match(service, /WorkflowRepository::update/);
  assert.match(service, /EditorIntegerSafety::is_browser_safe/);
  assert.match(service, /ExecutionPrincipalPolicy::evaluate/);
});

test('G1a-V21-A06: workspace, folders, templates and import are preserved', async () => {
  const page = await read('templates/admin/automations.php');
  const controller = await read('src/Admin/WorkflowController.php');
  const transfer = await read('src/Admin/WorkflowTransferController.php');
  assert.match(page, /workflows|folders/);
  assert.match(controller, /create_from_template/);
  assert.match(transfer, /function confirm\(/);
  assert.match(transfer, /function export\(/);
});


test('G1a-V21-A07: actual pinned Base ESM exports match the consumer named imports', () => {
  const historySource = readFileSync(new URL('../../assets/js/admin-builder-history.js', import.meta.url), 'utf8');
  const matches = historySource.match(
    /import\s*\{([^}]+)\}\s*from\s*['"]@cb-core\/design-editor['"]/s,
  );
  assert.ok(matches, 'Designer must import the public Base module');
  const consumerNames = matches[1].split(',').map((name) => name.trim()).filter(Boolean);
  assert.deepEqual(consumerNames, ['createDomainSession', 'animateLayoutChange']);
  assert.match(historySource, /\banimateLayoutChange\(api\.root,/);
  assert.doesNotMatch(historySource, /\bmotion\.animateLayoutChange\(/);

  // Verify the ESM namespace of the real Base source, not an Automations-side
  // export fixture or a source-string regex. Require the immutable Base SHA.
  const baseRepo = process.env.CB_BASE_DESIGNER_REPO
    || fileURLToPath(new URL('../../../wp-core-blueprint/', import.meta.url));
  assert.ok(existsSync(join(baseRepo, '.git')),
    'Pinned Base v2 checkout is required for this gate: set CB_BASE_DESIGNER_REPO (no skipped export checks).');
  let baseSource;
  try {
    baseSource = execFileSync('git', [
      '-C', baseRepo,
      'show', BASE_DESIGNER_V2_SOURCE_SHA + ':assets/js/design/editor.js',
    ], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  } catch {
    assert.fail('Pinned Base v2 SHA is unavailable: fetch Base G1d-B before claiming export parity.');
  }

  // Compile/link the untouched pinned Base entry as a real ESM module. The
  // imported private dependencies are synthetic only because evaluating Base
  // would require a WordPress browser; ESM export-name resolution is native.
  const checkExports = [
    "import { SourceTextModule, SyntheticModule } from 'node:vm';",
    "let src = '';",
    "for await (const part of process.stdin) src += part;",
    "const bindings = new Map();",
    "const importPattern = /import\\s+(\\{[\\s\\S]*?\\}|\\*\\s+as\\s+[\\w$]+)\\s+from\\s+['\\\"]([^'\\\"]+)['\\\"]\\s*;/g;",
    "for (const match of src.matchAll(importPattern)) {",
    "  const names = match[1].startsWith('{') ? match[1].slice(1, -1).split(',').map(s => s.trim().split(/\\s+as\\s+/)[0]).filter(Boolean) : ['__namespace'];",
    "  const have = bindings.get(match[2]) || new Set();",
    "  for (const name of names) have.add(name);",
    "  bindings.set(match[2], have);",
    "}",
    "const entry = new SourceTextModule(src, { identifier: 'pinned-base-v2-public-editor.js' });",
    "await entry.link(async specifier => new SyntheticModule([...bindings.get(specifier) || ['__namespace']], function () {}, { identifier: specifier }));",
    "process.stdout.write(JSON.stringify(Reflect.ownKeys(entry.namespace).filter(x => typeof x === 'string')));",
  ].join('\n');
  const output = execFileSync(process.execPath, [
    '--no-warnings', '--experimental-vm-modules', '--input-type=module', '--eval', checkExports,
  ], { input: baseSource, encoding: 'utf8' });
  const baseExports = JSON.parse(output);
  assert.ok(baseExports.includes('createDomainSession'));
  assert.ok(baseExports.includes('animateLayoutChange'));
  assert.ok(!baseExports.includes('motion'), 'Base does not export motion as an ESM binding');
  for (const imported of consumerNames) {
    assert.ok(baseExports.includes(imported), 'Unavailable Base named ESM export: ' + imported);
  }
});


test('G1a-V21-A08: real Base ProjectState accepts consumer generation 0 and rejects unverified Host generation 1', () => {
  const project = toProject(definition());
  assert.equal(project.schema_version, 0, 'Automations must use the public, non-Host domain session generation');
  const baseRepo = process.env.CB_BASE_DESIGNER_REPO
    || fileURLToPath(new URL('../../../wp-core-blueprint/', import.meta.url));
  assert.ok(existsSync(join(baseRepo, '.git')), 'real Base source checkout required');
  const sources = {};
  for (const name of ['value.js', 'stable-schema.js', 'project-state.js']) {
    try {
      sources[name] = execFileSync('git', [
        '-C', baseRepo, 'show',
        BASE_DESIGNER_V2_SOURCE_SHA + ':assets/js/design/core/' + name,
      ], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
    } catch {
      assert.fail('Pinned merged Base ProjectState source unavailable: ' + name);
    }
  }

  // Load the REAL Base ProjectState and its REAL dependencies in an isolated
  // Node ESM VM. No copied Host logic, mocked validators or private imports.
  const evaluate = [
    "import assert from 'node:assert/strict';",
    "import { SourceTextModule } from 'node:vm';",
    "let input = ''; for await (const part of process.stdin) input += part;",
    "const { sources, project } = JSON.parse(input);",
    "const modules = new Map(Object.entries(sources).map(([name, code]) =>",
    "  [name, new SourceTextModule(code, { identifier: name })]));",
    "const target = modules.get('project-state.js');",
    "await target.link(async (specifier) => {",
    "  const dependency = modules.get(specifier.replace(/^\\.\\//, ''));",
    "  if (!dependency) throw new Error('Unpinned Base dependency: ' + specifier);",
    "  return dependency;",
    "});",
    "await target.evaluate();",
    "const State = target.namespace.ProjectState;",
    "const validate = (candidate) => {",
    "  assert.equal(candidate.design_type, 'automations.workflow');",
    "  assert.equal(candidate.root.type, 'automations.workflow');",
    "};",
    "const session = new State(project, { validate });",
    "assert.equal(session.snapshot().schema_version, 0);",
    "assert.throws(() => new State({ ...project, schema_version: 1 }, { validate }),",
    "  /Stable DesignProject requires verified Host registry context/);",
    "assert.throws(() => new State({ ...project, schema_version: 2 }, { validate }),",
    "  /Unsupported DesignProject schema generation/);",
  ].join('\n');
  execFileSync(process.execPath, [
    '--no-warnings', '--experimental-vm-modules', '--input-type=module', '--eval', evaluate,
  ], { input: JSON.stringify({ sources, project }), encoding: 'utf8' });
});


test('G1a-V21-A09: History imports the domain codec through a versioned WordPress module', async () => {
  const history = await read('assets/js/admin-builder-history.js');
  const assets = await read('src/Admin/DesignerAssets.php');
  assert.match(history, /from 'cb-automations-domain-project'/);
  assert.doesNotMatch(history, /from ['"]\.\/automations-domain-project\.js['"]/);
  assert.match(assets, /private const DOMAIN_PROJECT_MODULE\s*=\s*'cb-automations-domain-project'/);
  assert.match(assets, /\$domain_project\s*=\s*CB_AUTOMATIONS_DIR \. 'assets\/js\/automations-domain-project\.js'/);
  assert.match(assets, /wp_enqueue_script_module\(\s*self::DOMAIN_PROJECT_MODULE,\s*CB_AUTOMATIONS_URL \. 'assets\/js\/automations-domain-project\.js',\s*\[\],\s*is_file\( \$domain_project \) \? \(string\) filemtime\( \$domain_project \) : CB_AUTOMATIONS_VERSION\s*\)/);
  assert.match(assets, /wp_enqueue_script_module\(\s*self::HISTORY_MODULE[\s\S]*?\[\s*'@cb-core\/design-editor',\s*'cb-automations-admin-designer-shell',\s*self::DOMAIN_PROJECT_MODULE\s*\]/);
});
