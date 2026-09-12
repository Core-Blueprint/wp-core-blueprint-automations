import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const root = new URL('../../', import.meta.url);
const read = (path) => readFile(new URL(path, root), 'utf8');

test('Automation Builder maturity keeps shared-shell, canonical persistence and AU1 boundaries intact', async () => {
	const [
		bootstrap,
		inspector,
		history,
		layout,
		save,
		editor,
		controller,
		assets,
		finishCss,
		designerCss,
		template,
	] = await Promise.all([
		read('assets/js/admin-builder-bootstrap.js'),
		read('assets/js/admin-builder-inspector.js'),
		read('assets/js/admin-builder-history.js'),
		read('assets/js/admin-builder-layout.js'),
		read('assets/js/admin-builder-save.js'),
		read('assets/js/admin-editor.js'),
		read('src/Admin/WorkflowController.php'),
		read('src/Admin/DesignerAssets.php'),
		read('assets/css/admin-builder-finish.css'),
		read('assets/css/admin-designer-shell.css'),
		read('templates/admin/workflow-editor.php'),
	]);

	// AD2.1: the workflow editor is a Base-owned direct Designer route, not a simulated manual launch.
	assert.match(template, /data-cb-design-launch-root/);
	assert.match(template, /data-cb-design-launch-mode="direct"/);
	assert.ok(template.includes('data-cb-design-exit-url="<?php echo esc_url( \\CB\\Automations\\Admin\\AutomationsPage::url() ); ?>"'));
	assert.match(assets, /DesignEditorAssets::enqueue_designer_mode\(\)/);
	assert.match(assets, /is_callable\( \[ DesignEditorAssets::class, 'enqueue_designer_mode' \] \)/);
	assert.doesNotMatch(assets, /admin_body_class|builder_launch_requested|cb-automations-builder-launch-pending|libraryUrl/);
	assert.doesNotMatch(bootstrap, /sessionStorage|LAUNCH_TIMEOUT_MS|LAUNCH_PENDING_BODY_CLASS|MutationObserver|window\.location\.assign|searchParams\.set\(\s*['"]builder['"]/);
	assert.doesNotMatch(bootstrap, /data-cb-design-launch[^\n]*cb-core-design-launch/);
	assert.doesNotMatch(designerCss, /cb-automations-builder-launch-pending|cb-automations-builder-launch-fallback|z-index:\s*2147483647/);
	assert.doesNotMatch(designerCss, /\.cb-automations-design-shell(?:\.is-fullscreen)?\s*\{[^}]*margin-top\s*:/);

	// AD3: selected workflow cards map into a real contextual Inspector in the shared sidebar.
	assert.match(bootstrap, /data-cb-design-shell-tab[\s\S]*inspector/);
	assert.match(bootstrap, /data-cb-design-shell-sidebar-role[\s\S]*inspector/);
	assert.match(inspector, /data-cb-builder-selectable/);
	assert.match(inspector, /Selected step|selectedStep/);
	assert.match(inspector, /GET DATA/);
	assert.match(inspector, /ONLY IF/);
	assert.match(inspector, /key: `state:\$\{value\?\.step_id \|\| index\}`/);
	assert.match(inspector, /key: `condition:\$\{value\?\.condition_id \|\| index\}`/);
	assert.match(inspector, /key: `action:\$\{value\?\.step_id \|\| index\}`/);
	assert.match(inspector, /if \(match\) \{[\s\S]*?selectCard\(match, \{ open: false \}\);[\s\S]*?return;[\s\S]*?\}[\s\S]*?selected = null;/);
	assert.match(inspector, /selected = null;[\s\S]*?renderInspector\(null\);/);

	// AD3 polish: discrete reordering consumes Base Designer Motion with stable semantic identities.
	assert.match(editor, /window\.cbCore\?\.designEditor\?\.motion\?\.animateLayoutChange/);
	assert.match(editor, /animateLayoutChange\(motionRoot, mutate\)/);
	assert.match(editor, /'data-cb-design-motion-key': `\$\{context\}:\$\{step\.step_id\}`/);
	assert.match(editor, /'data-cb-design-motion-key': `condition:\$\{condition\.condition_id\}`/);
	assert.doesNotMatch(editor, /getBoundingClientRect|prefers-reduced-motion|cubic-bezier|\.animate\(\s*\[/);

	// AD4: existing editor state remains canonical while Base supplies history and Motion mechanics.
	assert.match(editor, /window\.cbAutomationsEditorSession/);
	assert.match(editor, /cb-automations:definitionchange/);
	assert.match(editor, /Move up|move_up/);
	assert.match(editor, /Move down|move_down/);
	assert.match(history, /import \{[^}]*CommandHistory[^}]*EditorState[^}]*ProjectState[^}]*animateLayoutChange[^}]*\} from '@cb-core\/design-editor'/);
	assert.match(history, /history\.undo\(\)/);
	assert.match(history, /history\.redo\(\)/);
	assert.match(history, /animateLayoutChange\(api\.root, applyDefinition\)/);
	assert.match(history, /event\.detail\?\.baselineDefinition/);
	assert.match(history, /event\.detail\?\.currentDirty === true/);
	assert.match(history, /history\.execute\(commandFor\(current\)\)/);
	assert.doesNotMatch(history, /getBoundingClientRect|prefers-reduced-motion|cubic-bezier|\.animate\(\s*\[/);
	assert.doesNotMatch(history, /assets\/js\/design\/|CB_CORE_URL/);

	// AD5: Save-in-place adapts the existing canonical form/controller path, not persistence ownership.
	assert.match(save, /new FormData\(form\)/);
	assert.match(save, /payload\.set\(ASYNC_FIELD, '1'\)/);
	assert.match(save, /form\.getAttribute\('action'\) \|\| window\.location\.href/);
	assert.match(save, /window\.fetch\(endpoint/);
	assert.doesNotMatch(save, /window\.fetch\(form\.action/);
	assert.match(save, /let saving = false/);
	assert.match(save, /if \(saving\) return/);
	assert.match(save, /save\.disabled = true/);
	assert.match(save, /save\.disabled = false/);
	assert.doesNotMatch(save, /AbortController/);
	assert.match(save, /baselineDefinition: submittedDefinition/);
	assert.match(save, /currentDirty: definitionDirty/);
	assert.match(save, /savedWithChanges/);
	assert.match(assets, /savedWithChanges/);
	assert.match(save, /searchParams\.delete\('builder'\)/);
	assert.doesNotMatch(save, /searchParams\.set\(\s*['"]builder['"]/);
	assert.match(save, /cb:design-shell:savechange/);
	assert.match(controller, /new\s+WorkflowService\(\)\s*\)->save\(/);
	assert.match(controller, /self::guard\( 'cb_automations_save_workflow' \)/);
	assert.match(controller, /PersistencePolicy::allows_encoded_definition/);
	assert.match(controller, /DefinitionCodec::decode/);
	assert.match(controller, /revision'\s*=>\s*\$result->was_saved\(\)\s*\?\s*\$revision\s*\+\s*1\s*:\s*\$revision/);
	assert.doesNotMatch(controller, /WorkflowRepository::(?:create|update)\(/);

	// AD5 closure: the exact canonical definition is the save payload and the reopen source.
	assert.match(template, /\$definition_json\s*=\s*wp_json_encode\(\s*\$editor_data\['workflow'\]\['definition'\]\s*\)/);
	assert.match(template, /name="definition_json"[^>]*data-cb-automations-definition/);
	assert.match(editor, /const state = JSON\.parse\(JSON\.stringify\(data\.workflow\?\.definition/);
	assert.match(editor, /hidden\.value = JSON\.stringify\(definition\)/);
	assert.match(editor, /form\.addEventListener\('submit', \(\) => sync\('submit'\)\)/);
	assert.match(editor, /renderAll\('initial'\)/);

	// Adaptive layout stays consumer-owned while Base remains sole fullscreen implementation.
	assert.match(layout, /is-palette-collapsed/);
	assert.match(layout, /is-sidebar-collapsed/);
	assert.match(finishCss, /is-palette-collapsed/);
	assert.match(finishCss, /is-sidebar-collapsed/);
	assert.doesNotMatch(finishCss, /position\s*:\s*fixed|100dvh/);
	assert.match(designerCss, /@media \(max-width: 1280px\)[\s\S]*?grid-template-columns:\s*minmax\(170px, 200px\) minmax\(0, 1fr\);/);
	assert.doesNotMatch(designerCss, /minmax\(260px, 300px\)/);
	assert.match(finishCss, /@media \(max-width: 1280px\)[\s\S]*?is-palette-collapsed[\s\S]*?grid-template-columns:\s*58px minmax\(0, 1fr\);/);
	assert.match(finishCss, /@media \(max-width: 1280px\)[\s\S]*?is-sidebar-collapsed[\s\S]*?grid-template-columns:\s*minmax\(170px, 200px\) minmax\(0, 1fr\);/);

	// Public Base boundaries only; no execution-runtime maturity sneaks into this UI batch.
	assert.match(assets, /@cb-core\/design-editor/);
	assert.doesNotMatch(assets, /assets\/js\/design\/|assets\/css\/design\//);
	for (const source of [bootstrap, inspector, history, layout, save, editor]) {
		assert.doesNotMatch(source, /queue worker|retry scheduler|execution principal/i);
	}
});
