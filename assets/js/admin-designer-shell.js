import { createDesignerShell, configureDesignerSidebar, createDesignerNavigation } from '@cb-core/design-editor';

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

		const launchRoot = root.closest('[data-cb-design-launch-root]');
		const contextDataNode = document.getElementById('cb-automations-editor-data');
		const contextError = (key, fallback) => new Error(
			String(window.cbAutomationsInspectorStrings?.[key] || fallback)
		);
		// An uncertain context cannot safely write either workflow. Force a
		// fresh server read rather than guessing whether the Base commit occurred.
		const fenceUncertainContext = () => {
			root.dataset.cbDesignShellSaveOutcomeUnknown = 'true';
			root.dataset.cbDesignShellSaveState = 'unknown';
			const message = 'Workflow context could not be verified. Reload the editor before saving.';
			const notice = root.querySelector?.('[data-cb-design-shell-persistent-notice]');
			if (notice) {
				notice.textContent = message;
				notice.setAttribute('role', 'alert');
				notice.hidden = false;
			}
			try {
				root.dispatchEvent(new CustomEvent('cb:design-shell:failure', {
					bubbles: true,
					detail: Object.freeze({ phase: 'context-integrity', message }),
				}));
			} catch { /* The sticky Save fence survives broken presentation. */ }
		};
		// Base B1 owns approval, modal, selection restoration and single-flight.
		// This consumer owns only the validated workflow transaction.
		let switchGeneration = 0;
		const saveIsUncertainOrBusy = () => (
			root.dataset?.cbDesignShellSaveState === 'saving'
			|| root.dataset?.cbDesignShellSaveState === 'unknown'
			|| root.dataset?.cbDesignShellSaveOutcomeUnknown === 'true'
		);
		const switchContext = async (url) => {
			if (saveIsUncertainOrBusy()) throw contextError('saveInProgress', 'Wait for the current save to finish.');
			const generation = ++switchGeneration;
			const sourceId = String(form?.querySelector('[name="workflow_id"]')?.value || '');
			const sourceRevision = String(form?.querySelector('[name="revision"]')?.value || '');
			const sourceDefinition = normalized(definition?.value);
			const sourceName = String(name?.value || '');
			const sourceActivation = String(activation?.value || '');
			const sourceRebind = Boolean(authority?.checked);
			const session = window.cbAutomationsEditorSession;
			const history = window.cbAutomationsBuilderHistory;
			if (!session?.replace || !session?.snapshot || !history?.prepareContext
				|| !history?.resetForContext || !history?.contextWitness
				|| !history?.snapshot || !contextDataNode) {
				throw contextError('contextUnavailable', 'Workflow switching is not available. Reload the editor.');
			}
			const id = Number(url.searchParams.get('workflow'));
			if (!Number.isSafeInteger(id) || id < 1
				|| url.searchParams.get('page') !== 'core-blueprint-automations-workflows'
				|| String(id) === sourceId) {
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
			const nextWorkflow = nextData?.workflow;
			if (Number(nextWorkflow?.id) !== id || !nextWorkflow?.definition || typeof nextWorkflow.definition !== 'object'
				|| Array.isArray(nextWorkflow.definition)) {
				throw contextError('loadFailed', 'The selected workflow data is invalid.');
			}
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
			const principalLabel = form?.querySelector('[data-cb-automations-principal-label]');
			const heading = document.querySelector('.cb-automations-editor-heading .cb-core-title');
			if (!nextId || !nextRevision || !currentId || !currentRevision
				|| !incomingName || !incomingActivation
				|| Boolean(principalLabel) !== Boolean(incomingPrincipal)) {
				throw contextError('loadFailed', 'The selected workflow form is incomplete.');
			}
			const revision = Number(nextRevision.value);
			if (String(nextId.value) !== String(id)
				|| !Number.isSafeInteger(revision) || revision < 1
				|| !String(incomingName.value || '').trim()
				|| !['enabled', 'disabled'].includes(String(incomingActivation.value))) {
				throw contextError('loadFailed', 'The selected workflow metadata is invalid.');
			}
			let nextDefinition;
			try {
				// Full domain codec validation before editing any live surface.
				nextDefinition = history.prepareContext(nextWorkflow.definition);
			} catch {
				throw contextError('loadFailed', 'The selected workflow definition is invalid.');
			}
			const messages = Array.from(nextDocument.querySelectorAll('.cb-automations-validation-list > li strong'))
				.map((node) => node.textContent?.trim() || '');
			if (nextData.validation !== undefined && !Array.isArray(nextData.validation)) {
				throw contextError('loadFailed', 'The selected workflow validation is invalid.');
			}
			const issues = (nextData.validation || []).map((issue, index) => {
				if (!issue || typeof issue !== 'object' || Array.isArray(issue)) {
					throw contextError('loadFailed', 'The selected workflow validation is invalid.');
				}
				return { ...issue, message: messages[index] || '' };
			});
			const oldStatuses = Array.from(document.querySelectorAll('.cb-automations-editor-statuses .cb-core-status'));
			const newStatuses = Array.from(nextDocument.querySelectorAll('.cb-automations-editor-statuses .cb-core-status'));
			if (oldStatuses.length !== newStatuses.length) {
				throw contextError('loadFailed', 'The selected workflow status is incomplete.');
			}
			// The Base context overlay deliberately marks the shell aria-busy
			// after requestTransition dispatch. Only the Save state is authoritative.
			if (saveIsUncertainOrBusy()) throw contextError('saveInProgress', 'Wait for the current save to finish.');
			if (generation !== switchGeneration
				|| String(currentId.value) !== sourceId || String(currentRevision.value) !== sourceRevision
				|| normalized(definition?.value) !== sourceDefinition
				|| String(name?.value || '') !== sourceName
				|| String(activation?.value || '') !== sourceActivation
				|| Boolean(authority?.checked) !== sourceRebind
				|| normalized(JSON.stringify(session.snapshot())) !== sourceDefinition
				|| normalized(JSON.stringify(history.snapshot())) !== sourceDefinition) {
				throw contextError('contextUnavailable', 'Workflow changed during selection. Reload the editor.');
			}
			// Stage the rollback journal before the first live mutation. The
			// Base witness includes baseline and Undo/Redo status, not just JSON.
			const historyBefore = history.contextWitness();
			const previous = {
				definition: session.snapshot(),
				id: currentId.value, revision: currentRevision.value,
				name: name?.value, activation: activation?.value,
				rebind: Boolean(authority?.checked),
				principal: principalLabel?.textContent, heading: heading?.textContent,
				context: contextDataNode.textContent,
				statuses: oldStatuses.map((node) => ({
					className: node.className,
					children: Array.from(node.childNodes).map((child) => child.cloneNode(true)),
				})),
			};
			const restorePresentation = () => {
				currentId.value = previous.id;
				currentRevision.value = previous.revision;
				if (name) name.value = previous.name;
				if (activation) activation.value = previous.activation;
				if (authority) authority.checked = previous.rebind;
				if (principalLabel) principalLabel.textContent = previous.principal;
				if (heading) heading.textContent = previous.heading;
				oldStatuses.forEach((node, index) => {
					node.className = previous.statuses[index].className;
					node.replaceChildren(...previous.statuses[index].children.map((child) => child.cloneNode(true)));
				});
				contextDataNode.textContent = previous.context;
			};
			// The only irreversible step is Base History context reset.
			// All fallible DOM/editor work precedes it; Base stage() validates before
			// committing and keeps observer exceptions outside its commit boundary.
			let commitStatus = 'not-started';
			try {
				session.replace(nextDefinition, { source: 'context' });
				currentId.value = String(id);
				currentRevision.value = nextRevision.value;
				if (name) name.value = incomingName.value;
				if (activation) activation.value = incomingActivation.value;
				if (authority) authority.checked = false;
				if (principalLabel) principalLabel.textContent = incomingPrincipal.textContent;
				if (heading) heading.textContent = incomingName.value;
				oldStatuses.forEach((node, index) => {
					node.className = newStatuses[index].className;
					node.replaceChildren(...Array.from(newStatuses[index].childNodes)
						.map((child) => document.importNode(child, true)));
				});
				contextDataNode.textContent = nextDataNode.textContent;
				commitStatus = 'attempted';
				const commit = history.resetForContext(nextDefinition);
				commitStatus = commit?.status === 'committed' || commit?.status === 'refused'
					? commit.status : 'unknown';
				if (commitStatus !== 'committed') {
					throw commit?.error || contextError('contextUnavailable', 'Workflow context commit could not be verified.');
				}
			} catch (failure) {
				// Once Base might have committed, restoring only editor JSON could
				// display an old workflow with the NEW History/Selection/epoch.
				if (commitStatus === 'unknown' || commitStatus === 'committed' || commitStatus === 'attempted') {
					fenceUncertainContext();
					throw contextError('contextUnavailable', 'Workflow context could not be verified. Reload before saving.');
				}
				let recovered = false;
				try {
					session.replace(previous.definition, { source: 'context-rollback' });
					restorePresentation();
					recovered = normalized(JSON.stringify(session.snapshot())) === sourceDefinition
						&& normalized(JSON.stringify(history.snapshot())) === sourceDefinition
						&& JSON.stringify(history.contextWitness()) === JSON.stringify(historyBefore)
						&& String(currentId.value) === sourceId && String(currentRevision.value) === sourceRevision;
				} catch { /* Refuse further writes if restoration itself fails. */ }
				if (!recovered) fenceUncertainContext();
				throw failure;
			}
			// Commit completed. Notifications and browser history are best-effort
			// presentation effects and must never manufacture a failed transaction.
			baseline = {
				definition: normalized(JSON.stringify(nextDefinition)),
				name: incomingName.value, activation: incomingActivation.value, rebind: false,
			};
			// dispatchEvent() does not propagate listener exceptions. Critical
			// consumer views therefore explicitly acknowledge their synchronous
			// postcommit refresh. Missing, duplicate or failed acknowledgements
			// cannot be mistaken for successful view synchronization.
			const views = new Map([
				['inspector', null], ['layers', null], ['stages', null],
			]);
			let notificationFailed = false;
			const acknowledgeView = (view, ok) => {
				if (!views.has(view) || views.get(view) !== null) {
					notificationFailed = true;
					return;
				}
				views.set(view, ok === true);
			};
			try {
				root.dispatchEvent(new CustomEvent('cb-automations:validation-saved', {
					bubbles: true, detail: Object.freeze({ valid: issues.length === 0, issues }),
				}));
			} catch { notificationFailed = true; }
			try {
				root.dispatchEvent(new CustomEvent('cb-automations:contextloaded', {
					bubbles: true,
					detail: Object.freeze({ workflowId: id, definition: nextDefinition, acknowledgeView }),
				}));
			} catch { notificationFailed = true; }
			if (notificationFailed || [...views.values()].some((ok) => ok !== true)) {
				fenceUncertainContext();
			}
			try { window.history.replaceState(window.history.state, '', url.href); } catch { /* Browser URL is not the data commit. */ }
			return Object.freeze({ workflowId: id });
		};

		// One adapter Save callback is shared by Base close and context authority.
		const saveBeforeSwitch = () => new Promise((resolve, reject) => {
			const saveButton = root.querySelector('[data-cb-design-shell-primary-action]');
			if (!(form instanceof HTMLFormElement) || !(saveButton instanceof HTMLButtonElement)
				|| window.cbAutomationsAsyncSaveReady !== true) {
				reject(contextError('contextUnavailable', 'Workflow saving is unavailable. Reload the editor.'));
				return;
			}
			if (saveIsUncertainOrBusy() || saveButton.disabled) {
				reject(contextError('saveInProgress', 'Wait for the current save to finish.'));
				return;
			}
			const onSaveChange = (event) => {
				const state = String(event.detail?.state || '');
				if (state !== 'saved' && state !== 'error' && state !== 'unknown') return;
				root.removeEventListener('cb:design-shell:savechange', onSaveChange);
				if (state !== 'saved') {
					reject(new Error(String(event.detail?.message || window.cbAutomationsSaveStrings?.failed || 'The automation could not be saved.')));
				} else if (isDirty()) {
					reject(new Error(String(window.cbAutomationsSaveStrings?.savedWithChanges || 'Automation saved. Newer edits are not saved yet.')));
				} else resolve();
			};
			root.addEventListener('cb:design-shell:savechange', onSaveChange);
			try { form.requestSubmit(saveButton); }
			catch (error) {
				root.removeEventListener('cb:design-shell:savechange', onSaveChange);
				reject(error);
			}
		});
		// Base owns the one Exit Guard, confirmation Modal, Escape handling and
		// beforeunload safety. Register only authoritative Automations adapters.
		const exitGuard = window.cbCore?.designerExitGuard;
		if (typeof exitGuard?.register !== 'function' || typeof exitGuard?.requestTransition !== 'function') {
			root.dispatchEvent(new CustomEvent('cb:design-shell:failure', {
				bubbles: true,
				detail: Object.freeze({
					phase: 'exit-guard',
					message: 'The shared Designer Exit Guard is unavailable. Reload the editor.',
				}),
			}));
		} else {
			const labels = window.cbAutomationsInspectorStrings || {};
			exitGuard.register(root, {
				isDirty,
				isUncertain: () => root.dataset.cbDesignShellSaveOutcomeUnknown === 'true'
					|| root.dataset.cbDesignShellSaveState === 'unknown',
				save: async () => {
					await saveBeforeSwitch();
					return !isDirty();
				},
				labels: {
					cleanTitle: String(labels.closeTitle || 'Close Automation Builder?'),
					cleanDescription: String(labels.closeDescription || 'Return to Automations?'),
					dirtyTitle: String(labels.unsavedTitle || 'Unsaved changes'),
					dirtyDescription: String(labels.closeUnsavedDescription || 'Save or discard your changes before closing the Builder.'),
					transitionDirtyDescription: String(labels.unsavedChanges || 'Save or discard your changes before switching workflows.'),
					transitionConfirm: String(labels.switchWorkflow || 'Switch workflow'),
					choiceTitle: String(labels.unsavedChoice || 'What should happen to your changes?'),
					save: String(labels.optionSave || 'Save changes'),
					discard: String(labels.optionDiscard || 'Discard changes'),
					close: String(labels.closeBuilder || 'Close Builder'),
					cancel: String(labels.cancel || 'Cancel'),
					busy: String(labels.saveInProgress || 'Wait for the current save to finish.'),
				},
			});
		}

		launchRoot?.addEventListener('cb:design-shell:contextrequest', (event) => {
			if (event.detail?.control !== workflowSwitcher) return;
			// Base B1's synchronous authorization window is the only caller.
			const target = String(event.detail.value || '').trim();
			let url;
			try { url = new URL(target, window.location.href); } catch { return; }
			if (!target || url.origin !== window.location.origin) return;
			if (typeof window.cbCore?.designerExitGuard?.requestTransition !== 'function') return;
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

	createDesignerShell(root, { defaultPanel: 'settings', defaultPanels: { palette: 'elements' } });
	configureDesignerSidebar(root, {
		roles: { inspector: 'inspector', settings: 'settings' },
		activeRole: 'settings',
	});
	const stageHost = root.querySelector('[data-cb-automations-elements-panel]');
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
