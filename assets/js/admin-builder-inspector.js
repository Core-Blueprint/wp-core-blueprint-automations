(() => {
	'use strict';

	const strings = {
		inspector: 'Inspector',
		selectedStep: 'Selected step',
		selectStep: 'Select a workflow step to inspect its context.',
		stage: 'Stage',
		provider: 'Provider',
		capability: 'Capability',
		identifier: 'Identifier',
		condition: 'Condition',
		...(window.cbAutomationsInspectorStrings || {}),
	};

	const root = document.querySelector('[data-cb-automations-editor]');
	const shell = document.querySelector('[data-cb-automations-designer-shell]');
	const hidden = document.querySelector('[data-cb-automations-definition]');
	const dataNode = document.getElementById('cb-automations-editor-data');
	const panel = shell?.querySelector('[data-cb-design-shell-panel="inspector"]');
	const tab = shell?.querySelector('[data-cb-design-shell-tab="inspector"]');
	if (!root || !shell || !hidden || !dataNode || !panel || !tab) return;

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

	const renderInspector = (record) => {
		panel.replaceChildren();
		const heading = document.createElement('div');
		heading.className = 'cb-automations-panel-heading';
		const headingCopy = document.createElement('div');
		const eyebrow = document.createElement('span');
		eyebrow.className = 'cb-automations-panel-eyebrow';
		eyebrow.textContent = strings.inspector;
		const title = document.createElement('h2');
		title.textContent = record ? strings.selectedStep : strings.inspector;
		headingCopy.append(eyebrow, title);
		heading.append(headingCopy);
		panel.append(heading);

		if (!record) {
			const empty = document.createElement('p');
			empty.className = 'description';
			empty.textContent = strings.selectStep;
			panel.append(empty);
			return;
		}

		const value = record.value || {};
		const capability = capabilityFor(value.capability);
		const list = document.createElement('div');
		list.className = 'cb-automations-inspector__details';
		addRow(list, strings.stage, `${record.stage} · ${record.kind}`);
		addRow(list, strings.identifier, value.step_id || value.condition_id || '');
		addRow(list, strings.provider, capability?.provider?.name || value.capability?.provider || '');
		addRow(list, strings.capability, capability?.label || value.capability?.id || '');
		if (record.kind === 'Condition') addRow(list, strings.condition, value.operator || '');
		panel.append(list);
	};

	const selectCard = (card, { open = true } = {}) => {
		const record = cardRecord(card);
		if (!record) return;
		root.querySelectorAll('.cb-automations-step.is-builder-selected, .cb-automations-condition.is-builder-selected').forEach((item) => item.classList.remove('is-builder-selected'));
		card.classList.add('is-builder-selected');
		selected = record.key;
		renderInspector(record);
		if (open) tab.click();
	};

	const restoreSelection = () => {
		if (!selected) return;
		const cards = Array.from(root.querySelectorAll('.cb-automations-step, .cb-automations-condition'));
		const match = cards.find((card) => cardRecord(card)?.key === selected);
		if (match) selectCard(match, { open: false });
	};

	const decorate = () => {
		root.querySelectorAll('.cb-automations-step, .cb-automations-condition').forEach((card) => {
			if (!(card instanceof HTMLElement) || card.dataset.cbBuilderSelectable === '1') return;
			card.dataset.cbBuilderSelectable = '1';
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

	renderInspector(null);
	decorate();
	const observer = new MutationObserver(() => decorate());
	observer.observe(root, { childList: true, subtree: true });
})();
