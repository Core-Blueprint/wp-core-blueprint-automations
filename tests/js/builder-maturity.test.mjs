import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const root = new URL('../../', import.meta.url);
const read = (path) => readFile(new URL(path, root), 'utf8');

test('Automation Builder keeps canonical persistence, shared-shell ownership and save reconciliation intact', async () => {
	const [inspector, history, save, editor, controller, assets, finishCss, designerCss, template] = await Promise.all([
		read('assets/js/admin-builder-inspector.js'),
		read('assets/js/admin-builder-history.js'),
		read('assets/js/admin-builder-save.js'),
		read('assets/js/admin-editor.js'),
		read('src/Admin/WorkflowController.php'),
		read('src/Admin/DesignerAssets.php'),
		read('assets/css/admin-builder-finish.css'),
		read('assets/css/admin-designer-shell.css'),
		read('templates/admin/workflow-editor.php'),
	]);

	assert.match(template, /data-cb-design-launch-root/);
	assert.match(template, /data-cb-design-launch-mode="direct"/);
	assert.ok(template.includes('data-cb-design-exit-url="<?php echo esc_url( \\CB\\Automations\\Admin\\AutomationsPage::url() ); ?>"'));
	assert.match(assets, /enqueue_designer_mode\( __\( 'Automation Builder'/);
	assert.doesNotMatch(assets, /admin-builder-bootstrap\.js|admin-builder-layout\.js|admin_body_class|builder_launch_requested/);

	assert.match(inspector, /dataset\.cbBuilderSelectable/);
	assert.match(inspector, /Selected step|selectedStep/);
	assert.match(inspector, /GET DATA/);
	assert.match(inspector, /ONLY IF/);
	assert.match(inspector, /key: `state:\$\{value\?\.step_id \|\| index\}`/);
	assert.match(inspector, /key: `condition:\$\{value\?\.condition_id \|\| index\}`/);
	assert.match(inspector, /key: `action:\$\{value\?\.step_id \|\| index\}`/);

	assert.match(editor, /window\.cbAutomationsEditorSession/);
	assert.match(editor, /cb-automations:definitionchange/);
	assert.match(editor, /window\.cbCore\?\.designEditor\?\.motion\?\.animateLayoutChange/);
	assert.match(history, /import \{[^}]*CommandHistory[^}]*EditorState[^}]*ProjectState[^}]*animateLayoutChange[^}]*\} from '@cb-core\/design-editor'/);
	assert.match(history, /history\.undo\(\)/);
	assert.match(history, /history\.redo\(\)/);
	assert.match(history, /event\.detail\?\.baselineDefinition/);

	assert.match(save, /new FormData\(form\)/);
	assert.match(save, /payload\.set\(ASYNC_FIELD, '1'\)/);
	assert.match(save, /window\.fetch\(endpoint/);
	assert.match(save, /let saving = false/);
	assert.match(save, /updatePersistedStatuses\(result, !definitionDirty\)/);
	assert.match(save, /headingTitle\.textContent = result\.data\.name/);
	assert.match(save, /cb-core-status__dot--success/);
	assert.match(save, /cb-core-status__dot--warning/);
	assert.match(save, /cb-core-status__dot--danger/);
	assert.match(save, /cb-core-status__dot--muted/);
	assert.match(save, /baselineDefinition: submittedDefinition/);
	assert.match(save, /currentDirty: definitionDirty/);
	assert.match(save, /cb:design-shell:savechange/);

	assert.match(controller, /new\s+WorkflowService\(\)\s*\)->save\(/);
	assert.match(controller, /self::guard\( 'cb_automations_save_workflow' \)/);
	assert.match(controller, /PersistencePolicy::allows_encoded_definition/);
	assert.match(controller, /DefinitionCodec::decode/);
	assert.match(controller, /ValidationState::from_result\( \$result->validation\(\) \)->value/);
	assert.match(controller, /'name'\s*=>\s*\$persisted_name/);
	assert.match(controller, /'activation_state'\s*=>\s*\$persisted_state->value/);
	assert.doesNotMatch(controller, /WorkflowRepository::(?:create|update)\(/);

	assert.match(assets, /activationEnabled/);
	assert.match(assets, /validationDependencyUnavailable/);
	assert.match(template, /\$definition_json\s*=\s*wp_json_encode\(\s*\$editor_data\['workflow'\]\['definition'\]\s*\)/);
	assert.match(template, /name="definition_json".*data-cb-automations-definition/);
	assert.match(editor, /hidden\.value = JSON\.stringify\(definition\)/);
	assert.match(editor, /renderAll\('initial'\)/);

	assert.doesNotMatch(designerCss, /\.cb-automations-design-shell \.cb-core-design-shell__workspace/);
	assert.doesNotMatch(finishCss, /is-palette-collapsed|is-sidebar-collapsed/);
	for (const source of [inspector, history, save, editor]) {
		assert.doesNotMatch(source, /assets\/js\/design\/|CB_CORE_URL/);
	}
});
