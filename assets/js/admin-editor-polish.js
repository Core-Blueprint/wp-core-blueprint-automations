(() => {
	'use strict';

	const root = document.querySelector('[data-cb-automations-editor]');
	const dataNode = document.getElementById('cb-automations-editor-data');
	if (!root || !dataNode) return;

	let data;
	try {
		data = JSON.parse(dataNode.textContent || '{}');
	} catch {
		return;
	}

	const strings = {
		...(data.strings || {}),
		...(window.cbAutomationsEditorPolishStrings || {}),
	};
	const catalog = Array.isArray(data.capabilities) ? data.capabilities : [];
	const byRef = new Map();

	function refKey(ref) {
		return [ref?.kind || '', ref?.provider || '', ref?.id || '', ref?.schema_version || ''].join(':');
	}

	for (const capability of catalog) {
		byRef.set(refKey(capability?.reference || {}), capability);
	}

	function el(tag, attrs = {}, text = null) {
		const node = document.createElement(tag);
		for (const [key, value] of Object.entries(attrs)) {
			if (key === 'class') node.className = value;
			else node.setAttribute(key, String(value));
		}
		if (text !== null) node.textContent = text;
		return node;
	}

	function humanizeField(field) {
		const specials = new Map([
			['id', 'ID'],
			['url', 'URL'],
			['api', 'API'],
			['ip', 'IP'],
			['uuid', 'UUID'],
			['sku', 'SKU'],
		]);
		const words = String(field || '').replace(/[._-]+/g, ' ').trim().split(/\s+/).filter(Boolean);
		return words.map((word, index) => {
			const lower = word.toLowerCase();
			if (specials.has(lower)) return specials.get(lower);
			return index === 0 ? lower.charAt(0).toUpperCase() + lower.slice(1) : lower;
		}).join(' ');
	}

	function capabilityFromSelect(select) {
		return select ? byRef.get(select.value) || null : null;
	}

	function referenceFromSelect(select) {
		if (!select || !select.value) return null;
		const parts = String(select.value).split(':');
		if (parts.length !== 4) return null;
		return { kind: parts[0], provider: parts[1], id: parts[2], schema_version: parts[3] };
	}

	function stepIdFromCard(card, fallback = '') {
		return card?.querySelector('.cb-automations-step-head code')?.textContent?.trim() || fallback;
	}

	function technicalType(schema) {
		if (!schema || !schema.type) return '';
		return schema.type === 'array' ? `array<${schema.items || 'mixed'}>` : String(schema.type);
	}

	function technicalSchemaList(label, schema) {
		const entries = Object.entries(schema || {});
		if (entries.length === 0) return null;
		const group = el('div', { class: 'cb-automations-technical-schema' });
		group.append(el('strong', {}, label));
		const list = el('div', { class: 'cb-automations-technical-schema__items' });
		for (const [field, definition] of entries) {
			const flags = [];
			if (definition?.required) flags.push(strings.field_required || 'Required');
			if (definition?.sensitive) flags.push(strings.sensitive || 'Sensitive');
			const suffix = flags.length > 0 ? ` · ${flags.join(' · ')}` : '';
			list.append(el('code', {}, `${field}: ${technicalType(definition)}${suffix}`));
		}
		group.append(list);
		return group;
	}

	function detailRow(label, value) {
		const row = el('div', { class: 'cb-automations-advanced__item' });
		row.append(el('dt', {}, label));
		const valueNode = el('dd');
		valueNode.append(el('code', {}, String(value || '—')));
		row.append(valueNode);
		return row;
	}

	function ensureAdvancedDetails(card, stepId, capability, storedReference = null) {
		if (!card || card.querySelector(':scope > .cb-automations-advanced')) return;
		const details = el('details', { class: 'cb-automations-advanced' });
		details.append(el('summary', {}, strings.advanced_details || 'Advanced details'));
		const body = el('div', { class: 'cb-automations-advanced__body' });
		const grid = el('dl', { class: 'cb-automations-advanced__grid' });
		const ref = capability?.reference || storedReference || {};
		grid.append(
			detailRow(strings.provider || 'Provider', capability?.provider?.name || ref.provider || ''),
			detailRow(strings.capability_id || 'Capability ID', ref.id || ''),
			detailRow(strings.schema_version || 'Schema version', ref.schema_version || ''),
			detailRow(strings.step_id || 'Step ID', stepId)
		);
		if (capability?.required_capability) {
			grid.append(detailRow(strings.required_capability || 'Required capability', capability.required_capability));
		}
		body.append(grid);
		const inputs = technicalSchemaList(strings.inputs || 'Inputs', capability?.input_schema);
		const outputs = technicalSchemaList(strings.outputs || 'Outputs', capability?.output_schema);
		if (inputs) body.append(inputs);
		if (outputs) body.append(outputs);
		details.append(body);
		card.append(details);
	}

	function ensureConditionDetails(card, conditionId) {
		if (!card || card.querySelector(':scope > .cb-automations-advanced')) return;
		const details = el('details', { class: 'cb-automations-advanced' });
		details.append(el('summary', {}, strings.advanced_details || 'Advanced details'));
		const body = el('div', { class: 'cb-automations-advanced__body' });
		const grid = el('dl', { class: 'cb-automations-advanced__grid' });
		grid.append(detailRow(strings.condition_id || 'Condition ID', conditionId));
		body.append(grid);
		details.append(body);
		card.append(details);
	}

	function setStepTitle(card, label) {
		const head = card?.querySelector('.cb-automations-step-head');
		if (!head) return;
		let title = head.querySelector('.cb-automations-step-title');
		if (!title) {
			title = el('strong', { class: 'cb-automations-step-title' });
			head.prepend(title);
		}
		title.textContent = label;
	}

	function collectSteps() {
		const steps = new Map();
		const register = (card, stepId) => {
			if (!card || !stepId) return;
			const select = card.querySelector('.cb-automations-capability-select');
			const capability = capabilityFromSelect(select);
			const storedReference = referenceFromSelect(select);
			const selectedLabel = select?.selectedOptions[0]?.textContent?.trim() || '';
			const label = capability?.label || selectedLabel || humanizeField(stepId);
			steps.set(stepId, { card, capability, label });
			if (card.querySelector('.cb-automations-step-head')) setStepTitle(card, label);
			ensureAdvancedDetails(card, stepId, capability, storedReference);
		};

		const triggerCard = root.querySelector('[data-cb-automations-trigger] > .cb-automations-step');
		register(triggerCard, 'trigger_1');

		for (const selector of ['[data-cb-automations-states]', '[data-cb-automations-actions]']) {
			const container = root.querySelector(selector);
			for (const card of Array.from(container?.children || [])) {
				if (!(card instanceof HTMLElement) || !card.classList.contains('cb-automations-step')) continue;
				register(card, stepIdFromCard(card));
			}
		}
		return steps;
	}

	function outputLabel(value, steps) {
		const match = /^step:([^:]+):(.+)$/.exec(String(value || ''));
		if (!match) return null;
		const step = steps.get(match[1]);
		if (!step) return null;
		return `${step.label} → ${humanizeField(match[2])}`;
	}

	function relabelOutputOptions(steps) {
		root.querySelectorAll('option[value^="step:"]').forEach((option) => {
			const label = outputLabel(option.value, steps);
			if (label && option.textContent !== label) option.textContent = label;
		});
	}

	function polishInputRows(steps) {
		for (const { card, capability } of steps.values()) {
			if (!capability) continue;
			const fields = Object.entries(capability.input_schema || {});
			const rows = card.querySelectorAll('.cb-automations-input-row');
			rows.forEach((row, index) => {
				const [field, schema] = fields[index] || [];
				if (!field || !schema) return;
				const strong = row.querySelector('.cb-automations-input-label strong');
				if (strong) strong.textContent = humanizeField(field);
				const meta = row.querySelector('.cb-automations-input-label .description');
				if (meta) {
					const labels = [schema.required ? (strings.field_required || 'Required') : (strings.field_optional || 'Optional')];
					if (schema.sensitive) labels.push(strings.sensitive || 'Sensitive');
					meta.textContent = labels.join(' · ');
				}
			});
		}
	}

	function literalText(wrap) {
		const control = wrap?.querySelector('.cb-automations-literal input, .cb-automations-literal textarea, .cb-automations-literal select');
		if (!control) return '';
		if (control instanceof HTMLSelectElement) return control.selectedOptions[0]?.textContent?.trim() || control.value;
		if (control instanceof HTMLTextAreaElement) return control.value.split(/\r?\n/).filter(Boolean).join(', ');
		return control.value;
	}

	function conditionRightText(grid) {
		const rightWrap = grid?.children?.[2];
		if (!rightWrap) return '';
		const source = rightWrap.querySelector('.cb-automations-binding-source');
		if (!source) return '';
		if (source.value === 'literal') return literalText(rightWrap);
		return source.selectedOptions[0]?.textContent?.trim() || '';
	}

	function polishConditions() {
		const container = root.querySelector('[data-cb-automations-conditions]');
		const cards = Array.from(container?.children || []).filter((card) => card instanceof HTMLElement && card.classList.contains('cb-automations-condition'));
		cards.forEach((card, index) => {
			const conditionId = stepIdFromCard(card, `condition_${index + 1}`);
			setStepTitle(card, `${strings.condition || 'Condition'} ${index + 1}`);
			const grid = card.querySelector('.cb-automations-condition-grid');
			if (!grid) return;
			const selects = grid.querySelectorAll(':scope > div > select');
			const left = selects[0]?.selectedOptions[0]?.textContent?.trim() || '';
			const operator = selects[1]?.selectedOptions[0]?.textContent?.trim() || '';
			const right = conditionRightText(grid);
			let summary = card.querySelector('.cb-automations-condition-summary');
			if (!summary) {
				summary = el('div', { class: 'cb-automations-condition-summary' });
				grid.insertAdjacentElement('beforebegin', summary);
			}
			summary.replaceChildren(
				el('span', { class: 'cb-automations-condition-summary__label' }, strings.reads_as || 'Reads as'),
				el('strong', {}, [left, operator.toLowerCase(), right].filter(Boolean).join(' '))
			);
			ensureConditionDetails(card, conditionId);
		});
	}

	function polish() {
		const steps = collectSteps();
		relabelOutputOptions(steps);
		polishInputRows(steps);
		polishConditions();
	}

	let scheduled = false;
	function schedulePolish() {
		if (scheduled) return;
		scheduled = true;
		queueMicrotask(() => {
			scheduled = false;
			polish();
		});
	}

	root.addEventListener('change', schedulePolish);
	root.addEventListener('input', schedulePolish);
	root.addEventListener('click', schedulePolish);
	polish();
})();
