(() => {
	'use strict';

	const ASYNC_FIELD = 'cb_automations_builder_async';
	const strings = {
		saving: 'Saving automation…',
		failed: 'The automation could not be saved.',
		savedWithChanges: 'Automation saved. Newer edits are not saved yet.',
		ready: 'Ready',
		needsAttention: 'Needs attention',
		validDescription: 'The current definition is valid against the live capability catalog.',
		invalidDescription: 'Resolve these items before the automation can be enabled.',
		workflowIssue: 'Workflow issue',
		...(window.cbAutomationsSaveStrings || {}),
	};
	const form = document.querySelector('[data-cb-automations-editor-form]');
	const shell = document.querySelector('[data-cb-automations-designer-shell]');
	const save = shell?.querySelector('[data-cb-design-shell-primary-action]');
	const status = shell?.querySelector('[data-cb-design-shell-status]');
	const revision = form?.querySelector('[name="revision"]');
	const name = form?.querySelector('[name="name"]');
	const activation = form?.querySelector('[name="activation_state"]');
	const definition = form?.querySelector('[data-cb-automations-definition]');
	const health = shell?.querySelector('.cb-automations-validation-panel');
	if (!(form instanceof HTMLFormElement) || !shell || !(save instanceof HTMLButtonElement) || typeof window.fetch !== 'function') return;

	let saving = false;
	const parseDefinition = (value) => {
		try {
			const parsed = JSON.parse(String(value || '{}'));
			return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : null;
		} catch (error) {
			return null;
		}
	};
	const emit = (state, message = '', extra = {}) => {
		const text = String(message || '');
		if (status) status.textContent = text;
		shell.toggleAttribute('aria-busy', state === 'saving');
		shell.dispatchEvent(new CustomEvent('cb:design-shell:savechange', {
			bubbles: true,
			detail: Object.freeze({ state, message: text, ...extra }),
		}));
	};
	const responseMessage = (payload, fallback) => {
		const message = payload?.data?.message;
		return typeof message === 'string' && message.trim() ? message.trim() : fallback;
	};
	const updateHealth = (payload) => {
		if (!health || typeof payload?.data?.valid !== 'boolean') return;
		const valid = payload.data.valid;
		const heading = health.querySelector('.cb-automations-panel-heading h2');
		if (heading) heading.textContent = valid ? strings.ready : strings.needsAttention;
		Array.from(health.children).forEach((child) => {
			if (!child.classList?.contains('cb-automations-panel-heading')) child.remove();
		});
		const description = document.createElement('p');
		description.className = 'description';
		description.textContent = valid ? strings.validDescription : strings.invalidDescription;
		health.append(description);
		if (valid) return;
		const issues = Array.isArray(payload.data.issues) ? payload.data.issues : [];
		const list = document.createElement('ul');
		list.className = 'cb-automations-validation-list';
		issues.forEach((issue) => {
			const item = document.createElement('li');
			const message = document.createElement('strong');
			message.textContent = String(issue?.message || strings.workflowIssue);
			const path = document.createElement('code');
			path.textContent = String(issue?.path || '');
			item.append(message, path);
			list.append(item);
		});
		health.append(list);
	};
	const cleanLocation = () => {
		try {
			const url = new URL(window.location.href);
			url.searchParams.delete('notice');
			url.searchParams.delete('builder');
			window.history.replaceState({}, '', url.toString());
		} catch (error) {
			// URL cleanup is optional UI polish.
		}
	};
	const reconcileAfterUnparseableSuccess = () => {
		cleanLocation();
		window.location.reload();
	};

	form.addEventListener('submit', async (event) => {
		if (event.submitter !== save) return;
		event.preventDefault();
		if (saving) return;

		saving = true;
		save.disabled = true;
		const submittedDefinitionJson = definition instanceof HTMLInputElement ? definition.value : '';
		const submittedDefinition = parseDefinition(submittedDefinitionJson);
		const submittedName = name instanceof HTMLInputElement ? name.value : null;
		const submittedActivation = activation instanceof HTMLSelectElement ? activation.value : null;
		emit('saving', strings.saving);

		const payload = new FormData(form);
		payload.set(ASYNC_FIELD, '1');
		const endpoint = form.getAttribute('action') || window.location.href;
		try {
			const response = await window.fetch(endpoint, {
				method: 'POST',
				body: payload,
				credentials: 'same-origin',
			});
			const body = await response.text();
			let result = null;
			try {
				result = JSON.parse(body);
			} catch (error) {
				if (response.ok) {
					reconcileAfterUnparseableSuccess();
					return;
				}
			}
			if (!response.ok || result?.success !== true) {
				throw new Error(responseMessage(result, strings.failed));
			}

			const definitionDirty = definition instanceof HTMLInputElement && definition.value !== submittedDefinitionJson;
			const nameDirty = name instanceof HTMLInputElement && submittedName !== null && name.value !== submittedName;
			const activationDirty = activation instanceof HTMLSelectElement && submittedActivation !== null && activation.value !== submittedActivation;
			const currentDirty = definitionDirty || nameDirty || activationDirty;

			if (revision instanceof HTMLInputElement && Number.isInteger(Number(result.data?.revision))) {
				revision.value = String(result.data.revision);
			}
			if (!activationDirty && activation instanceof HTMLSelectElement && ['enabled', 'disabled'].includes(result.data?.activation_state)) {
				activation.value = result.data.activation_state;
			}
			if (!definitionDirty) updateHealth(result);
			cleanLocation();
			emit(
				'saved',
				currentDirty ? strings.savedWithChanges : responseMessage(result, save.textContent?.trim() || ''),
				{
					baselineDefinition: submittedDefinition,
					currentDirty: definitionDirty,
				}
			);
		} catch (error) {
			emit('error', error instanceof Error && error.message ? error.message : strings.failed);
		} finally {
			saving = false;
			save.disabled = false;
		}
	});
})();
