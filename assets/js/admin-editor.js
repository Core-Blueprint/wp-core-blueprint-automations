(() => {
	'use strict';

	const root = document.querySelector('[data-cb-automations-editor]');
	const dataNode = document.getElementById('cb-automations-editor-data');
	const form = document.querySelector('[data-cb-automations-editor-form]');
	const hidden = document.querySelector('[data-cb-automations-definition]');
	if (!root || !dataNode || !form || !hidden) return;

	let data;
	try {
		data = JSON.parse(dataNode.textContent || '{}');
	} catch {
		return;
	}

	const strings = data.strings || {};
	const operatorLabels = data.operator_labels || {};
	const operatorCatalog = data.operators || {};
	const catalog = Array.isArray(data.capabilities) ? data.capabilities : [];
	const state = JSON.parse(JSON.stringify(data.workflow?.definition || {
		definition_version: 1,
		trigger: null,
		states: [],
		conditions: [],
		actions: [],
	}));

	state.states = Array.isArray(state.states) ? state.states : [];
	state.conditions = Array.isArray(state.conditions) ? state.conditions : [];
	state.actions = Array.isArray(state.actions) ? state.actions : [];

	const byKind = { trigger: [], state: [], action: [] };
	const byRef = new Map();
	for (const capability of catalog) {
		const ref = capability?.reference || {};
		if (!byKind[ref.kind]) continue;
		byKind[ref.kind].push(capability);
		byRef.set(refKey(ref), capability);
	}

	const triggerRoot = root.querySelector('[data-cb-automations-trigger]');
	const statesRoot = root.querySelector('[data-cb-automations-states]');
	const conditionsRoot = root.querySelector('[data-cb-automations-conditions]');
	const actionsRoot = root.querySelector('[data-cb-automations-actions]');
	const addState = root.querySelector('[data-cb-add-state]');
	const addCondition = root.querySelector('[data-cb-add-condition]');
	const addAction = root.querySelector('[data-cb-add-action]');
	if (!triggerRoot || !statesRoot || !conditionsRoot || !actionsRoot || !addState || !addCondition || !addAction) return;

	function refKey(ref) {
		return [ref?.kind || '', ref?.provider || '', ref?.id || '', ref?.schema_version || ''].join(':');
	}

	function clone(value) {
		return JSON.parse(JSON.stringify(value));
	}

	function el(tag, attrs = {}, text = null) {
		const node = document.createElement(tag);
		for (const [key, value] of Object.entries(attrs)) {
			if (key === 'class') node.className = value;
			else if (key === 'type') node.type = value;
			else if (key === 'value') node.value = value;
			else if (key === 'disabled') node.disabled = Boolean(value);
			else node.setAttribute(key, String(value));
		}
		if (text !== null) node.textContent = text;
		return node;
	}

	function capabilityFor(step) {
		return step?.capability ? byRef.get(refKey(step.capability)) || null : null;
	}

	function capabilityLabel(capability) {
		const provider = capability?.provider?.name || capability?.reference?.provider || '';
		return `${provider} — ${capability?.label || capability?.reference?.id || ''}`;
	}

	function capabilitySelect(kind, current, onChange) {
		const select = el('select', { class: 'cb-automations-capability-select' });
		select.append(el('option', { value: '' }, strings.choose_capability || 'Choose a capability…'));
		for (const capability of byKind[kind] || []) {
			select.append(el('option', { value: refKey(capability.reference) }, capabilityLabel(capability)));
		}
		if (current && !byRef.has(refKey(current))) {
			select.append(el('option', { value: refKey(current) }, `${strings.unavailable || 'Unavailable'} — ${current.provider} / ${current.id} (v${current.schema_version})`));
		}
		select.value = current ? refKey(current) : '';
		select.addEventListener('change', () => onChange(select.value));
		return select;
	}

	function selectedReference(key) {
		const capability = byRef.get(key);
		return capability ? clone(capability.reference) : null;
	}

	function nextStepId(prefix) {
		const used = new Set([
			state.trigger?.step_id,
			...state.states.map((step) => step.step_id),
			...state.actions.map((step) => step.step_id),
		].filter(Boolean));
		let index = 1;
		while (used.has(`${prefix}_${index}`)) index += 1;
		return `${prefix}_${index}`;
	}

	function nextConditionId() {
		const used = new Set(state.conditions.map((condition) => condition.condition_id));
		let index = 1;
		while (used.has(`condition_${index}`)) index += 1;
		return `condition_${index}`;
	}

	function typeCompatible(source, target) {
		if (!source || !target) return false;
		if (source.type === target.type) {
			return source.type !== 'array' || source.items === target.items;
		}
		return source.type === 'integer' && target.type === 'number';
	}

	function outputEntries(context, index = 0) {
		const entries = [];
		const addStep = (step) => {
			const capability = capabilityFor(step);
			if (!step || !capability) return;
			for (const [field, schema] of Object.entries(capability.output_schema || {})) {
				entries.push({ step_id: step.step_id, field, schema, label: `${step.step_id} · ${field}` });
			}
		};

		addStep(state.trigger);
		if (context === 'state') {
			state.states.slice(0, index).forEach(addStep);
			return entries;
		}
		state.states.forEach(addStep);
		if (context === 'action') state.actions.slice(0, index).forEach(addStep);
		return entries;
	}

	function bindingKey(binding) {
		return binding?.source === 'step_output' ? `step:${binding.step_id}:${binding.field}` : '';
	}

	function parseOutputKey(value) {
		const match = /^step:([a-z][a-z0-9_]{0,63}):([a-z][a-z0-9_]*)$/.exec(value);
		return match ? { source: 'step_output', step_id: match[1], field: match[2] } : null;
	}

	function defaultLiteral(schema) {
		switch (schema?.type) {
			case 'integer':
			case 'number': return 0;
			case 'boolean': return false;
			case 'array': return [];
			default: return '';
		}
	}

	function literalSchema(value) {
		if (typeof value === 'boolean') return { type: 'boolean', items: null };
		if (Number.isInteger(value)) return { type: 'integer', items: null };
		if (typeof value === 'number' && Number.isFinite(value)) return { type: 'number', items: null };
		if (typeof value === 'string') return { type: 'string', items: null };
		if (!Array.isArray(value)) return null;
		let items = null;
		for (const item of value) {
			const schema = literalSchema(item);
			if (!schema || schema.type === 'array') return { type: 'array', items: 'mixed' };
			if (items === null) items = schema.type;
			else if (items !== schema.type) {
				if ([items, schema.type].every((type) => ['integer', 'number'].includes(type))) items = 'number';
				else return { type: 'array', items: 'mixed' };
			}
		}
		return { type: 'array', items };
	}

	function schemaForBinding(binding) {
		if (!binding) return null;
		if (binding.source === 'literal') return literalSchema(binding.value);
		if (binding.source !== 'step_output') return null;
		const step = [state.trigger, ...state.states, ...state.actions].find((item) => item?.step_id === binding.step_id);
		const capability = capabilityFor(step);
		return capability?.output_schema?.[binding.field] || null;
	}

	function literalControl(binding, schema, onChange) {
		const wrap = el('div', { class: 'cb-automations-literal' });
		const type = schema?.type || literalSchema(binding.value)?.type || 'string';
		if (type === 'boolean') {
			const select = el('select');
			select.append(el('option', { value: 'false' }, strings.false || 'False'));
			select.append(el('option', { value: 'true' }, strings.true || 'True'));
			select.value = binding.value === true ? 'true' : 'false';
			select.addEventListener('change', () => onChange(select.value === 'true'));
			wrap.append(select);
			return wrap;
		}
		if (type === 'array') {
			const textarea = el('textarea', { rows: '3', class: 'large-text code' });
			textarea.value = Array.isArray(binding.value) ? binding.value.join('\n') : '';
			textarea.addEventListener('input', () => {
				const lines = textarea.value === '' ? [] : textarea.value.split(/\r?\n/);
				const itemType = schema?.items || 'string';
				onChange(lines.map((line) => {
					const trimmed = line.trim();
					if (itemType === 'integer') return /^-?\d+$/.test(trimmed) ? Number.parseInt(trimmed, 10) : line;
					if (itemType === 'number') return trimmed !== '' && Number.isFinite(Number(trimmed)) ? Number(trimmed) : line;
					if (itemType === 'boolean') return trimmed.toLowerCase() === 'true' ? true : (trimmed.toLowerCase() === 'false' ? false : line);
					return line;
				}));
			});
			wrap.append(textarea, el('p', { class: 'description' }, strings.empty_array || 'One value per line.'));
			return wrap;
		}

		const input = el('input', { type: ['integer', 'number'].includes(type) ? 'number' : 'text', class: 'regular-text' });
		if (type === 'number') input.step = 'any';
		input.value = binding.value ?? '';
		input.addEventListener('input', () => {
			if (type === 'integer') onChange(/^-?\d+$/.test(input.value) ? Number.parseInt(input.value, 10) : input.value);
			else if (type === 'number') onChange(input.value !== '' && Number.isFinite(Number(input.value)) ? Number(input.value) : input.value);
			else onChange(input.value);
		});
		wrap.append(input);
		return wrap;
	}

	function bindingEditor(binding, targetSchema, outputs, onChange) {
		const wrap = el('div', { class: 'cb-automations-binding' });
		const select = el('select', { class: 'cb-automations-binding-source' });
		select.append(el('option', { value: '' }, strings.choose_source || 'Choose a source…'));

		const allowLiteral = !targetSchema?.sensitive || binding?.source === 'literal';
		if (allowLiteral) select.append(el('option', { value: 'literal' }, strings.literal || 'Literal value'));
		for (const output of outputs) {
			if (targetSchema && !typeCompatible(output.schema, targetSchema)) continue;
			select.append(el('option', { value: `step:${output.step_id}:${output.field}` }, output.label));
		}

		const currentKey = bindingKey(binding);
		if (binding?.source === 'step_output' && !Array.from(select.options).some((option) => option.value === currentKey)) {
			select.append(el('option', { value: currentKey }, `${strings.source_unavailable || 'Stored source is unavailable'} — ${binding.step_id} · ${binding.field}`));
		}
		select.value = binding?.source === 'literal' ? 'literal' : currentKey;
		select.addEventListener('change', () => {
			if (select.value === '') onChange(null, true);
			else if (select.value === 'literal') onChange({ source: 'literal', value: defaultLiteral(targetSchema) }, true);
			else onChange(parseOutputKey(select.value), true);
		});
		wrap.append(select);

		if (binding?.source === 'literal') {
			wrap.append(literalControl(binding, targetSchema, (value) => {
				binding.value = value;
				onChange(binding, false);
			}));
		}
		return wrap;
	}

	function renderInputs(step, context, index) {
		const capability = capabilityFor(step);
		const wrap = el('div', { class: 'cb-automations-inputs' });
		if (!capability) return wrap;
		const fields = Object.entries(capability.input_schema || {});
		if (fields.length === 0) {
			wrap.append(el('p', { class: 'description' }, strings.no_inputs || 'No inputs required.'));
			return wrap;
		}

		wrap.append(el('h4', {}, strings.inputs || 'Inputs'));
		const outputs = outputEntries(context, index);
		for (const [field, schema] of fields) {
			const row = el('div', { class: 'cb-automations-input-row' });
			const label = el('div', { class: 'cb-automations-input-label' });
			label.append(el('strong', {}, field));
			const meta = [schema.required ? (strings.field_required || 'Required') : (strings.field_optional || 'Optional')];
			meta.push(schema.type === 'array' ? `array<${schema.items}>` : schema.type);
			if (schema.sensitive) meta.push(strings.sensitive || 'Sensitive');
			label.append(el('span', { class: 'description' }, meta.join(' · ')));
			row.append(label);
			row.append(bindingEditor(step.bindings?.[field] || null, schema, outputs, (next, rerender) => {
				step.bindings = step.bindings || {};
				if (next) step.bindings[field] = next;
				else delete step.bindings[field];
				sync();
				if (rerender) renderAll();
			}));
			wrap.append(row);
		}
		return wrap;
	}

	function renderTrigger() {
		triggerRoot.replaceChildren();
		const card = el('div', { class: 'cb-automations-step' });
		card.append(capabilitySelect('trigger', state.trigger?.capability || null, (key) => {
			if (!key) state.trigger = null;
			else {
				const reference = selectedReference(key);
				if (reference) state.trigger = { step_id: 'trigger_1', capability: reference, bindings: {} };
			}
			renderAll();
		}));
		const capability = capabilityFor(state.trigger);
		if (capability?.description) card.append(el('p', { class: 'description' }, capability.description));
		triggerRoot.append(card);
	}

	function renderStep(step, kind, context, index, remove) {
		const card = el('div', { class: 'cb-automations-step cb-automations-repeatable-step' });
		const head = el('div', { class: 'cb-automations-step-head' });
		head.append(el('code', {}, step.step_id));
		const removeButton = el('button', { type: 'button', class: 'button-link-delete' }, strings.remove || 'Remove');
		removeButton.addEventListener('click', remove);
		head.append(removeButton);
		card.append(head);
		card.append(capabilitySelect(kind, step.capability, (key) => {
			const reference = selectedReference(key);
			if (!reference) return;
			step.capability = reference;
			step.bindings = {};
			renderAll();
		}));
		const capability = capabilityFor(step);
		if (capability?.description) card.append(el('p', { class: 'description' }, capability.description));
		card.append(renderInputs(step, context, index));
		return card;
	}

	function renderStates() {
		statesRoot.replaceChildren();
		state.states.forEach((step, index) => {
			statesRoot.append(renderStep(step, 'state', 'state', index, () => {
				state.states.splice(index, 1);
				renderAll();
			}));
		});
		addState.disabled = byKind.state.length === 0;
	}

	function renderActions() {
		actionsRoot.replaceChildren();
		state.actions.forEach((step, index) => {
			actionsRoot.append(renderStep(step, 'action', 'action', index, () => {
				state.actions.splice(index, 1);
				renderAll();
			}));
		});
		addAction.disabled = byKind.action.length === 0;
	}

	function conditionRightSchema(operator, leftSchema) {
		if (!leftSchema) return { type: 'string', items: null, sensitive: false };
		if (['contains', 'not_contains'].includes(operator) && leftSchema.type === 'array') {
			return { type: leftSchema.items || 'string', items: null, sensitive: false };
		}
		if (['greater_than', 'greater_than_or_equal', 'less_than', 'less_than_or_equal'].includes(operator)) {
			return { type: leftSchema.type === 'integer' ? 'integer' : 'number', items: null, sensitive: false };
		}
		return leftSchema;
	}

	function renderCondition(condition, index) {
		const card = el('div', { class: 'cb-automations-step cb-automations-condition' });
		const head = el('div', { class: 'cb-automations-step-head' });
		head.append(el('code', {}, condition.condition_id));
		const removeButton = el('button', { type: 'button', class: 'button-link-delete' }, strings.remove || 'Remove');
		removeButton.addEventListener('click', () => {
			state.conditions.splice(index, 1);
			renderAll();
		});
		head.append(removeButton);
		card.append(head);

		const outputs = outputEntries('condition');
		const grid = el('div', { class: 'cb-automations-condition-grid' });
		const leftWrap = el('div');
		leftWrap.append(el('label', {}, strings.condition_left || 'Value'));
		const leftSelect = el('select');
		for (const output of outputs) leftSelect.append(el('option', { value: `step:${output.step_id}:${output.field}` }, output.label));
		const currentLeft = bindingKey(condition.left);
		if (condition.left?.source === 'step_output' && !Array.from(leftSelect.options).some((option) => option.value === currentLeft)) {
			leftSelect.append(el('option', { value: currentLeft }, `${strings.source_unavailable || 'Stored source is unavailable'} — ${condition.left.step_id} · ${condition.left.field}`));
		}
		if (condition.left?.source === 'literal') leftSelect.append(el('option', { value: 'literal' }, strings.literal || 'Literal value'));
		leftSelect.value = condition.left?.source === 'literal' ? 'literal' : currentLeft;
		leftSelect.addEventListener('change', () => {
			condition.left = leftSelect.value === 'literal' ? { source: 'literal', value: '' } : parseOutputKey(leftSelect.value);
			const leftSchema = schemaForBinding(condition.left);
			condition.operator = 'equals';
			condition.right = { source: 'literal', value: defaultLiteral(leftSchema) };
			renderAll();
		});
		leftWrap.append(leftSelect);
		if (condition.left?.source === 'literal') {
			leftWrap.append(literalControl(condition.left, schemaForBinding(condition.left), (value) => {
				condition.left.value = value;
				sync();
			}));
		}
		grid.append(leftWrap);

		const leftSchema = schemaForBinding(condition.left);
		const operatorWrap = el('div');
		operatorWrap.append(el('label', {}, strings.condition_operator || 'Operator'));
		const operatorSelect = el('select');
		for (const [id, definition] of Object.entries(operatorCatalog)) {
			if (leftSchema && !definition.left_types?.includes(leftSchema.type) && id !== condition.operator) continue;
			operatorSelect.append(el('option', { value: id }, operatorLabels[id] || id.replaceAll('_', ' ')));
		}
		if (!Array.from(operatorSelect.options).some((option) => option.value === condition.operator)) {
			operatorSelect.append(el('option', { value: condition.operator }, condition.operator));
		}
		operatorSelect.value = condition.operator;
		operatorSelect.addEventListener('change', () => {
			condition.operator = operatorSelect.value;
			condition.right = operatorCatalog[condition.operator]?.arity === 1
				? null
				: { source: 'literal', value: defaultLiteral(conditionRightSchema(condition.operator, leftSchema)) };
			renderAll();
		});
		operatorWrap.append(operatorSelect);
		grid.append(operatorWrap);

		if (operatorCatalog[condition.operator]?.arity !== 1) {
			const rightWrap = el('div');
			rightWrap.append(el('label', {}, strings.condition_right || 'Compare with'));
			rightWrap.append(bindingEditor(condition.right, conditionRightSchema(condition.operator, leftSchema), outputs, (next, rerender) => {
				condition.right = next;
				sync();
				if (rerender) renderAll();
			}));
			grid.append(rightWrap);
		}

		card.append(grid);
		return card;
	}

	function renderConditions() {
		conditionsRoot.replaceChildren();
		state.conditions.forEach((condition, index) => conditionsRoot.append(renderCondition(condition, index)));
		addCondition.disabled = outputEntries('condition').length === 0;
	}

	function sync() {
		hidden.value = JSON.stringify(state);
	}

	function renderAll() {
		renderTrigger();
		renderStates();
		renderConditions();
		renderActions();
		sync();
	}

	addState.addEventListener('click', () => {
		const capability = byKind.state[0];
		if (!capability) return;
		state.states.push({ step_id: nextStepId('state'), capability: clone(capability.reference), bindings: {} });
		renderAll();
	});

	addAction.addEventListener('click', () => {
		const capability = byKind.action[0];
		if (!capability) return;
		state.actions.push({ step_id: nextStepId('action'), capability: clone(capability.reference), bindings: {} });
		renderAll();
	});

	addCondition.addEventListener('click', () => {
		const output = outputEntries('condition')[0];
		if (!output) return;
		state.conditions.push({
			condition_id: nextConditionId(),
			left: { source: 'step_output', step_id: output.step_id, field: output.field },
			operator: 'equals',
			right: { source: 'literal', value: defaultLiteral(output.schema) },
		});
		renderAll();
	});

	form.addEventListener('submit', sync);
	renderAll();
})();
