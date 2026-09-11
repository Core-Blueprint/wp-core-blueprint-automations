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
	} catch (error) {
		return;
	}

	const strings = data.strings || {};
	const operatorLabels = data.operator_labels || {};
	const catalog = Array.isArray(data.capabilities) ? data.capabilities : [];
	const operatorCatalog = data.operators || {};
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
	catalog.forEach((capability) => {
		const ref = capability?.reference || {};
		if (!byKind[ref.kind]) return;
		byKind[ref.kind].push(capability);
		byRef.set(refKey(ref), capability);
	});

	const triggerRoot = root.querySelector('[data-cb-automations-trigger]');
	const statesRoot = root.querySelector('[data-cb-automations-states]');
	const conditionsRoot = root.querySelector('[data-cb-automations-conditions]');
	const actionsRoot = root.querySelector('[data-cb-automations-actions]');
	const addState = root.querySelector('[data-cb-add-state]');
	const addCondition = root.querySelector('[data-cb-add-condition]');
	const addAction = root.querySelector('[data-cb-add-action]');

	function refKey(ref) {
		return [ref?.kind || '', ref?.provider || '', ref?.id || '', ref?.schema_version || ''].join(':');
	}

	function clone(value) {
		return JSON.parse(JSON.stringify(value));
	}

	function node(tag, attrs = {}, text = null) {
		const element = document.createElement(tag);
		Object.entries(attrs).forEach(([key, value]) => {
			if (key === 'class') element.className = value;
			else if (key === 'type') element.type = value;
			else if (key === 'value') element.value = value;
			else if (key === 'checked') element.checked = Boolean(value);
			else if (key === 'disabled') element.disabled = Boolean(value);
			else element.setAttribute(key, String(value));
		});
		if (text !== null) element.textContent = text;
		return element;
	}

	function capabilityLabel(capability) {
		const provider = capability?.provider?.name || capability?.reference?.provider || '';
		return `${provider} — ${capability?.label || capability?.reference?.id || ''}`;
	}

	function capabilityFor(step) {
		return step?.capability ? byRef.get(refKey(step.capability)) || null : null;
	}

	function capabilitySelect(kind, current, onChange) {
		const select = node('select', { class: 'cb-automations-capability-select' });
		select.append(node('option', { value: '' }, strings.choose_capability || 'Choose a capability…'));

		(byKind[kind] || []).forEach((capability) => {
			const key = refKey(capability.reference);
			const option = node('option', { value: key }, capabilityLabel(capability));
			if (current && refKey(current) === key) option.selected = true;
			select.append(option);
		});

		if (current && !byRef.has(refKey(current))) {
			const label = `${strings.unavailable || 'Unavailable'} — ${current.provider} / ${current.id} (v${current.schema_version})`;
			const option = node('option', { value: refKey(current) }, label);
			option.selected = true;
			option.dataset.unavailable = '1';
			select.append(option);
		}

		select.addEventListener('change', () => onChange(select.value));
		return select;
	}

	function selectedReference(key) {
		const capability = byRef.get(key);
		return capability ? clone(capability.reference) : null;
	}

	function allStepIds() {
		const ids = new Set();
		if (state.trigger?.step_id) ids.add(state.trigger.step_id);
		state.states.forEach((step) => ids.add(step.step_id));
		state.actions.forEach((step) => ids.add(step.step_id));
		return ids;
	}

	function nextId(prefix) {
		const ids = allStepIds();
		let index = 1;
		while (ids.has(`${prefix}_${index}`)) index += 1;
		return `${prefix}_${index}`;
	}

	function nextConditionId() {
		const ids = new Set(state.conditions.map((condition) => condition.condition_id));
		let index = 1;
		while (ids.has(`condition_${index}`)) index += 1;
		return `condition_${index}`;
	}

	function fieldSchema(capability, field, output = false) {
		const schema = output ? capability?.output_schema : capability?.input_schema;
		return schema && typeof schema[field] === 'object' ? schema[field] : null;
	}

	function typeCompatible(source, target) {
		if (!source || !target) return false;
		if (source.type === target.type) {
			if (source.type !== 'array') return true;
			return source.items === target.items;
		}
		return source.type === 'integer' && target.type === 'number';
	}

	function outputsForContext(context, index) {
		const outputs = [];
		const pushStep = (step) => {
			if (!step) return;
			const capability = capabilityFor(step);
			if (!capability) return;
			Object.entries(capability.output_schema || {}).forEach(([field, schema]) => {
				outputs.push({
					step_id: step.step_id,
					field,
					schema,
					label: `${step.step_id} · ${field}`,
				});
			});
		};

		pushStep(state.trigger);
		if (context === 'state') {
			state.states.slice(0, index).forEach(pushStep);
			return outputs;
		}

		state.states.forEach(pushStep);
		if (context === 'action') state.actions.slice(0, index).forEach(pushStep);
		return outputs;
	}

	function bindingKey(binding) {
		if (!binding || binding.source !== 'step_output') return '';
		return `step:${binding.step_id}:${binding.field}`;
	}

	function parseOutputKey(value) {
		if (!value.startsWith('step:')) return null;
		const parts = value.split(':');
		if (parts.length !== 3) return null;
		return { source: 'step_output', step_id: parts[1], field: parts[2] };
	}

	function defaultLiteral(schema) {
		switch (schema?.type) {
			case 'integer': return 0;
			case 'number': return 0;
			case 'boolean': return false;
			case 'array': return [];
			default: return '';
		}
	}

	function literalSchema(value) {
		if (typeof value === 'boolean') return { type: 'boolean', items: null };
		if (Number.isInteger(value)) return { type: 'integer', items: null };
		if (typeof value === 'number') return { type: 'number', items: null };
		if (typeof value === 'string') return { type: 'string', items: null };
		if (Array.isArray(value)) {
			let itemType = null;
			for (const item of value) {
				const current = literalSchema(item);
				if (!current || current.type === 'array') return { type: 'array', items: 'mixed' };
				if (itemType === null) itemType = current.type;
				else if (itemType !== current.type) {
					if ([itemType, current.type].every((type) => ['integer', 'number'].includes(type))) itemType = 'number';
					else return { type: 'array', items: 'mixed' };
				}
			}
			return { type: 'array', items: itemType };
		}
		return null;
	}

	function outputSchema(binding) {
		if (!binding || binding.source !== 'step_output') return null;
		const step = [state.trigger, ...state.states, ...state.actions].find((item) => item?.step_id === binding.step_id);
		const capability = capabilityFor(step);
		return capability ? fieldSchema(capability, binding.field, true) : null;
	}

	function bindingSchema(binding) {
		if (!binding) return null;
		return binding.source === 'literal' ? literalSchema(binding.value) : outputSchema(binding);
	}

	function renderLiteralControl(binding, schema, onUpdate) {
		const wrapper = node('div', { class: 'cb-automations-literal' });
		const type = schema?.type || literalSchema(binding.value)?.type || 'string';

		if (type === 'boolean') {
			const select = node('select');
			select.append(node('option', { value: 'false' }, strings.false || 'False'));
			select.append(node('option', { value: 'true' }, strings.true || 'True'));
			select.value = binding.value === true ? 'true' : 'false';
			select.addEventListener('change', () => onUpdate(select.value === 'true'));
			wrapper.append(select);
			return wrapper;
		}

		if (type === 'array') {
			const textarea = node('textarea', { rows: '3', class: 'large-text code' });
			textarea.value = Array.isArray(binding.value) ? binding.value.join('\n') : '';
			textarea.addEventListener('input', () => {
				const lines = textarea.value === '' ? [] : textarea.value.split(/\r?\n/);
				const itemType = schema?.items || 'string';
				const values = lines.map((line) => {
					if (itemType === 'integer') return /^-?\d+$/.test(line.trim()) ? Number.parseInt(line.trim(), 10) : line;
					if (itemType === 'number') return line.trim() !== '' && Number.isFinite(Number(line)) ? Number(line) : line;
					if (itemType === 'boolean') return line.trim().toLowerCase() === 'true' ? true : (line.trim().toLowerCase() === 'false' ? false : line);
					return line;
				});
				onUpdate(values);
			});
			wrapper.append(textarea, node('p', { class: 'description' }, strings.empty_array || 'One value per line.'));
			return wrapper;
		}

		const input = node('input', {
			type: ['integer', 'number'].includes(type) ? 'number' : 'text',
			class: 'regular-text',
		});
		if (type === 'number') input.step = 'any';
		input.value = binding.value ?? '';
		input.addEventListener('input', () => {
			if (type === 'integer') onUpdate(/^-?\d+$/.test(input.value) ? Number.parseInt(input.value, 10) : input.value);
			else if (type === 'number') onUpdate(input.value !== '' && Number.isFinite(Number(input.value)) ? Number(input.value) : input.value);
			else onUpdate(input.value);
		});
		wrapper.append(input);
		return wrapper;
	}

	function bindingEditor(binding, targetSchema, outputs, onUpdate, options = {}) {
		const wrapper = node('div', { class: 'cb-automations-binding' });
		const select = node('select', { class: 'cb-automations-binding-source' });
		const allowLiteral = options.allowLiteral !== false && (!targetSchema?.sensitive || binding?.source === 'literal');

		if (allowLiteral) select.append(node('option', { value: 'literal' }, strings.literal || 'Literal value'));

		outputs
			.filter((output) => !targetSchema || typeCompatible(output.schema, targetSchema))
			.forEach((output) => select.append(node('option', { value: `step:${output.step_id}:${output.field}` }, output.label)));

		const currentKey = bindingKey(binding);
		if (binding?.source === 'step_output' && !Array.from(select.options).some((option) => option.value === currentKey)) {
			select.append(node('option', { value: currentKey }, `${strings.source_unavailable || 'Stored source is unavailable'} — ${binding.step_id} · ${binding.field}`));
		}

		if (!binding) {
			if (allowLiteral) binding = { source: 'literal', value: defaultLiteral(targetSchema) };
			else {
				const firstOutput = outputs.find((output) => !targetSchema || typeCompatible(output.schema, targetSchema));
				binding = firstOutput ? { source: 'step_output', step_id: firstOutput.step_id, field: firstOutput.field } : null;
			}
		}

		if (binding?.source === 'literal' && !allowLiteral) {
			select.append(node('option', { value: 'literal' }, strings.literal || 'Literal value'));
		}

		select.value = binding?.source === 'literal' ? 'literal' : bindingKey(binding);
		select.addEventListener('change', () => {
			if (select.value === 'literal') onUpdate({ source: 'literal', value: defaultLiteral(targetSchema) }, true);
			else onUpdate(parseOutputKey(select.value), true);
		});
		wrapper.append(select);

		if (binding?.source === 'literal') {
			wrapper.append(renderLiteralControl(binding, targetSchema, (value) => {
				binding.value = value;
				onUpdate(binding, false);
			}));
		}

		return wrapper;
	}

	function renderInputs(step, context, index) {
		const capability = capabilityFor(step);
		const container = node('div', { class: 'cb-automations-inputs' });
		if (!capability) return container;
		const schema = capability.input_schema || {};
		const fields = Object.keys(schema);
		if (fields.length === 0) {
			container.append(node('p', { class: 'description' }, strings.no_inputs || 'No inputs required.'));
			return container;
		}

		container.append(node('h4', {}, strings.inputs || 'Inputs'));
		const outputs = outputsForContext(context, index);
		fields.forEach((field) => {
			const definition = schema[field] || {};
			const row = node('div', { class: 'cb-automations-input-row' });
			const label = node('div', { class: 'cb-automations-input-label' });
			label.append(node('strong', {}, field));
			const meta = [];
			meta.push(definition.required ? (strings.field_required || 'Required') : (strings.field_optional || 'Optional'));
			meta.push(definition.type === 'array' ? `array<${definition.items}>` : definition.type);
			if (definition.sensitive) meta.push(strings.sensitive || 'Sensitive');
			label.append(node('span', { class: 'description' }, meta.join(' · ')));
			row.append(label);

			const current = step.bindings?.[field] || null;
			row.append(bindingEditor(current, definition, outputs, (binding, rerender) => {
				step.bindings = step.bindings || {};
				if (binding) step.bindings[field] = binding;
				else delete step.bindings[field];
				sync();
				if (rerender) renderAll();
			}));
			container.append(row);
		});
		return container;
	}

	function renderTrigger() {
		triggerRoot.replaceChildren();
		const block = node('div', { class: 'cb-automations-step' });
		block.append(capabilitySelect('trigger', state.trigger?.capability || null, (key) => {
			if (!key) state.trigger = null;
			else {
				const reference = selectedReference(key);
				if (reference) state.trigger = { step_id: 'trigger_1', capability: reference, bindings: {} };
			}
			sync();
			renderAll();
		}));
		const capability = capabilityFor(state.trigger);
		if (capability?.description) block.append(node('p', { class: 'description' }, capability.description));
		triggerRoot.append(block);
	}

	function stepCard(step, kind, context, index, onRemove) {
		const card = node('div', { class: 'cb-automations-step cb-automations-repeatable-step' });
		const head = node('div', { class: 'cb-automations-step-head' });
		head.append(node('code', {}, step.step_id));
		const remove = node('button', { type: 'button', class: 'button-link-delete' }, strings.remove || 'Remove');
		remove.addEventListener('click', onRemove);
		head.append(remove);
		card.append(head);
		card.append(capabilitySelect(kind, step.capability, (key) => {
			const reference = selectedReference(key);
			if (!reference) return;
			step.capability = reference;
			step.bindings = {};
			sync();
			renderAll();
		}));
		const capability = capabilityFor(step);
		if (capability?.description) card.append(node('p', { class: 'description' }, capability.description));
		card.append(renderInputs(step, context, index));
		return card;
	}

	function renderStates() {
		statesRoot.replaceChildren();
		state.states.forEach((step, index) => {
			statesRoot.append(stepCard(step, 'state', 'state', index, () => {
				state.states.splice(index, 1);
				sync();
				renderAll();
			}));
		});
		addState.disabled = byKind.state.length === 0;
	}

	function renderActions() {
		actionsRoot.replaceChildren();
		state.actions.forEach((step, index) => {
			actionsRoot.append(stepCard(step, 'action', 'action', index, () => {
				state.actions.splice(index, 1);
				sync();
				renderAll();
			}));
		});
		addAction.disabled = byKind.action.length === 0;
	}

	function conditionLeftOutputs() {
		return outputsForContext('condition', 0);
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
		const card = node('div', { class: 'cb-automations-step cb-automations-condition' });
		const head = node('div', { class: 'cb-automations-step-head' });
		head.append(node('code', {}, condition.condition_id));
		const remove = node('button', { type: 'button', class: 'button-link-delete' }, strings.remove || 'Remove');
		remove.addEventListener('click', () => {
			state.conditions.splice(index, 1);
			sync();
			renderAll();
		});
		head.append(remove);
		card.append(head);

		const outputs = conditionLeftOutputs();
		const grid = node('div', { class: 'cb-automations-condition-grid' });
		const leftWrap = node('div');
		leftWrap.append(node('label', {}, strings.condition_left || 'Value'));
		const leftSelect = node('select');
		outputs.forEach((output) => leftSelect.append(node('option', { value: `step:${output.step_id}:${output.field}` }, output.label)));
		const currentLeftKey = bindingKey(condition.left);
		if (condition.left?.source === 'step_output' && !Array.from(leftSelect.options).some((option) => option.value === currentLeftKey)) {
			leftSelect.append(node('option', { value: currentLeftKey }, `${strings.source_unavailable || 'Stored source is unavailable'} — ${condition.left.step_id} · ${condition.left.field}`));
		}
		if (condition.left?.source === 'literal') {
			leftSelect.append(node('option', { value: 'literal' }, strings.literal || 'Literal value'));
		}
		leftSelect.value = condition.left?.source === 'literal' ? 'literal' : currentLeftKey;
		leftSelect.addEventListener('change', () => {
			condition.left = leftSelect.value === 'literal'
				? { source: 'literal', value: '' }
				: parseOutputKey(leftSelect.value);
			condition.operator = 'equals';
			condition.right = { source: 'literal', value: '' };
			sync();
			renderAll();
		});
		leftWrap.append(leftSelect);
		if (condition.left?.source === 'literal') {
			leftWrap.append(renderLiteralControl(condition.left, bindingSchema(condition.left), (value) => {
				condition.left.value = value;
				sync();
			}));
		}
		grid.append(leftWrap);

		const leftSchema = bindingSchema(condition.left);
		const operatorWrap = node('div');
		operatorWrap.append(node('label', {}, strings.condition_operator || 'Operator'));
		const operatorSelect = node('select');
		Object.entries(operatorCatalog).forEach(([id, definition]) => {
			if (leftSchema && !definition.left_types?.includes(leftSchema.type) && id !== condition.operator) return;
			operatorSelect.append(node('option', { value: id }, operatorLabels[id] || id.replaceAll('_', ' ')));
		});
		if (!Array.from(operatorSelect.options).some((option) => option.value === condition.operator)) {
			operatorSelect.append(node('option', { value: condition.operator }, condition.operator));
		}
		operatorSelect.value = condition.operator;
		operatorSelect.addEventListener('change', () => {
			condition.operator = operatorSelect.value;
			const definition = operatorCatalog[condition.operator];
			condition.right = definition?.arity === 1 ? null : { source: 'literal', value: defaultLiteral(conditionRightSchema(condition.operator, leftSchema)) };
			sync();
			renderAll();
		});
		operatorWrap.append(operatorSelect);
		grid.append(operatorWrap);

		const operatorDefinition = operatorCatalog[condition.operator] || null;
		if (!operatorDefinition || operatorDefinition.arity !== 1) {
			const rightWrap = node('div');
			rightWrap.append(node('label', {}, strings.condition_right || 'Compare with'));
			const target = conditionRightSchema(condition.operator, leftSchema);
			rightWrap.append(bindingEditor(condition.right, target, outputs, (binding, rerender) => {
				condition.right = binding;
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
		addCondition.disabled = conditionLeftOutputs().length === 0;
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
		state.states.push({ step_id: nextId('state'), capability: clone(capability.reference), bindings: {} });
		renderAll();
	});

	addAction.addEventListener('click', () => {
		const capability = byKind.action[0];
		if (!capability) return;
		state.actions.push({ step_id: nextId('action'), capability: clone(capability.reference), bindings: {} });
		renderAll();
	});

	addCondition.addEventListener('click', () => {
		const output = conditionLeftOutputs()[0];
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
