import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const root = new URL('../../', import.meta.url);
const view = readFileSync(new URL('templates/admin/automations.php', root), 'utf8');
const page = readFileSync(new URL('src/Admin/AutomationsPage.php', root), 'utf8');
const style = readFileSync(new URL('assets/css/admin-overview.css', root), 'utf8');
const controller = readFileSync(new URL('assets/js/admin-workflow-delete.js', root), 'utf8');
const menuCode = readFileSync(new URL('assets/js/admin-workflow-menu.js', root), 'utf8');

test('UX-A prioritizes status and health, retains server search/folder/status combination', () => {
	const headings = view.slice(view.indexOf('<thead><tr>'), view.indexOf('</tr></thead>'));
	const columns = [...headings.matchAll(/class="cb-automations-workspace__(select|status|health|name|trigger|folder|updated|menu)-col"/g)].map(match => match[1]);
	assert.deepEqual(columns, ['select', 'status', 'health', 'name', 'trigger', 'folder', 'updated', 'menu']);
	assert.match(view, /class="cb-automations-workspace__quick-filters"/);
	assert.match(view, /name="status" value="<\?php echo esc_attr\( \$status \); \?>"/);
	assert.match(page, /WorkflowRepository::count_index\( \$search, 'enabled', \$folder_filter \)/);
	assert.match(page, /WorkflowRepository::count_index\( \$search, 'disabled', \$folder_filter \)/);
	assert.match(view, /'s' => \$search, 'status' => \$filter_status, 'sort' => \$sort, 'folder' =>/);
});

test('UX-A native action menu preserves protected destructive/action forms', () => {
	assert.match(view, /popovertarget="<\?php echo esc_attr\( \$menu_id \); \?>"/);
	assert.match(view, /popover="auto" role="group"/);
	for (const action of ['cb_automations_toggle_workflow', 'cb_automations_export_workflow', 'cb_automations_folder_assign', 'cb_automations_delete_workflow']) {
		assert.ok(view.includes('value="' + action + '"'), 'missing action ' + action);
	}
	assert.match(view, /name="expected_revision"/);
	assert.match(view, /WorkflowActivationController::nonce_action/);
	assert.match(view, /wp_nonce_field\( 'cb_automations_delete_workflow'/);
	assert.doesNotMatch(view, /cb-automations-workspace__folder-assign/);
	assert.match(style, /cb-automations-workspace__action-menu\[popover\]/);
	assert.match(style, /cb-automations-workspace__trigger-cell.*::before/);
});

test('UX-A action popover is positioned beside the target including bottom-edge flip', () => {
	let onToggle;
	const menu = { id: 'test-actions', offsetWidth: 210, offsetHeight: 180, style: {},
		matches: () => true, addEventListener: (type, fn) => { if (type === 'toggle') onToggle = fn; } };
	let top = 200;
	const button = { getBoundingClientRect: () => ({ right: 600, bottom: top + 36, top }) };
	const workspace = { querySelectorAll: () => [menu], querySelector: () => button };
	const document = { querySelector: () => workspace };
	vm.runInNewContext(menuCode, { document, window: { innerWidth: 800, innerHeight: 560 } });
	onToggle();
	assert.equal(menu.style.left, '390px');
	assert.equal(menu.style.top, '240px');
	top = 495;
	onToggle();
	assert.equal(menu.style.top, '311px');
});

test('UX-A bulk toolbar is hidden until selection and keeps delete confirmation', () => {
	assert.match(view, /data-cb-workflow-bulk-toolbar hidden/);
	assert.match(controller, /toolbar.hidden = count === 0/);
	assert.match(view, /data-cb-workflow-bulk-confirm/);
	assert.match(view, /data-cb-workflow-select form="cb-automations-bulk-delete"/);
});
