/**
 * Automations consumer adapter for Base @cb-core/template-browser.
 * A selected metadata record is NEVER itself authority to create a workflow.
 * The legacy POST handler revalidates source, identity and caller capability.
 */
import { open as openBrowser } from '@cb-core/template-browser';

const SOURCE = 'core-blueprint-automations-workflows';
const EXTENSION = 'core-blueprint-automations';
const DESIGNER = 'workflow';

for (const button of document.querySelectorAll('[data-cb-automations-browse-templates]')) {
	if (!(button instanceof HTMLButtonElement)) continue;
	button.addEventListener('click', async () => {
		if (button.disabled) return;
		const token = button.dataset.cbTemplateScope || '';
		const nonce = button.dataset.cbTemplateNonce || '';
		const action = button.dataset.cbTemplateAction || '';
		if (!token || !nonce || !action) return;
		try {
			button.disabled = true;
			await openBrowser({
				contextToken: token,
				title: button.textContent.trim(),
				onChoose: async (metadata) => {
					if (!metadata || metadata.source !== SOURCE ||
						metadata.extension !== EXTENSION ||
						metadata.designer !== DESIGNER ||
						metadata.compatible !== true ||
						typeof metadata.id !== 'string' ||
						!/^[a-z][a-z0-9]*(?:[._-][a-z0-9]+)*$/.test(metadata.id)) {
						return false;
					}
					const destination = new URL(action, window.location.href);
					if (destination.origin !== window.location.origin ||
						!destination.pathname.endsWith('/wp-admin/admin-post.php')) {
						return false;
					}
					const form = document.createElement('form');
					form.method = 'POST';
					form.action = destination.href;
					for (const [name, value] of Object.entries({
						action: 'cb_automations_create_from_template',
						_cb_automations_nonce: nonce,
						template_source: SOURCE,
						template_provider: EXTENSION,
						template_id: metadata.id,
					})) {
						const input = document.createElement('input');
						input.type = 'hidden';
						input.name = name;
						input.value = value;
						form.append(input);
					}
					document.body.append(form);
					form.submit();
					return true;
				},
			});
		} catch {
			// No alternate account or network transfer. Keep the existing
			// server-rendered starter template actions as a fallback.
		} finally {
			button.disabled = false;
		}
	});
}
