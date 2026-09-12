import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const root = new URL('../../', import.meta.url);
const read = (path) => readFile(new URL(path, root), 'utf8');

test('Automations consumes the public Base Designer Shell without owning shared structure', async () => {
	const [suite, plugin, assets, template, moduleSource, styles, finishStyles] = await Promise.all([
		read('src/Integration/Suite.php'),
		read('src/Plugin.php'),
		read('src/Admin/DesignerAssets.php'),
		read('templates/admin/workflow-editor.php'),
		read('assets/js/admin-designer-shell.js'),
		read('assets/css/admin-designer-shell.css'),
		read('assets/css/admin-builder-finish.css'),
	]);

	assert.doesNotMatch(suite, /['"]design-editor['"]/);
	assert.match(plugin, /DesignerAssets::init\(\)/);
	assert.match(assets, /wp_enqueue_script_module\(/);
	assert.match(assets, /['"]@cb-core\/design-editor['"]/);
	assert.match(assets, /is_callable\( \[ DesignEditorAssets::class, ['"]enqueue_designer_mode['"] \] \)/);
	assert.match(assets, /enqueue_designer_mode\( __\( 'Automation Builder'/);
	assert.match(assets, /'properties'\s*=>\s*__\( 'Properties'/);
	assert.doesNotMatch(assets, /admin-builder-bootstrap\.js|admin-builder-layout\.js|assets\/js\/features\/designer-launch\.js/);

	assert.match(template, /data-cb-design-launch-root/);
	assert.match(template, /data-cb-design-launch-mode="direct"/);
	assert.match(template, /class="cb-core-design-shell cb-automations-design-shell"/);
	assert.match(template, /data-cb-design-shell/);
	assert.match(template, /cb-core-design-shell__workspace/);
	assert.match(template, /cb-core-design-shell__palette/);
	assert.match(template, /cb-core-design-shell__canvas/);
	assert.match(template, /cb-core-design-shell__sidebar/);
	assert.match(template, /data-cb-design-shell-undo/);
	assert.match(template, /data-cb-design-shell-redo/);
	assert.match(template, /data-cb-design-shell-primary-action/);
	assert.match(template, /data-cb-design-shell-panel="inspector"/);
	assert.match(template, /data-cb-design-shell-panel="settings"/);
	assert.match(template, /data-cb-design-shell-panel="health"/);
	assert.match(template, /cb-automations-validation-panel/);

	assert.match(moduleSource, /import\s*\{\s*createDesignerShell\s*\}\s*from\s*['"]@cb-core\/design-editor['"]/);
	assert.match(moduleSource, /createDesignerShell\(root,\s*\{/);
	assert.match(moduleSource, /sidebar:\s*['"]settings['"]/);
	assert.match(moduleSource, /cb-automations-panel-eyebrow/);
	assert.match(moduleSource, /workflowSidebar\.setAttribute\('aria-label', workflowLabel\)/);
	assert.match(moduleSource, /propertiesSidebar\.setAttribute\('aria-label', propertiesLabel\)/);
	assert.match(moduleSource, /cbAutomationsInspectorStrings\?\.properties/);
	assert.match(moduleSource, /workflowTitle\.remove\(\)/);
	assert.doesNotMatch(moduleSource, /CB_CORE_URL|assets\/js\/design\/|assets\/css\/design\//);

	assert.doesNotMatch(styles, /\.cb-automations-design-shell \.cb-core-design-shell__workspace/);
	assert.doesNotMatch(styles, /position:\s*fixed|100dvh/);
	assert.doesNotMatch(finishStyles, /is-palette-collapsed|is-sidebar-collapsed/);
});
