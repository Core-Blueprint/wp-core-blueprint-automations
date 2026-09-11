(() => {
	'use strict';

	function parseOutputValue(value) {
		const match = /^step:([a-z][a-z0-9_]{0,63}):([a-z][a-z0-9_]*)$/.exec(String(value || ''));
		return match ? { step_id: match[1], field: match[2] } : null;
	}

	function typeLabel(schema, strings = {}) {
		switch (schema?.type) {
			case 'integer':
			case 'number': return strings.type_number || 'Number';
			case 'boolean': return strings.type_boolean || 'True / false';
			case 'array': return strings.type_list || 'List';
			default: return strings.type_text || 'Text';
		}
	}

	function operatorPhrase(operator, leftType = '', strings = {}) {
		const key = String(operator || '');
		if (key === 'is_empty' && leftType === 'array') return strings.operator_array_is_empty || 'has no items';
		if (key === 'is_not_empty' && leftType === 'array') return strings.operator_array_is_not_empty || 'has items';

		const defaults = {
			equals: 'is',
			not_equals: 'is not',
			contains: 'contains',
			not_contains: 'does not contain',
			greater_than: 'is greater than',
			greater_than_or_equal: 'is at least',
			less_than: 'is less than',
			less_than_or_equal: 'is at most',
			is_empty: 'is empty',
			is_not_empty: 'is not empty',
		};
		return strings[`operator_${key}`] || defaults[key] || key.replaceAll('_', ' ');
	}

	function sentence(left, operator, right, arity = 2) {
		return [left, operator, arity === 1 ? '' : right].filter(Boolean).join(' ').trim();
	}

	if (typeof document === 'undefined') {
		globalThis.cbAutomationsConditionBuilderHelpers = {
			parseOutputValue,
			typeLabel,
			operatorPhrase,
			sentence,
		};
		return;
	}

	const root = document.querySelector('[data-cb-automations-editor]');
	const dataNode = document.getElementById('cb-automations-editor-data');
	if (!root || !dataNode) return;

	let data;
	try {
		data = JSON.parse(dataNode.textContent || '{}');
	} catch {
		return;
	}

	const catalog = Array.isArray(data.capabilities) ? data.capabilities : [];
	const operatorCatalog = data.operators || {};
	const strings = {
		only_continue_if: 'Only continue if',
		rule_preview: 'Rule preview',
		condition_value: 'Value',
		condition_rule: 'Rule',
		condition_compare: 'Compare with',
		choose_comparison: 'Choose a value or workflow data…',
		enter_value: 'Enter a value',
		workflow_data: 'Workflow data',
		unavailable_data: 'Unavailable data',
		type_text: 'Text',
		type_number: 'Number',
		type_boolean: 'True / false',
		type_list: 'List',
		operator_equals: 'is',
		operator_not_equals: 'is not',
		operator_contains: 'contains',
		operator_not_contains: 'does not contain',
		operator_greater_than: 'is greater than',
		operator_greater_than_or_equal: 'is at least',
		operator_less_than: 'is less than',
		operator_less_than_or_equal: 'is at most',
		operator_is_empty: 'is empty',
		operator_is_not_empty: 'is not empty',
		operator_array_is_empty: 'has no items',
		operator_array_is_not_empty: 'has items',
		...(window.cbAutomationsConditionBuilderStrings || {}),
	};

	function refKey(ref) {
		return [ref?.kind || '', ref?.provider || '', ref?.id || '', ref?.schema_version || ''].join(':');
	}

	const byRef = new Map(catalog.map((capability) => [refKey(capability?.reference || {}), capability]));

	function directSelect(wrap) {
		if (!wrap) return null;
		return Array.from(wrap.children).find((child) => child instanceof HTMLSelectElement) || null;
	}

	function stepLabel(card, fallback) {
		return card?.querySelector('.cb-automations-step-title')?.textContent?.trim()
			|| card?.querySelector('.cb-automations-capability-select')?.selectedOptions?.[0]?.textContent?.trim()
			|| fallback;
	}

	function collectSteps() {
		const steps = new Map();
		const register = (card, stepId) => {
			if (!card || !stepId) return;
			const capabilitySelect = card.querySelector('.cb-automations-capability-select');
			steps.set(stepId, {
				label: stepLabel(card, stepId),
				capability: capabilitySelect ? byRef.get(capabilitySelect.value) || null : null,
			});
		};

		register(root.querySelector('[data-cb-automations-trigger] > .cb-automations-step'), 'trigger_1');
		for (const selector of ['[data-cb-automations-states]', '[data-cb-automations-actions]']) {
			const container = root.querySelector(selector);
			for (const card of Array.from(container?.children || [])) {
				if (!(card instanceof HTMLElement) || !card.classList.contains('cb-automations-step')) continue;
				const stepId = card.querySelector('.cb-automations-step-head code')?.textContent?.trim() || '';
				register(card, stepId);
			}
		}
		return steps;
	}

	function schemaForOutputValue(value, steps) {
		const parsed = parseOutputValue(value);
		if (!parsed) return null;
		return steps.get(parsed.step_id)?.capability?.output_schema?.[parsed.field] || null;
	}

	function schemaForLiteral(wrap) {
		const control = wrap?.querySelector('.cb-automations-literal input, .cb-automations-literal textarea, .cb-automations-literal select');
		if (control instanceof HTMLTextAreaElement) return { type: 'array' };
		if (control instanceof HTMLSelectElement) return { type: 'boolean' };
		if (control instanceof HTMLInputElement && control.type === 'number') {
			return { type: control.step === 'any' ? 'number' : 'integer' };
		}
		return control ? { type: 'string' } : null;
	}

	function schemaForSource(select, wrap, steps) {
		if (!select) return null;
		if (select.value === 'literal') return schemaForLiteral(wrap);
		return schemaForOutputValue(select.value, steps);
	}

	function humanGroupLabel(stepId, steps) {
		return steps.get(stepId)?.label || strings.unavailable_data;
	}

	function groupOutputOptions(select, steps) {
		if (!select || select.dataset.cbConditionGrouped === '1') return;
		const options = Array.from(select.children).filter((child) => child instanceof HTMLOptionElement);
		const outputOptions = options.filter((option) => parseOutputValue(option.value));
		if (outputOptions.length === 0) {
			select.dataset.cbConditionGrouped = '1';
			return;
		}

		const selected = select.value;
		const groups = new Map();
		for (const option of outputOptions) {
			const parsed = parseOutputValue(option.value);
			if (!parsed) continue;
			let group = groups.get(parsed.step_id);
			if (!group) {
				group = document.createElement('optgroup');
				group.label = humanGroupLabel(parsed.step_id, steps);
				groups.set(parsed.step_id, group);
			}
			group.append(option);
		}
		for (const group of groups.values()) select.append(group);
		select.value = selected;
		select.dataset.cbConditionGrouped = '1';
	}

	function relabelSourceOptions(select) {
		if (!select) return;
		const empty = Array.from(select.options).find((option) => option.value === '');
		if (empty) empty.textContent = strings.choose_comparison;
		const literal = Array.from(select.options).find((option) => option.value === 'literal');
		if (literal) literal.textContent = strings.enter_value;
	}

	function setAccessibleLabel(control, label) {
		if (control) control.setAttribute('aria-label', label);
	}

	function ensureTypeBadge(wrap, schema) {
		if (!wrap) return;
		let badge = wrap.querySelector(':scope > .cb-automations-condition-type');
		if (!badge) {
			badge = document.createElement('span');
			badge.className = 'cb-automations-condition-type';
			wrap.append(badge);
		}
		badge.textContent = typeLabel(schema, strings);
		badge.hidden = !schema;
	}

	function literalText(wrap) {
		const control = wrap?.querySelector('.cb-automations-literal input, .cb-automations-literal textarea, .cb-automations-literal select');
		if (!control) return '';
		if (control instanceof HTMLSelectElement) return control.selectedOptions[0]?.textContent?.trim() || control.value;
		if (control instanceof HTMLTextAreaElement) return control.value.split(/\r?\n/).filter(Boolean).join(', ');
		return control.value;
	}

	function sourceText(select, wrap) {
		if (!select || select.value === '') return '';
		if (select.value === 'literal') return literalText(wrap);
		return select.selectedOptions[0]?.textContent?.trim() || '';
	}

	function ensureLead(card, grid) {
		let lead = card.querySelector(':scope > .cb-automations-condition-human-lead');
		if (!lead) {
			lead = document.createElement('div');
			lead.className = 'cb-automations-condition-human-lead';
			lead.textContent = strings.only_continue_if;
			grid.insertAdjacentElement('beforebegin', lead);
		}
	}

	function relabelOperators(select, leftType) {
		if (!select) return;
		for (const option of Array.from(select.options)) {
			option.textContent = operatorPhrase(option.value, leftType, strings);
		}
	}

	function updatePreview(card, grid, leftSelect, operatorSelect, rightWrap) {
		let summary = card.querySelector(':scope > .cb-automations-condition-summary');
		if (!summary) {
			summary = document.createElement('div');
			summary.className = 'cb-automations-condition-summary';
		}
		if (summary.previousElementSibling !== grid) grid.insertAdjacentElement('afterend', summary);

		const operator = operatorSelect?.value || '';
		const arity = Number(operatorCatalog[operator]?.arity || 2);
		const left = leftSelect?.selectedOptions[0]?.textContent?.trim() || '';
		const phrase = operatorPhrase(operator, card.dataset.conditionLeftType || '', strings);
		const rightSelect = rightWrap?.querySelector('.cb-automations-binding-source') || null;
		const right = arity === 1 ? '' : sourceText(rightSelect, rightWrap);

		summary.replaceChildren();
		const label = document.createElement('span');
		label.className = 'cb-automations-condition-summary__label';
		label.textContent = strings.rule_preview;
		const strong = document.createElement('strong');
		strong.textContent = sentence(left, phrase, right, arity);
		summary.append(label, strong);
	}

	function enhanceCondition(card, steps) {
		const grid = card.querySelector('.cb-automations-condition-grid');
		if (!grid || grid.children.length < 2) return;

		card.classList.add('is-human-condition');
		const leftWrap = grid.children[0];
		const operatorWrap = grid.children[1];
		const rightWrap = grid.children[2] || null;
		leftWrap.classList.add('cb-automations-condition-control', 'is-left');
		operatorWrap.classList.add('cb-automations-condition-control', 'is-operator');
		if (rightWrap) rightWrap.classList.add('cb-automations-condition-control', 'is-right');
		grid.classList.toggle('is-unary', !rightWrap);

		const leftSelect = directSelect(leftWrap);
		const operatorSelect = directSelect(operatorWrap);
		const rightSelect = rightWrap?.querySelector('.cb-automations-binding-source') || null;
		if (!leftSelect || !operatorSelect) return;

		ensureLead(card, grid);
		groupOutputOptions(leftSelect, steps);
		groupOutputOptions(rightSelect, steps);
		relabelSourceOptions(rightSelect);

		setAccessibleLabel(leftSelect, strings.condition_value);
		setAccessibleLabel(operatorSelect, strings.condition_rule);
		setAccessibleLabel(rightSelect, strings.condition_compare);
		const rightLiteral = rightWrap?.querySelector('.cb-automations-literal input, .cb-automations-literal textarea, .cb-automations-literal select');
		setAccessibleLabel(rightLiteral, strings.condition_compare);

		const leftSchema = schemaForSource(leftSelect, leftWrap, steps);
		card.dataset.conditionLeftType = leftSchema?.type || '';
		relabelOperators(operatorSelect, leftSchema?.type || '');
		ensureTypeBadge(leftWrap, leftSchema);

		const rightSchema = schemaForSource(rightSelect, rightWrap, steps);
		if (rightWrap) ensureTypeBadge(rightWrap, rightSchema);
		updatePreview(card, grid, leftSelect, operatorSelect, rightWrap);
	}

	function enhance() {
		const steps = collectSteps();
		const container = root.querySelector('[data-cb-automations-conditions]');
		const cards = Array.from(container?.children || []).filter((card) => card instanceof HTMLElement && card.classList.contains('cb-automations-condition'));
		for (const card of cards) enhanceCondition(card, steps);
	}

	let scheduled = false;
	function scheduleEnhance() {
		if (scheduled) return;
		scheduled = true;
		queueMicrotask(() => {
			scheduled = false;
			enhance();
		});
	}

	root.addEventListener('change', scheduleEnhance);
	root.addEventListener('input', scheduleEnhance);
	root.addEventListener('click', scheduleEnhance);
	enhance();
})();
