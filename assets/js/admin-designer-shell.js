import { createDesignerShell, configureDesignerSidebar, createDesignerNavigation } from '@cb-core/design-editor';
import { show as showModal } from '@cb-core/modal';

const root = document.querySelector('[data-cb-automations-designer-shell]');

if (root) {
	const workflowSwitcher = root.querySelector('[data-cb-automations-workflow-switcher]');
	if (workflowSwitcher instanceof HTMLSelectElement) {
		const form = document.querySelector('[data-cb-automations-editor-form]');
		const definition = form?.querySelector('[data-cb-automations-definition]');
		const name = form?.querySelector('[name="name"]');
		const activation = form?.querySelector('[name="activation_state"]');
		const authority = form?.querySelector('[name="rebind_execution_principal"]');
		const normalized = (value) => {
			try { return JSON.stringify(JSON.parse(String(value || '{}'))); }
			catch { return String(value || ''); }
		};
		let allowConfirmedExit = false;
		let baseline = {
			definition: normalized(definition?.value),
			name: String(name?.value || ''),
			activation: String(activation?.value || ''),
			rebind: Boolean(authority?.checked),
		};
		const isDirty = () => (
			normalized(definition?.value) !== baseline.definition
			|| String(name?.value || '') !== baseline.name
			|| String(activation?.value || '') !== baseline.activation
			|| Boolean(authority?.checked) !== baseline.rebind
		);

		window.addEventListener('beforeunload', (event) => {
			if (allowConfirmedExit || !isDirty()) return;
			event.preventDefault();
			event.returnValue = '';
		});

		const launchRoot = root.closest('[data-cb-design-launch-root]');
		const contextDataNode = document.getElementById('cb-automations-editor-data');
		const contextError = (key, fallback) => new Error(
			String(window.cbAutomationsInspectorStrings?.[key] || fallback)
		);
		const switchContext = async (url) => {
			if (root.hasAttribute('aria-busy')) throw contextError('saveInProgress', 'Wait for the current save to finish.');
			const session = window.cbAutomationsEditorSession;
			const history = window.cbAutomationsBuilderHistory;
			if (!session?.replace || !history?.resetForContext || !contextDataNode) {
				throw contextError('contextUnavailable', 'Workflow switching is not available. Reload the editor.');
			}
			const id = Number(url.searchParams.get('workflow'));
			if (!Number.isSafeInteger(id) || id < 1 || url.searchParams.get('page') !== 'core-blueprint-automations-workflows') {
				throw contextError('contextUnavailable', 'Invalid workflow selection.');
			}
			const response = await fetch(url.href, { credentials: 'same-origin' });
			if (!response.ok) throw contextError('loadFailed', 'Could not load the selected workflow.');
			const nextDocument = new DOMParser().parseFromString(await response.text(), 'text/html');
			const nextDataNode = nextDocument.getElementById('cb-automations-editor-data');
			const nextForm = nextDocument.querySelector('[data-cb-automations-editor-form]');
			if (!nextDataNode || !nextForm) throw contextError('contextUnavailable', 'The selected workflow cannot be edited in Designer Mode.');
			let nextData;
			let existingData;
			try {
				nextData = JSON.parse(nextDataNode.textContent || '{}');
				existingData = JSON.parse(contextDataNode.textContent || '{}');
			} catch {
				throw contextError('loadFailed', 'The selected workflow data could not be read.');
			}
			const nextWorkflow = nextData.workflow;
			const nextDefinition = nextWorkflow?.definition;
			if (Number(nextWorkflow?.id) !== id || !nextDefinition || typeof nextDefinition !== 'object' || Array.isArray(nextDefinition)) {
				throw contextError('loadFailed', 'The selected workflow data is invalid.');
			}
			// Editor/picker condition registries are session-scoped; fail closed if
			// provider contracts have changed since initialization.
			if (JSON.stringify(nextData.capabilities) !== JSON.stringify(existingData.capabilities)
				|| JSON.stringify(nextData.operators) !== JSON.stringify(existingData.operators)) {
				throw contextError('catalogChanged', 'Available capabilities changed. Reload Designer before switching workflows.');
			}
			const nextId = nextForm.querySelector('[name="workflow_id"]');
			const nextRevision = nextForm.querySelector('[name="revision"]');
			const currentId = form?.querySelector('[name="workflow_id"]');
			const currentRevision = form?.querySelector('[name="revision"]');
			const incomingName = nextForm.querySelector('[name="name"]');
			const incomingActivation = nextForm.querySelector('[name="activation_state"]');
			const incomingPrincipal = nextForm.querySelector('[data-cb-automations-principal-label]');
			if (!nextId || !nextRevision || !currentId || !currentRevision || !incomingName || !incomingActivation) {
				throw contextError('loadFailed', 'The selected workflow form is incomplete.');
			}
			if (String(nextId.value) !== String(id)) throw contextError('loadFailed', 'Workflow identifiers do not match.');
			// Validation recovery owns status cards; its issue shape is the saved
			// validation contract with messages from the server-rendered view.
			const messages = Array.from(nextDocument.querySelectorAll('.cb-automations-validation-list > li strong'))
				.map((node) => node.textContent?.trim() || '');
			const issues = Array.isArray(nextData.validation) ? nextData.validation.map((issue, index) => ({
				...issue,
				message: messages[index] || '',
			})) : [];
			session.replace(nextDefinition, { source: 'context' });
			currentId.value = String(id);
			currentRevision.value = nextRevision.value;
			if (name) name.value = incomingName.value;
			if (activation) activation.value = incomingActivation.value;
			if (authority) authority.checked = false;
			const principalLabel = form?.querySelector('[data-cb-automations-principal-label]');
			if (principalLabel && incomingPrincipal) principalLabel.textContent = incomingPrincipal.textContent;
			const heading = document.querySelector('.cb-automations-editor-heading .cb-core-title');
			if (heading) heading.textContent = incomingName.value;
			const oldStatuses = document.querySelectorAll('.cb-automations-editor-statuses .cb-core-status');
			const newStatuses = nextDocument.querySelectorAll('.cb-automations-editor-statuses .cb-core-status');
			oldStatuses.forEach((node, index) => {
				const next = newStatuses.item(index);
				if (!next) return;
				node.className = next.className;
				node.replaceChildren(...Array.from(next.childNodes).map((child) => document.importNode(child, true)));
			});
			contextDataNode.textContent = nextDataNode.textContent;
			history.resetForContext(nextDefinition);
			root.dispatchEvent(new CustomEvent('cb-automations:validation-saved', {
				bubbles: true,
				detail: Object.freeze({ valid: issues.length === 0, issues }),
			}));
			root.dispatchEvent(new CustomEvent('cb-automations:contextloaded', {
				bubbles: true,
				detail: Object.freeze({ workflowId: id, definition: nextDefinition }),
			}));
			baseline = {
				definition: normalized(JSON.stringify(nextDefinition)),
				name: incomingName.value,
				activation: incomingActivation.value,
				rebind: false,
			};
			window.history.replaceState(window.history.state, '', url.href);
			return Object.freeze({ workflowId: id });
		};


		// Base requires respondWith() synchronously. On dirty changes let Base
		// restore the previous selection; replay it only after modal approval.
		let awaitingChoice = false;
		let approvedSwitchUrl = '';
		const saveBeforeSwitch = () => new Promise((resolve, reject) => {
			const saveButton = root.querySelector('[data-cb-design-shell-primary-action]');
			if (!(form instanceof HTMLFormElement) || !(saveButton instanceof HTMLButtonElement)
				|| window.cbAutomationsAsyncSaveReady !== true) {
				reject(contextError('contextUnavailable', 'Workflow saving is unavailable. Reload the editor.'));
				return;
			}
			if (root.hasAttribute('aria-busy') || saveButton.disabled) {
				reject(contextError('saveInProgress', 'Wait for the current save to finish.'));
				return;
			}
			const onSaveChange = (event) => {
				const state = String(event.detail?.state || '');
				if (state !== 'saved' && state !== 'error') return;
				root.removeEventListener('cb:design-shell:savechange', onSaveChange);
				if (state === 'error') {
					reject(new Error(String(event.detail?.message || window.cbAutomationsSaveStrings?.failed || 'The automation could not be saved.')));
				} else if (isDirty()) {
					reject(new Error(String(window.cbAutomationsSaveStrings?.savedWithChanges || 'Automation saved. Newer edits are not saved yet.')));
				} else {
					resolve();
				}
			};
			root.addEventListener('cb:design-shell:savechange', onSaveChange);
			try {
				form.requestSubmit(saveButton);
			} catch (error) {
				root.removeEventListener('cb:design-shell:savechange', onSaveChange);
				reject(error);
			}
		});
		const chooseSwitchAction = async (purpose = 'switch') => {
			const exiting = purpose === 'exit';
			const labels = window.cbAutomationsInspectorStrings || {};
			const body = document.createElement('div');
			const description = document.createElement('p');
			description.textContent = String(exiting
				? (labels.closeUnsavedDescription || 'This workflow has unsaved changes. Save or discard them before closing the Builder.')
				: (labels.unsavedChanges || 'This workflow has unsaved changes. Save or discard them before switching.'));
			body.append(description);
			const options = document.createElement('fieldset');
			options.className = 'cb-automations-switch-options';
			const legend = document.createElement('legend');
			legend.textContent = String(labels.unsavedChoice || 'What should happen to your changes?');
			options.append(legend);
			const radios = [];
			const choices = document.createElement('div');
			choices.className = 'cb-automations-switch-choices';
			for (const [value, caption] of [
				['save', labels.optionSave || 'Save changes'],
				['discard', labels.optionDiscard || 'Discard changes'],
			]) {
				const option = document.createElement('label');
				option.className = 'cb-automations-switch-option';
				const radio = document.createElement('input');
				radio.type = 'radio';
				radio.name = 'cb-automations-switch-action';
				radio.value = value;
				radio.checked = value === 'save';
				option.append(radio, document.createTextNode(String(caption)));
				choices.append(option);
				radios.push(radio);
			}
			options.append(choices);
			body.append(options);
			const errorMessage = document.createElement('p');
			errorMessage.className = 'cb-automations-switch-error';
			errorMessage.setAttribute('role', 'alert');
			errorMessage.hidden = true;
			body.append(errorMessage);
			let action = null;
			const accepted = await showModal({
				title: String(labels.unsavedTitle || 'Unsaved changes'),
				body,
				confirmLabel: String(exiting ? (labels.closeBuilder || 'Close Builder') : (labels.switchWorkflow || 'Switch workflow')),
				cancelLabel: String(labels.cancel || 'Cancel'),
				onConfirm: async () => {
					const selected = radios.find((radio) => radio.checked)?.value;
					if (!selected) return false;
					errorMessage.hidden = true;
					if (selected === 'save') {
						radios.forEach((radio) => { radio.disabled = true; });
						try {
							await saveBeforeSwitch();
						} catch (error) {
							errorMessage.textContent = error instanceof Error ? error.message : String(window.cbAutomationsSaveStrings?.failed || 'The automation could not be saved.');
							errorMessage.hidden = false;
							return false;
						} finally {
							radios.forEach((radio) => { radio.disabled = false; });
						}
					}
					action = selected;
					return true;
				},
			});
			return accepted ? action : null;
		};

		// Direct Designer Mode close belongs to Base. Intercept the close
		// intent before Base toggles fullscreen, then replay its own control
		// only after the user approves. Never navigate around Base's lifecycle.
		const chooseExitAction = async () => {
			if (isDirty()) return chooseSwitchAction('exit');
			const labels = window.cbAutomationsInspectorStrings || {};
			const approved = await showModal({
				title: String(labels.closeTitle || 'Close Automation Builder?'),
				body: String(labels.closeDescription || 'Return to Automations?'),
				confirmLabel: String(labels.closeBuilder || 'Close Builder'),
				cancelLabel: String(labels.cancel || 'Cancel'),
			});
			return approved ? 'close' : null;
		};
		let exitPending = false;
		let approvedCloseClick = false;
		const requestExit = () => {
			if (exitPending || allowConfirmedExit) return;
			exitPending = true;
			Promise.resolve().then(chooseExitAction).then((action) => {
				if (!action) return;
				const close = root.querySelector('[data-cb-design-shell-close]');
				const fullscreen = root.querySelector('[data-cb-design-shell-fullscreen]');
				if (!(close instanceof HTMLButtonElement) || !(fullscreen instanceof HTMLButtonElement)
					|| fullscreen.getAttribute('aria-pressed') !== 'true') {
					throw contextError('contextUnavailable', 'The Designer close control is unavailable.');
				}
				// Discard was explicitly approved. Otherwise beforeunload would
				// produce a second browser warning after the modal confirmation.
				allowConfirmedExit = true;
				approvedCloseClick = true;
				try {
					close.click();
				} finally {
					approvedCloseClick = false;
					if (fullscreen.getAttribute('aria-pressed') === 'true') allowConfirmedExit = false;
				}
			}).catch((error) => {
				const status = root.querySelector('[data-cb-design-shell-status]');
				if (status) status.textContent = error instanceof Error ? error.message : String(error);
			}).finally(() => {
				exitPending = false;
			});
		};
		launchRoot?.addEventListener('click', (event) => {
			const close = event.target?.closest?.('[data-cb-design-shell-close]');
			if (!close || !root.contains(close) || approvedCloseClick) return;
			event.preventDefault();
			event.stopImmediatePropagation();
			requestExit();
		}, true);
		window.addEventListener('keydown', (event) => {
			if (event.key !== 'Escape' || event.defaultPrevented
				|| !root.classList.contains('is-fullscreen')) return;
			const modal = document.querySelector('dialog.cb-core-modal[open]');
			if (modal) {
				// Base fullscreen also listens for Escape. Let the open dialog
				// handle dismissal instead of accidentally exiting the Builder.
				event.preventDefault();
				event.stopImmediatePropagation();
				modal.dispatchEvent(new Event('cancel', { cancelable: true }));
				return;
			}
			event.preventDefault();
			event.stopImmediatePropagation();
			requestExit();
		}, true);

		launchRoot?.addEventListener('cb:design-shell:contextrequest', (event) => {
			if (event.detail?.control !== workflowSwitcher) return;
			const target = String(event.detail.value || '').trim();
			let url;
			try { url = new URL(target, window.location.href); }
			catch { return; }
			if (!target || url.origin !== window.location.origin) return;
			if (approvedSwitchUrl === url.href) {
				approvedSwitchUrl = '';
				event.detail.respondWith(switchContext(url));
				return;
			}
			if (awaitingChoice) return;
			if (isDirty()) {
				awaitingChoice = true;
				workflowSwitcher.disabled = true;
				let handedOff = false;
				Promise.resolve().then(chooseSwitchAction).then((action) => {
					if (!action) return;
					approvedSwitchUrl = url.href;
					workflowSwitcher.value = target;
					workflowSwitcher.dispatchEvent(new Event('change', { bubbles: true }));
					handedOff = approvedSwitchUrl === '';
					approvedSwitchUrl = '';
				}).catch((error) => {
					const status = root.querySelector('[data-cb-design-shell-status]');
					if (status) status.textContent = error instanceof Error ? error.message : String(error);
				}).finally(() => {
					awaitingChoice = false;
					if (!handedOff) workflowSwitcher.disabled = false;
				});
				return;
			}
			event.detail.respondWith(switchContext(url));
		});

		root.addEventListener('cb:design-shell:savechange', (event) => {
			if (String(event.detail?.state || '') !== 'saved') return;
			baseline = {
				definition: normalized(JSON.stringify(event.detail?.baselineDefinition || {})),
				name: String(event.detail?.baselineName ?? name?.value ?? ''),
				activation: String(event.detail?.baselineActivation ?? activation?.value ?? ''),
				rebind: false,
			};
		});

	}

	const workflowSidebar = root.querySelector('.cb-core-design-shell__palette');
	const workflowTitle = workflowSidebar?.querySelector('.cb-automations-panel-eyebrow');
	const workflowLabel = String(workflowTitle?.textContent || '').trim() || 'Workflow';
	const propertiesLabel = String(window.cbAutomationsInspectorStrings?.properties || '').trim() || 'Properties';
	const propertiesSidebar = root.querySelector('.cb-core-design-shell__sidebar');

	const applyPanelTitle = (panel, title) => {
		if (!panel || !title) return;
		panel.dataset.cbDesignShellPanelTitle = title;
		const heading = panel.querySelector(':scope > .cb-core-design-shell__panel-header [data-cb-design-shell-panel-heading]');
		if (heading) heading.textContent = title;
	};

	applyPanelTitle(workflowSidebar, workflowLabel);
	applyPanelTitle(propertiesSidebar, propertiesLabel);
	if (workflowTitle) workflowTitle.remove();

	createDesignerShell(root, { defaultPanel: 'settings' });
	configureDesignerSidebar(root, {
		roles: { inspector: 'inspector', settings: 'settings' },
		activeRole: 'settings',
	});
	const stageHost = root.querySelector('.cb-core-design-shell__palette');
	const canvas = root.querySelector('.cb-core-design-shell__canvas-workarea');
	const stages = [
		{ id: 'trigger', step: '1', label: 'WHEN', description: 'Trigger', targetId: 'cb-automations-stage-trigger' },
		{ id: 'states', step: '2', label: 'GET DATA', description: 'Live state', targetId: 'cb-automations-stage-states' },
		{ id: 'conditions', step: '3', label: 'ONLY IF', description: 'Conditions', targetId: 'cb-automations-stage-conditions' },
		{ id: 'actions', step: '4', label: 'THEN', description: 'Actions', targetId: 'cb-automations-stage-actions' },
	];
	const stageLabels = window.cbAutomationsInspectorStrings?.navigation || {};
	stages.forEach((stage) => {
		stage.label = String(stageLabels[stage.id] || stage.label);
		stage.description = String(stageLabels[stage.id + 'Description'] || stage.description);
	});
	const definitionControl = root.closest('form')?.querySelector('[data-cb-automations-definition]');
	const parseDefinition = (value) => {
		try { return JSON.parse(String(value || '{}')); }
		catch { return {}; }
	};
	let currentDefinition = parseDefinition(definitionControl?.value);
	let activeStage = 'trigger';
	const itemsFor = (definition) => stages.map((stage) => ({
		...stage,
		status: (stage.id === 'trigger' && !definition?.trigger)
			|| (stage.id === 'actions' && (!Array.isArray(definition?.actions) || definition.actions.length === 0))
			? { label: String(stageLabels.required || 'Required'), tone: 'warning' }
			: null,
	}));
	if (stageHost instanceof HTMLElement && canvas instanceof HTMLElement) {
		const navigation = createDesignerNavigation({
			root,
			ariaLabel: String(stageLabels.ariaLabel || 'Workflow stages'),
			activeId: activeStage,
			items: itemsFor(currentDefinition),
		});
		stageHost.append(navigation.element);
		const syncStage = () => {
			const anchor = canvas.getBoundingClientRect().top + 64;
			for (const stage of stages) {
				const section = root.querySelector('#' + stage.targetId);
				if (section && section.getBoundingClientRect().top <= anchor) activeStage = stage.id;
			}
			navigation.setActive(activeStage);
		};
		canvas.addEventListener('scroll', syncStage, { passive: true });
		window.addEventListener('resize', syncStage);
		root.addEventListener('cb-automations:definitionchange', (event) => {
			currentDefinition = event.detail?.definition || currentDefinition;
			navigation.update(itemsFor(currentDefinition), { activeId: activeStage });
		});
		root.addEventListener('cb-automations:contextloaded', () => {
			activeStage = 'trigger';
			canvas.scrollTop = 0;
			navigation.setActive(activeStage);
		});
		syncStage();
	}

}
