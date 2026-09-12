import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const root = new URL('../../', import.meta.url);
const read = (path) => readFile(new URL(path, root), 'utf8');

test('Automations adopts the public Base Designer Shell without changing workflow ownership', async () => {
	const [suite, plugin, assets, template, moduleSource, bootstrap, styles] = await Promise.all([
		read('src/Integration/Suite.php'),
		read('src/Plugin.php'),
		read('src/Admin/DesignerAssets.php'),
		read('templates/admin/workflow-editor.php'),
		read('assets/js/admin-designer-shell.js'),
		read('assets/js/admin-builder-bootstrap.js'),
		read('assets/css/admin-designer-shell.css'),
	]);

	assert.match(suite, /['"]foundations['"]\s*=>\s*\[[\s\S]*['"]design-editor['"]/);
	assert.match(plugin, /DesignerAssets::init\(\)/);
	assert.match(assets, /wp_enqueue_script_module\(/);
	assert.match(assets, /['"]@cb-core\/design-editor['"]/);
	assert.match(assets, /is_callable\( \[ DesignEditorAssets::class, ['"]enqueue_designer_mode['"] \] \)/);
	assert.match(assets, /DesignEditorAssets::enqueue_designer_mode\(\)/);
	assert.match(assets, /DesignEditorAssets::enqueue\(\)/);
	assert.match(assets, /cbAutomationsBuilderStrings/);
	assert.doesNotMatch(assets, /assets\/js\/features\/designer-launch\.js/);

	assert.match(template, /class="cb-core-design-shell cb-automations-design-shell"/);
	assert.match(template, /data-cb-design-shell/);
	assert.match(template, /cb-core-design-shell__workspace/);
	assert.match(template, /cb-core-design-shell__palette/);
	assert.match(template, /cb-core-design-shell__canvas/);
	assert.match(template, /cb-core-design-shell__sidebar/);
	assert.match(template, /data-cb-design-shell-group="sidebar"/);
	assert.match(template, /data-cb-design-shell-tab="settings"/);
	assert.match(template, /data-cb-design-shell-tab="health"/);
	assert.match(template, /data-cb-design-shell-panel="settings"/);
	assert.match(template, /data-cb-design-shell-panel="health"/);
	assert.match(template, /cb-automations-validation-panel/);

	for (const hook of [
		'data-cb-automations-editor',
		'data-cb-automations-trigger',
		'data-cb-automations-states',
		'data-cb-automations-conditions',
		'data-cb-automations-actions',
	]) {
		assert.match(template, new RegExp(hook));
	}

	assert.match(moduleSource, /import\s*\{\s*createDesignerShell\s*\}\s*from\s*['"]@cb-core\/design-editor['"]/);
	assert.match(moduleSource, /createDesignerShell\(root,\s*\{/);
	assert.match(moduleSource, /sidebar:\s*['"]settings['"]/);
	assert.doesNotMatch(moduleSource, /CB_CORE_URL|assets\/js\/design\/|assets\/css\/design\//);

	assert.match(bootstrap, /data-cb-design-launch-root/);
	assert.match(bootstrap, /data-cb-design-launch-context/);
	assert.match(bootstrap, /data-cb-design-shell-primary-action/);
	assert.match(bootstrap, /searchParams\.set\(['"]builder['"], ['"]1['"]\)/);
	assert.match(bootstrap, /cb-automations-builder-workflow/);
	assert.match(bootstrap, /MutationObserver/);
	assert.match(bootstrap, /cb-core-design-launch/);
	assert.match(bootstrap, /cb:design-shell:fullscreenchange/);
	assert.match(bootstrap, /Open builder/);
	assert.match(bootstrap, /Automation Builder/);

	assert.match(styles, /\.cb-automations-design-shell \.cb-core-design-shell__workspace/);
	assert.match(styles, /\.cb-automations-design-shell__sidebar \.cb-core-design-shell__sidebar-tab/);
	assert.doesNotMatch(styles, /position:\s*fixed|100dvh/);
});
