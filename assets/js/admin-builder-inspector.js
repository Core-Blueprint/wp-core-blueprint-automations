(() => {
	'use strict';

	const initialize = () => {
		const inspectorApi = window.cbCore?.designEditor?.shell?.inspector;
		if (typeof inspectorApi?.createIdentity !== 'function'
			|| typeof inspectorApi?.createControls !== 'function'
			|| typeof inspectorApi?.createSection !== 'function') return false;

	const strings = {
		inspector: 'Inspector',
		selectedStep: 'Selected step',
		selectStep: 'Select a workflow step to inspect its context.',
		selectTrigger: 'Configure trigger',
		stage: 'Stage',
		provider: 'Provider',
		capability: 'Capability',
		identifier: 'Identifier',
		condition: 'Condition',
		unavailable: 'This capability is unavailable. Choose a replacement on the canvas.',
		noSelection: 'No workflow step selected',
		editCanvas: 'Edit this step on the workflow canvas.',
		technicalDetails: 'Technical details',
		detailsSummary: 'Capability reference and workflow identifiers',
		...(window.cbAutomationsInspectorStrings || {}),
	};

	const root = document.querySelector('[data-cb-automations-editor]');
	const shell = document.querySelector('[data-cb-automations-designer-shell]');
	const hidden = document.querySelector('[data-cb-automations-definition]');
	const dataNode = document.getElementById('cb-automations-editor-data');
	const panel = shell?.querySelector('[data-cb-design-shell-panel="inspector"]');
	const panelBody = panel?.querySelector('.cb-core-design-shell__panel-body');
	const tab = shell?.querySelector('[data-cb-design-shell-tab="inspector"]');
	if (!root || !shell || !hidden || !dataNode || !panel || !panelBody || !tab) return;

	let editorData = {};
	try {
		editorData = JSON.parse(dataNode.textContent || '{}');
	} catch (error) {
		editorData = {};
	}
	const catalog = Array.isArray(editorData.capabilities) ? editorData.capabilities : [];
	let selected = null;

	const refKey = (ref) => [ref?.kind || '', ref?.provider || '', ref?.id || '', ref?.schema_version || ''].join(':');
	const capabilityFor = (ref) => catalog.find((item) => refKey(item?.reference) === refKey(ref)) || null;
	const currentDefinition = () => {
		try {
			return JSON.parse(hidden.value || '{}');
		} catch (error) {
			return {};
		}
	};
	const interactive = (target) => target?.closest?.('button, a, input, select, textarea, details, summary, label, [role="button"]');

	const cardRecord = (card) => {
		if (!(card instanceof HTMLElement)) return null;
		const definition = currentDefinition();
		const triggerRoot = root.querySelector('[data-cb-automations-trigger]');
		const statesRoot = root.querySelector('[data-cb-automations-states]');
		const conditionsRoot = root.querySelector('[data-cb-automations-conditions]');
		const actionsRoot = root.querySelector('[data-cb-automations-actions]');

		if (triggerRoot?.contains(card)) {
			return { key: 'trigger', stage: 'WHEN', kind: 'Trigger', value: definition.trigger || null };
		}
		if (statesRoot?.contains(card)) {
			const cards = Array.from(statesRoot.children).filter((item) => item instanceof HTMLElement && item.classList.contains('cb-automations-step'));
			const index = cards.indexOf(card);
			const value = index >= 0 ? definition.states?.[index] || null : null;
			return index >= 0 ? { key: `state:${value?.step_id || index}`, stage: 'GET DATA', kind: 'Data lookup', value } : null;
		}
		if (conditionsRoot?.contains(card)) {
			const cards = Array.from(conditionsRoot.children).filter((item) => item instanceof HTMLElement && item.classList.contains('cb-automations-condition'));
			const index = cards.indexOf(card);
			const value = index >= 0 ? definition.conditions?.[index] || null : null;
			return index >= 0 ? { key: `condition:${value?.condition_id || index}`, stage: 'ONLY IF', kind: 'Condition', value } : null;
		}
		if (actionsRoot?.contains(card)) {
			const cards = Array.from(actionsRoot.children).filter((item) => item instanceof HTMLElement && item.classList.contains('cb-automations-step'));
			const index = cards.indexOf(card);
			const value = index >= 0 ? definition.actions?.[index] || null : null;
			return index >= 0 ? { key: `action:${value?.step_id || index}`, stage: 'THEN', kind: 'Action', value } : null;
		}
		return null;
	};

	const addRow = (list, label, value) => {
		if (!value) return;
		const row = document.createElement('div');
		row.className = 'cb-automations-inspector__row';
		const term = document.createElement('span');
		term.className = 'cb-automations-inspector__label';
		term.textContent = label;
		const content = document.createElement('strong');
		content.textContent = String(value);
		row.append(term, content);
		list.append(row);
	};

	const sectionStates = new Map(); // UI-only, never persisted with workflow definitions.
	const renderInspector = (record) => {
		panelBody.replaceChildren();
		if (!record) {
			const identity = inspectorApi.createIdentity({
				label: strings.noSelection,
				compact: true,
				documentRef: document,
			});
			const guidance = document.createElement('p');
			guidance.className = 'cb-core-design-shell__panel-section-description';
			guidance.textContent = strings.selectStep;
			panelBody.append(identity.element, guidance);
			return;
		}

		const value = record.value || {};
		const capability = capabilityFor(value.capability);
		const displayLabel = capability?.label || value.capability?.id
			|| (record.kind === 'Trigger' ? strings.selectTrigger : record.kind === 'Condition' ? strings.condition : record.kind);
		const identity = inspectorApi.createIdentity({
			label: String(displayLabel),
			eyebrow: record.stage,
			context: String(displayLabel) === String(record.kind) ? '' : record.kind,
			compact: true,
			documentRef: document,
		});
		const controls = inspectorApi.createControls({ documentRef: document });
		const description = document.createElement('p');
		description.className = 'cb-core-design-shell__panel-section-description';
		description.textContent = strings.editCanvas;
		controls.append(description);
		if (value.capability && !capability) {
			const warning = document.createElement('p');
			warning.className = 'cb-automations-inspector__warning';
			warning.setAttribute('role', 'status');
			warning.textContent = strings.unavailable;
			controls.append(warning);
		}
		if (record.kind === 'Condition' && value.operator) {
			const primary = document.createElement('section');
			primary.className = 'cb-core-design-shell__panel-section';
			const title = document.createElement('h3');
			title.className = 'cb-core-design-shell__panel-section-title';
			title.textContent = strings.condition;
			primary.append(title);
			addRow(primary, strings.condition, String(value.operator));
			controls.append(primary);
		}

		const technical = inspectorApi.createSection({
			title: strings.technicalDetails,
			summary: strings.detailsSummary,
			expanded: sectionStates.get(record.key) === true,
			onToggle: (opened) => sectionStates.set(record.key, opened),
			documentRef: document,
		});
		const list = document.createElement('div');
		list.className = 'cb-automations-inspector__details';
		addRow(list, strings.stage, record.stage);
		addRow(list, strings.identifier, value.step_id || value.condition_id || '');
		addRow(list, strings.provider, capability?.provider?.name || value.capability?.provider || '');
		addRow(list, strings.capability, capability?.label || value.capability?.id || '');
		technical.body.append(list);
		controls.append(technical.element);
		panelBody.append(identity.element, controls);
	};

	const selectCard = (card, { open = true } = {}) => {
		const record = cardRecord(card);
		if (!record) return;
		root.querySelectorAll('.cb-automations-step.is-builder-selected, .cb-automations-condition.is-builder-selected').forEach((item) => (item.classList.remove('is-builder-selected'), item.removeAttribute('aria-current')));
		card.classList.add('is-builder-selected');
		card.setAttribute('aria-current', 'true');
		selected = record.key;
		shell.dispatchEvent(new CustomEvent('cb-automations:inspector-selection', {
			bubbles: true,
			detail: Object.freeze({ key: selected }),
		}));
		renderInspector(record);
		if (open) tab.click();
	};

	const restoreSelection = () => {
		if (!selected) return;
		const cards = Array.from(root.querySelectorAll('.cb-automations-step, .cb-automations-condition'));
		const match = cards.find((card) => cardRecord(card)?.key === selected);
		if (match) {
			selectCard(match, { open: false });
			return;
		}
		selected = null;
		shell.dispatchEvent(new CustomEvent('cb-automations:inspector-selection', {
			bubbles: true, detail: Object.freeze({ key: '' }),
		}));
		root.querySelectorAll('.cb-automations-step.is-builder-selected, .cb-automations-condition.is-builder-selected').forEach((item) => item.classList.remove('is-builder-selected'));
		renderInspector(null);
	};

	const decorate = () => {
		root.querySelectorAll('.cb-automations-step, .cb-automations-condition').forEach((card) => {
			if (!(card instanceof HTMLElement) || card.dataset.cbBuilderSelectable === '1') return;
			card.dataset.cbBuilderSelectable = '1';
			card.setAttribute('role', 'group');
			card.setAttribute('aria-label', String(card.querySelector('.cb-automations-step-head, .cb-automations-condition-head')?.textContent || strings.selectedStep).trim());
			card.tabIndex = card.tabIndex >= 0 ? card.tabIndex : 0;
			card.addEventListener('click', (event) => {
				if (interactive(event.target)) return;
				selectCard(card);
			});
			card.addEventListener('keydown', (event) => {
				if (event.target !== card || !['Enter', ' '].includes(event.key)) return;
				event.preventDefault();
				selectCard(card);
			});
		});
		restoreSelection();
	};

	// Base Layers publishes a selection intent. Inspector remains the single
	// selection presenter; selecting a layer never edits the workflow.
	shell.addEventListener('cb-automations:layerselect', (event) => {
		const key = String(event.detail?.key || '');
		const cards = Array.from(root.querySelectorAll('.cb-automations-step, .cb-automations-condition'));
		const card = cards.find((candidate) => cardRecord(candidate)?.key === key);
		if (card) selectCard(card);
	});

	renderInspector(null);
	decorate();
	const observer = new MutationObserver(() => decorate());
	observer.observe(root, { childList: true, subtree: true });
	root.addEventListener('cb-automations:definitionchange', () => queueMicrotask(restoreSelection));
	shell.addEventListener('cb-automations:contextloaded', () => {
		selected = null;
		shell.dispatchEvent(new CustomEvent('cb-automations:inspector-selection', {
			bubbles: true, detail: Object.freeze({ key: '' }),
		}));
		root.querySelectorAll('.is-builder-selected').forEach((card) => {
			card.classList.remove('is-builder-selected');
			card.removeAttribute('aria-current');
		});
		renderInspector(null);
	});
	return true;
	};
	if (!initialize()) window.addEventListener('cb:design-editor:ready', initialize, { once: true });
})();
