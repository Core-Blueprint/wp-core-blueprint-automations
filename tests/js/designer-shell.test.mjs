import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const root = new URL('../../', import.meta.url);
const read = (path) => readFile(new URL(path, root), 'utf8');

test('Automations adopts the public Base Designer Shell without changing workflow ownership', async () => {
	const [suite, plugin, assets, template, moduleSource] = await Promise.all([
		read('src/Integration/Suite.php'),
		read('src/Plugin.php'),
		read('src/Admin/DesignerAssets.php'),
		read('templates/admin/workflow-editor.php'),
		read('assets/js/admin-designer-shell.js'),
	]);

	assert.match(suite, /['"]foundations['"]\s*=>\s*\[[\s\S]*['"]design-editor['"]/);
	assert.match(plugin, /DesignerAssets::init\(\)/);
	assert.match(assets, /wp_enqueue_script_module\(/);
	assert.match(assets, /['"]@cb-core\/design-editor['"]/);
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
});
