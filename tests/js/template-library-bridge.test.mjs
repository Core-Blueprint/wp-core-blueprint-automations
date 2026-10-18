import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const path = new URL('../../assets/js/template-library-bridge.js', import.meta.url);
const source = readFileSync(path, 'utf8');
const overview = readFileSync(new URL('../../templates/admin/automations.php', import.meta.url), 'utf8');
const editor = readFileSync(new URL('../../templates/admin/workflow-editor.php', import.meta.url), 'utf8');

test('Automations imports only the Base public modal browser module', () => {
	assert.match(source, /import \{ open as openBrowser \} from '@cb-core\/template-browser'/);
	assert.match(source, /contextToken: token/);
	assert.match(source, /onChoose: async \(metadata\)/);
	assert.match(source, /metadata\.source !== SOURCE/);
	assert.match(source, /metadata\.designer !== DESIGNER/);
	assert.match(source, /metadata\.extension !== EXTENSION/);
	assert.doesNotMatch(source, /localStorage|sessionStorage|innerHTML|eval\(/);
});
test('Browser cannot instantiate workflows with untrusted identities or cross-origin URLs', () => {
	assert.match(source, /destination\.origin !== window\.location\.origin/);
	assert.match(source, /\/wp-admin\/admin-post\.php/);
	assert.match(source, /template_source: SOURCE/);
	assert.match(source, /template_provider: EXTENSION/);
	assert.match(source, /_cb_automations_nonce: nonce/);
	assert.match(source, /form\.method = 'POST'/);
	assert.doesNotMatch(source, /activation_state|execution_principal_user_id|client_secret/);
});
test('Both overview and Designer render Browse Templates with server-signed tokens', () => {
	for (const page of [overview, editor]) {
		assert.match(page, /TemplateLibraryBridge::scope_token\(\)/);
		assert.match(page, /data-cb-automations-browse-templates/);
		assert.match(page, /wp_create_nonce\( 'cb_automations_create_from_template' \)/);
	}
	assert.match(editor, /TemplateManagementController::nonce\( 'save', \$record->id\(\) \)/);
	assert.match(overview, /cb_automations_create_from_template/); // legacy fallback remains.
});
