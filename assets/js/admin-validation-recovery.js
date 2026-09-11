(() => {
	'use strict';

	function pathParts(path) {
		return String(path || '').split('.').filter(Boolean);
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
		return String(field || '')
			.replace(/[._-]+/g, ' ')
			.trim()
			.split(/\s+/)
			.filter(Boolean)
			.map((word, index) => {
				const lower = word.toLowerCase();
				if (specials.has(lower)) return specials.get(lower);
				return index === 0 ? lower.charAt(0).toUpperCase() + lower.slice(1) : lower;
			})
			.join(' ');
	}

	function indexedLabel(template, index) {
		return String(template || '').replace('%d', String(Number(index) + 1));
	}

	function fieldFromIssue(issue) {
		if (issue?.context?.field) return String(issue.context.field);
		const parts = pathParts(issue?.path);
		const bindingsIndex = parts.indexOf('bindings');
		return bindingsIndex >= 0 && parts[bindingsIndex + 1] ? parts[bindingsIndex + 1] : '';
	}

	function locationLabel(issue, strings = {}) {
		const parts = pathParts(issue?.path);
		const root = parts[0] || '';
		let label = strings.workflow || 'Workflow';

		if (root === 'trigger') {
			label = strings.when_trigger || 'When · Trigger';
		} else if (root === 'states' && /^\d+$/.test(parts[1] || '')) {
			label = indexedLabel(strings.get_data || 'Get data · Data lookup %d', Number(parts[1]));
		} else if (root === 'conditions' && /^\d+$/.test(parts[1] || '')) {
			label = indexedLabel(strings.only_if || 'Only if · Condition %d', Number(parts[1]));
		} else if (root === 'actions' && /^\d+$/.test(parts[1] || '')) {
			label = indexedLabel(strings.then_action || 'Then · Action %d', Number(parts[1]));
		} else if (root === 'actions') {
			label = strings.then_actions || 'Then · Actions';
		}

		const field = fieldFromIssue(issue);
		return field ? `${label} · ${humanizeField(field)}` : label;
	}

	function recoveryLabel(issue, strings = {}) {
		const code = String(issue?.code || '');
		if (code === 'workflow.trigger_missing') return strings.choose_trigger || 'Choose trigger';
		if (code === 'workflow.action_missing') return strings.add_action || 'Add action';
		if (code === 'binding.trigger_has_bindings') return strings.review_trigger || 'Review trigger';
		if (code === 'binding.input_unknown') return strings.review_capability || 'Review capability';
		if (code === 'binding.required_missing') return strings.connect_input || 'Connect input';
		if (code.startsWith('binding.') || code === 'privacy.sensitive_literal') return strings.fix_input || 'Fix input';
		if (code.startsWith('dependency.') || code.startsWith('capability.')) return strings.choose_replacement || 'Choose replacement';
		if (code.startsWith('condition.')) return strings.review_condition || 'Review condition';
		return strings.review_issue || 'Review issue';
	}

	function targetDescriptor(issue) {
		const parts = pathParts(issue?.path);
		const root = parts[0] || '';
		const index = /^\d+$/.test(parts[1] || '') ? Number(parts[1]) : null;
		const field = fieldFromIssue(issue);
		const code = String(issue?.code || '');

		if (code === 'workflow.trigger_missing') return { type: 'trigger', root: 'trigger', index: null, field: '' };
		if (code === 'workflow.action_missing') return { type: 'add_action', root: 'actions', index: null, field: '' };
		if (code === 'binding.trigger_has_bindings') return { type: 'capability', root: 'trigger', index: null, field: '' };
		if (code === 'binding.input_unknown') return { type: 'capability', root, index, field: '' };
		if (root === 'states' && index !== null && field) return { type: 'binding', root, index, field };
		if (root === 'actions' && index !== null && field) return { type: 'binding', root, index, field };
		if (root === 'conditions' && index !== null) return { type: 'condition', root, index, field: parts[2] || '' };
		if (['trigger', 'states', 'actions'].includes(root)) return { type: 'capability', root, index, field: '' };
		return { type: 'stage', root, index, field };
	}

	if (typeof document === 'undefined') {
		globalThis.cbAutomationsValidationRecoveryHelpers = {
			pathParts,
			humanizeField,
			fieldFromIssue,
			locationLabel,
			recoveryLabel,
			targetDescriptor,
		};
		return;
	}

	const root = document.querySelector('[data-cb-automations-editor]');
	const dataNode = document.getElementById('cb-automations-editor-data');
	const panel = document.querySelector('.cb-automations-validation-panel');
	const list = panel?.querySelector('.cb-automations-validation-list');
	if (!root || !dataNode || !panel || !list) return;

	let data;
	try {
		data = JSON.parse(dataNode.textContent || '{}');
	} catch {
		return;
	}

	const issues = Array.isArray(data.validation) ? data.validation : [];
	if (issues.length === 0) return;

	const catalog = Array.isArray(data.capabilities) ? data.capabilities : [];
	const byRef = new Map(catalog.map((capability) => {
		const ref = capability?.reference || {};
		return [[ref.kind || '', ref.provider || '', ref.id || '', ref.schema_version || ''].join(':'), capability];
	}));
	const strings = {
		workflow: 'Workflow',
		when_trigger: 'When · Trigger',
		get_data: 'Get data · Data lookup %d',
		only_if: 'Only if · Condition %d',
		then_actions: 'Then · Actions',
		then_action: 'Then · Action %d',
		choose_trigger: 'Choose trigger',
		add_action: 'Add action',
		review_trigger: 'Review trigger',
		review_capability: 'Review capability',
		connect_input: 'Connect input',
		fix_input: 'Fix input',
		choose_replacement: 'Choose replacement',
		review_condition: 'Review condition',
		review_issue: 'Review issue',
		technical_details: 'Technical details',
		saved_issue: 'Saved workflow issue',
		save_recheck: 'Changes made. Save the automation to re-check workflow health.',
		...(window.cbAutomationsValidationRecoveryStrings || {}),
	};

	const summaryItems = Array.from(list.children).filter((item) => item instanceof HTMLElement);
	let stale = false;

	function el(tag, attrs = {}, text = null) {
		const node = document.createElement(tag);
		for (const [key, value] of Object.entries(attrs)) {
			if (key === 'class') node.className = value;
			else node.setAttribute(key, String(value));
		}
		if (text !== null) node.textContent = text;
		return node;
	}

	function summaryMessage(index) {
		return summaryItems[index]?.dataset.validationMessage || '';
	}

	function enhanceSummary() {
		summaryItems.forEach((item, index) => {
			const issue = issues[index];
			if (!issue || item.dataset.cbValidationEnhanced === '1') return;
			const messageNode = item.querySelector('strong');
			const pathNode = item.querySelector('code');
			const message = messageNode?.textContent?.trim() || strings.saved_issue;
			const path = String(issue.path || pathNode?.textContent || '');

			item.dataset.cbValidationEnhanced = '1';
			item.dataset.validationPath = path;
			item.dataset.validationCode = String(issue.code || '');
			item.dataset.validationMessage = message;

			const copy = el('div', { class: 'cb-automations-validation-item__copy' });
			copy.append(
				el('span', { class: 'cb-automations-validation-item__location' }, locationLabel(issue, strings)),
				el('strong', { class: 'cb-automations-validation-item__message' }, message)
			);

			const controls = el('div', { class: 'cb-automations-validation-item__controls' });
			const jump = el('button', {
				type: 'button',
				class: 'button cb-core-button cb-core-button--secondary cb-automations-validation-jump',
				'data-cb-validation-jump': String(index),
			}, recoveryLabel(issue, strings));
			const technical = el('details', { class: 'cb-automations-validation-technical' });
			technical.append(
				el('summary', {}, strings.technical_details),
				el('code', {}, path)
			);
			controls.append(jump, technical);
			item.replaceChildren(copy, controls);
		});
	}

	function stepCard(rootName, index) {
		const selectors = {
			states: '[data-cb-automations-states]',
			actions: '[data-cb-automations-actions]',
		};
		const selector = selectors[rootName];
		if (!selector || index === null) return null;
		const container = root.querySelector(selector);
		if (!container) return null;
		return Array.from(container.children).filter((child) => child instanceof HTMLElement && child.classList.contains('cb-automations-step'))[index] || null;
	}

	function bindingRow(card, field) {
		if (!card || !field) return null;
		const select = card.querySelector('.cb-automations-capability-select');
		const capability = select ? byRef.get(select.value) : null;
		const fields = Object.keys(capability?.input_schema || {});
		const index = fields.indexOf(field);
		if (index < 0) return null;
		return card.querySelectorAll('.cb-automations-input-row')[index] || null;
	}

	function conditionTarget(index, field) {
		const container = root.querySelector('[data-cb-automations-conditions]');
		const cards = Array.from(container?.children || []).filter((child) => child instanceof HTMLElement && child.classList.contains('cb-automations-condition'));
		const card = cards[index] || null;
		if (!card) return null;
		const grid = card.querySelector('.cb-automations-condition-grid');
		if (!grid) return { container: card, focus: null, host: card };
		if (field === 'left') {
			const target = grid.children[0] || card;
			return { container: target, focus: target.querySelector('select, input, textarea, button'), host: target };
		}
		if (field === 'operator') {
			const target = grid.children[1] || card;
			return { container: target, focus: target.querySelector('select'), host: target };
		}
		if (field === 'right') {
			const target = grid.children[2] || card;
			return { container: target, focus: target.querySelector('select, input, textarea, button'), host: target };
		}
		return { container: card, focus: card.querySelector('select, input, textarea, button'), host: card };
	}

	function resolveTarget(issue) {
		const descriptor = targetDescriptor(issue);
		if (descriptor.type === 'trigger') {
			const stage = root.querySelector('[data-stage="trigger"]');
			const card = root.querySelector('[data-cb-automations-trigger] > .cb-automations-step');
			return {
				container: card || stage,
				focus: card?.querySelector('.cb-automations-capability-picker__trigger, .cb-automations-capability-select') || null,
				host: card || stage,
			};
		}
		if (descriptor.type === 'add_action') {
			const stage = root.querySelector('[data-stage="actions"]');
			return { container: stage, focus: root.querySelector('[data-cb-add-action]'), host: stage };
		}
		if (descriptor.type === 'binding') {
			const card = stepCard(descriptor.root, descriptor.index);
			const row = bindingRow(card, descriptor.field);
			return {
				container: row || card,
				focus: row?.querySelector('.cb-automations-binding-source, input, textarea, select, button') || null,
				host: row || card,
			};
		}
		if (descriptor.type === 'condition') {
			return conditionTarget(descriptor.index, descriptor.field);
		}
		if (descriptor.type === 'capability') {
			const card = descriptor.root === 'trigger'
				? root.querySelector('[data-cb-automations-trigger] > .cb-automations-step')
				: stepCard(descriptor.root, descriptor.index);
			return {
				container: card,
				focus: card?.querySelector('.cb-automations-capability-picker__trigger, .cb-automations-capability-select') || null,
				host: card,
			};
		}
		const allowedStages = new Set(['trigger', 'states', 'conditions', 'actions']);
		const stage = allowedStages.has(descriptor.root) ? root.querySelector(`[data-stage="${descriptor.root}"]`) : root;
		return { container: stage || root, focus: null, host: stage || root };
	}

	function clearInlineIssues() {
		root.querySelectorAll('.cb-automations-inline-validation').forEach((notice) => notice.remove());
		root.querySelectorAll('.has-validation-issue').forEach((node) => node.classList.remove('has-validation-issue'));
	}

	function applyInlineIssues() {
		clearInlineIssues();
		if (stale) return;
		issues.forEach((issue, index) => {
			const resolved = resolveTarget(issue);
			if (!resolved?.container) return;
			resolved.host?.classList.add('has-validation-issue');
			const notice = el('div', {
				class: 'cb-automations-inline-validation',
				role: 'note',
				'data-validation-path': String(issue.path || ''),
			});
			notice.append(
				el('span', { class: 'cb-automations-inline-validation__label' }, strings.saved_issue),
				el('strong', {}, summaryMessage(index))
			);
			resolved.container.append(notice);
		});
	}

	function focusIssue(index) {
		if (stale) return;
		const issue = issues[index];
		if (!issue) return;
		const resolved = resolveTarget(issue);
		if (!resolved?.container) return;
		const host = resolved.host || resolved.container;
		host.classList.add('is-validation-focus');
		resolved.container.scrollIntoView({ behavior: 'smooth', block: 'center' });
		window.setTimeout(() => {
			if (resolved.focus instanceof HTMLElement && !resolved.focus.hidden && !resolved.focus.hasAttribute('disabled')) {
				resolved.focus.focus({ preventScroll: true });
			}
		}, 180);
		window.setTimeout(() => host.classList.remove('is-validation-focus'), 1800);
	}

	function markStale() {
		if (stale) return;
		stale = true;
		panel.classList.add('is-stale');
		clearInlineIssues();
		panel.querySelectorAll('[data-cb-validation-jump]').forEach((button) => {
			button.disabled = true;
		});
		const staleStatus = el('p', {
			class: 'cb-automations-validation-stale',
			role: 'status',
		}, strings.save_recheck);
		panel.querySelector('.cb-automations-panel-heading')?.insertAdjacentElement('afterend', staleStatus);
	}

	function isPresentationOnlyEvent(event) {
		return event.target instanceof Element && Boolean(event.target.closest('.cb-automations-capability-picker__search'));
	}

	list.addEventListener('click', (event) => {
		const button = event.target.closest('[data-cb-validation-jump]');
		if (!button || button.disabled) return;
		focusIssue(Number(button.getAttribute('data-cb-validation-jump')));
	});

	root.addEventListener('change', (event) => {
		if (!isPresentationOnlyEvent(event)) queueMicrotask(markStale);
	});
	root.addEventListener('input', (event) => {
		if (!isPresentationOnlyEvent(event)) queueMicrotask(markStale);
	});
	root.addEventListener('click', (event) => {
		if (event.target.closest('[data-cb-add-state], [data-cb-add-condition], [data-cb-add-action], .button-link-delete')) {
			queueMicrotask(markStale);
		}
	});

	enhanceSummary();
	applyInlineIssues();
})();
