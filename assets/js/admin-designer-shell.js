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

		let confirmedNavigation = false;
		window.addEventListener('beforeunload', (event) => {
			if (!confirmedNavigation && isDirty()) {
				event.preventDefault();
				event.returnValue = '';
			}
		});

		root.addEventListener('cb:design-shell:contextrequest', (event) => {
			if (event.detail?.control !== workflowSwitcher) return;
			const target = String(event.detail.value || '').trim();
			let url;
			try { url = new URL(target, window.location.href); }
			catch { return; }
			if (!target || url.origin !== window.location.origin) return;
			if (isDirty() && !window.confirm(
				String(window.cbAutomationsInspectorStrings?.unsavedChanges || 'This workflow has unsaved changes. Switch workflows and discard those changes?')
			)) return;
			confirmedNavigation = true;
			event.detail.respondWith(Promise.resolve({ route: url.href }));
			window.location.assign(url.href);
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
	const stageHost = root.querySelector('[data-cb-automations-stage-navigation]');
	const canvas = root.querySelector('.cb-core-design-shell__canvas');
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
		syncStage();
	}

}
