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
	]);

	// AD2.1: Library is the outer product surface; Builder launch hides the embedded transition.
	assert.match(bootstrap, /libraryUrl/);
	assert.match(bootstrap, /is-builder-launch-pending/);
	assert.match(bootstrap, /window\.location\.assign\(String\(config\.libraryUrl\)\)/);
	assert.match(bootstrap, /LAUNCH_TIMEOUT_MS/);

	// AD3: selected workflow cards map into a real contextual Inspector in the shared sidebar.
	assert.match(bootstrap, /data-cb-design-shell-tab[\s\S]*inspector/);
	assert.match(bootstrap, /data-cb-design-shell-sidebar-role[\s\S]*inspector/);
	assert.match(inspector, /data-cb-builder-selectable/);
	assert.match(inspector, /Selected step|selectedStep/);
	assert.match(inspector, /GET DATA/);
	assert.match(inspector, /ONLY IF/);

	// AD4: existing editor state remains canonical while Base supplies session history mechanics.
	assert.match(editor, /window\.cbAutomationsEditorSession/);
	assert.match(editor, /cb-automations:definitionchange/);
	assert.match(editor, /Move up|move_up/);
	assert.match(editor, /Move down|move_down/);
	assert.match(history, /import \{ CommandHistory, EditorState, ProjectState \} from '@cb-core\/design-editor'/);
	assert.match(history, /history\.undo\(\)/);
	assert.match(history, /history\.redo\(\)/);
	assert.doesNotMatch(history, /assets\/js\/design\/|CB_CORE_URL/);

	// AD5: Save-in-place adapts the existing canonical form/controller path, not persistence ownership.
	assert.match(save, /new FormData\(form\)/);
	assert.match(save, /payload\.set\(ASYNC_FIELD, '1'\)/);
	assert.match(save, /window\.fetch\(form\.action \|\| window\.location\.href/);
	assert.match(save, /cb:design-shell:savechange/);
	assert.match(controller, /new\s+WorkflowService\(\)\s*\)->save\(/);
	assert.match(controller, /self::guard\( 'cb_automations_save_workflow' \)/);
	assert.match(controller, /PersistencePolicy::allows_encoded_definition/);
	assert.match(controller, /DefinitionCodec::decode/);
	assert.match(controller, /revision'\s*=>\s*\$result->was_saved\(\)\s*\?\s*\$revision\s*\+\s*1\s*:\s*\$revision/);
	assert.doesNotMatch(controller, /WorkflowRepository::(?:create|update)\(/);

	// Adaptive layout stays consumer-owned while Base remains sole fullscreen implementation.
	assert.match(layout, /is-palette-collapsed/);
	assert.match(layout, /is-sidebar-collapsed/);
	assert.match(finishCss, /is-palette-collapsed/);
	assert.match(finishCss, /is-sidebar-collapsed/);
	assert.doesNotMatch(finishCss, /position\s*:\s*fixed|100dvh/);

	// Public Base boundaries only; no execution-runtime maturity sneaks into this UI batch.
	assert.match(assets, /DesignEditorAssets::enqueue_designer_mode/);
	assert.match(assets, /@cb-core\/design-editor/);
	assert.doesNotMatch(assets, /assets\/js\/design\/|assets\/css\/design\//);
	for (const source of [bootstrap, inspector, history, layout, save, editor]) {
		assert.doesNotMatch(source, /queue worker|retry scheduler|execution principal/i);
	}
});
