(() => {
	'use strict';

	function normalizeSearch(value) {
		return String(value || '')
			.normalize('NFKD')
			.replace(/[\u0300-\u036f]/g, '')
			.toLowerCase()
			.trim();
	}

	function capabilityKey(capability) {
		const ref = capability?.reference || {};
		return [ref.kind || '', ref.provider || '', ref.id || '', ref.schema_version || ''].join(':');
	}

	function matchesCapability(capability, query) {
		const needle = normalizeSearch(query);
		if (!needle) return true;
		const ref = capability?.reference || {};
		const provider = capability?.provider || {};
		const haystack = normalizeSearch([
			capability?.label,
			capability?.description,
			provider.name,
			provider.id,
			ref.id,
		].filter(Boolean).join(' '));
		return haystack.includes(needle);
	}

	function groupCapabilities(capabilities) {
		const providers = new Map();
		for (const capability of capabilities || []) {
			const provider = capability?.provider || {};
			const ref = capability?.reference || {};
			const providerId = String(provider.id || ref.provider || 'unknown');
			if (!providers.has(providerId)) {
				providers.set(providerId, {
					id: providerId,
					name: String(provider.name || providerId),
					available: provider.available !== false,
					items: [],
				});
			}
			const group = providers.get(providerId);
			group.available = group.available && provider.available !== false;
			group.items.push(capability);
		}

		return Array.from(providers.values())
			.map((group) => ({
				...group,
				items: group.items.slice().sort((a, b) => String(a?.label || '').localeCompare(String(b?.label || ''))),
			}))
			.sort((a, b) => a.name.localeCompare(b.name));
	}

	if (typeof document === 'undefined') {
		globalThis.cbAutomationsCapabilityPickerHelpers = {
			normalizeSearch,
			capabilityKey,
			matchesCapability,
			groupCapabilities,
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
	const byRef = new Map(catalog.map((capability) => [capabilityKey(capability), capability]));
	const strings = {
		choose_capability: 'Choose capability',
		picker_label: 'Choose automation capability',
		search_label: 'Search capabilities',
		search_placeholder: 'Search by capability, provider or description…',
		search_help: 'Search by capability, provider or description.',
		no_results: 'No capabilities match your search.',
		unavailable: 'Unavailable',
		unavailable_description: 'This stored capability is no longer available. Choose a replacement.',
		...(window.cbAutomationsCapabilityPickerStrings || {}),
	};

	let pickerSequence = 0;
	let scheduled = false;

	function el(tag, attrs = {}, text = null) {
		const node = document.createElement(tag);
		for (const [key, value] of Object.entries(attrs)) {
			if (key === 'class') node.className = value;
			else if (key === 'hidden') node.hidden = Boolean(value);
			else node.setAttribute(key, String(value));
		}
		if (text !== null) node.textContent = text;
		return node;
	}

	function parseReference(key) {
		const parts = String(key || '').split(':');
		if (parts.length !== 4) return null;
		return {
			kind: parts[0],
			provider: parts[1],
			id: parts[2],
			schema_version: parts[3],
		};
	}

	function kindForSelect(select) {
		const stage = select.closest('[data-stage]')?.getAttribute('data-stage') || '';
		if (stage === 'trigger') return 'trigger';
		if (stage === 'states') return 'state';
		if (stage === 'actions') return 'action';
		const firstValue = Array.from(select.options).find((option) => option.value)?.value || '';
		return parseReference(firstValue)?.kind || '';
	}

	function currentCapability(select) {
		if (!select.value) return null;
		const capability = byRef.get(select.value);
		if (capability) return capability;
		const reference = parseReference(select.value);
		if (!reference) return null;
		return {
			reference,
			label: reference.id,
			description: strings.unavailable_description,
			provider: {
				id: reference.provider,
				name: reference.provider,
				available: false,
			},
			storedUnavailable: true,
		};
	}

	function isCapabilityAvailable(capability) {
		return capability?.provider?.available !== false && !capability?.storedUnavailable;
	}

	function visibleEnabledOptions(wrapper) {
		return Array.from(wrapper.querySelectorAll('.cb-automations-capability-picker__option'))
			.filter((option) => !option.hidden && !option.disabled);
	}

	function setExpanded(wrapper, expanded, restoreFocus = false) {
		const trigger = wrapper.querySelector('.cb-automations-capability-picker__trigger');
		const popover = wrapper.querySelector('.cb-automations-capability-picker__popover');
		if (!trigger || !popover) return;
		wrapper.classList.toggle('is-open', expanded);
		trigger.setAttribute('aria-expanded', expanded ? 'true' : 'false');
		popover.hidden = !expanded;
		if (!expanded && restoreFocus) trigger.focus();
	}

	function closeOtherPickers(activeWrapper) {
		root.querySelectorAll('.cb-automations-capability-picker.is-open').forEach((wrapper) => {
			if (wrapper !== activeWrapper) setExpanded(wrapper, false);
		});
	}

	function focusRelativeOption(wrapper, current, direction) {
		const options = visibleEnabledOptions(wrapper);
		if (options.length === 0) return;
		let index = current ? options.indexOf(current) : -1;
		if (direction === 'first') index = 0;
		else if (direction === 'last') index = options.length - 1;
		else if (direction === 'next') index = Math.min(options.length - 1, index + 1);
		else if (direction === 'previous') index = Math.max(0, index < 0 ? options.length - 1 : index - 1);
		options[index]?.focus();
	}

	function renderSelection(trigger, capability) {
		const title = trigger.querySelector('.cb-automations-capability-picker__title');
		const meta = trigger.querySelector('.cb-automations-capability-picker__meta');
		const description = trigger.querySelector('.cb-automations-capability-picker__description');
		if (!title || !meta || !description) return;

		if (!capability) {
			trigger.classList.remove('is-unavailable');
			title.textContent = strings.choose_capability;
			meta.textContent = strings.search_help;
			description.textContent = '';
			description.hidden = true;
			return;
		}

		const available = isCapabilityAvailable(capability);
		trigger.classList.toggle('is-unavailable', !available);
		title.textContent = String(capability.label || capability.reference?.id || strings.choose_capability);
		meta.textContent = available
			? String(capability.provider?.name || capability.reference?.provider || '')
			: `${capability.provider?.name || capability.reference?.provider || ''} · ${strings.unavailable}`;
		description.textContent = String(capability.description || '');
		description.hidden = !description.textContent;
	}

	function filterOptions(wrapper, query) {
		let visibleCount = 0;
		wrapper.querySelectorAll('.cb-automations-capability-picker__group').forEach((group) => {
			let groupCount = 0;
			group.querySelectorAll('.cb-automations-capability-picker__option').forEach((option) => {
				const capability = byRef.get(option.dataset.capabilityKey || '');
				const visible = Boolean(capability && matchesCapability(capability, query));
				option.hidden = !visible;
				if (visible) {
					visibleCount += 1;
					groupCount += 1;
				}
			});
			group.hidden = groupCount === 0;
		});
		const empty = wrapper.querySelector('.cb-automations-capability-picker__empty');
		if (empty) empty.hidden = visibleCount !== 0;
	}

	function buildPicker(select) {
		if (select.dataset.cbCapabilityPickerEnhanced === '1') return;
		const kind = kindForSelect(select);
		if (!kind) return;

		select.dataset.cbCapabilityPickerEnhanced = '1';
		select.hidden = true;
		select.tabIndex = -1;
		select.setAttribute('aria-hidden', 'true');

		pickerSequence += 1;
		const triggerId = `cb-automations-capability-picker-trigger-${pickerSequence}`;
		const popoverId = `cb-automations-capability-picker-popover-${pickerSequence}`;
		const searchId = `cb-automations-capability-picker-search-${pickerSequence}`;
		const wrapper = el('div', { class: 'cb-automations-capability-picker', 'data-capability-kind': kind });
		const trigger = el('button', {
			type: 'button',
			id: triggerId,
			class: 'cb-automations-capability-picker__trigger',
			'aria-haspopup': 'dialog',
			'aria-expanded': 'false',
			'aria-controls': popoverId,
		});
		const triggerCopy = el('span', { class: 'cb-automations-capability-picker__trigger-copy' });
		triggerCopy.append(
			el('strong', { class: 'cb-automations-capability-picker__title' }),
			el('span', { class: 'cb-automations-capability-picker__meta' }),
			el('span', { class: 'cb-automations-capability-picker__description', hidden: true })
		);
		trigger.append(triggerCopy, el('span', { class: 'cb-automations-capability-picker__chevron', 'aria-hidden': 'true' }));
		renderSelection(trigger, currentCapability(select));

		const popover = el('div', {
			id: popoverId,
			class: 'cb-automations-capability-picker__popover',
			role: 'dialog',
			'aria-label': strings.picker_label,
			hidden: true,
		});
		const searchLabel = el('label', { class: 'screen-reader-text', for: searchId }, strings.search_label);
		const search = el('input', {
			id: searchId,
			type: 'search',
			class: 'cb-automations-capability-picker__search',
			placeholder: strings.search_placeholder,
			autocomplete: 'off',
		});
		const groupsNode = el('div', { class: 'cb-automations-capability-picker__groups' });
		const capabilities = catalog.filter((capability) => capability?.reference?.kind === kind);

		for (const group of groupCapabilities(capabilities)) {
			const section = el('section', { class: 'cb-automations-capability-picker__group' });
			const heading = el('div', { class: 'cb-automations-capability-picker__group-heading' });
			heading.append(el('strong', {}, group.name));
			if (!group.available) heading.append(el('span', { class: 'cb-automations-capability-picker__badge' }, strings.unavailable));
			section.append(heading);

			for (const capability of group.items) {
				const key = capabilityKey(capability);
				const available = isCapabilityAvailable(capability);
				const option = el('button', {
					type: 'button',
					class: `cb-automations-capability-picker__option${select.value === key ? ' is-selected' : ''}${available ? '' : ' is-unavailable'}`,
					'data-capability-key': key,
					'aria-pressed': select.value === key ? 'true' : 'false',
				});
				if (!available) option.disabled = true;
				const optionTitle = el('span', { class: 'cb-automations-capability-picker__option-title' });
				optionTitle.append(el('strong', {}, capability.label || capability.reference?.id || ''));
				if (!available) optionTitle.append(el('span', { class: 'cb-automations-capability-picker__badge' }, strings.unavailable));
				option.append(optionTitle);
				if (capability.description) {
					option.append(el('span', { class: 'cb-automations-capability-picker__option-description' }, capability.description));
				}
				option.append(el('span', { class: 'cb-automations-capability-picker__option-provider' }, group.name));
				section.append(option);
			}
			groupsNode.append(section);
		}

		const empty = el('p', { class: 'cb-automations-capability-picker__empty', hidden: capabilities.length > 0 }, strings.no_results);
		popover.append(searchLabel, search, groupsNode, empty);
		wrapper.append(trigger, popover);
		select.insertAdjacentElement('afterend', wrapper);

		trigger.addEventListener('click', () => {
			const opening = !wrapper.classList.contains('is-open');
			if (!opening) {
				setExpanded(wrapper, false);
				return;
			}
			closeOtherPickers(wrapper);
			setExpanded(wrapper, true);
			search.value = '';
			filterOptions(wrapper, '');
			search.focus();
		});

		trigger.addEventListener('keydown', (event) => {
			if (event.key !== 'ArrowDown') return;
			event.preventDefault();
			closeOtherPickers(wrapper);
			setExpanded(wrapper, true);
			search.value = '';
			filterOptions(wrapper, '');
			search.focus();
		});

		search.addEventListener('input', () => filterOptions(wrapper, search.value));
		search.addEventListener('keydown', (event) => {
			if (event.key === 'Escape') {
				event.preventDefault();
				setExpanded(wrapper, false, true);
				return;
			}
			if (event.key === 'ArrowDown') {
				event.preventDefault();
				focusRelativeOption(wrapper, null, 'first');
			} else if (event.key === 'ArrowUp') {
				event.preventDefault();
				focusRelativeOption(wrapper, null, 'last');
			}
		});

		groupsNode.addEventListener('click', (event) => {
			const option = event.target.closest('.cb-automations-capability-picker__option');
			if (!option || option.disabled) return;
			const key = option.dataset.capabilityKey || '';
			if (!key) return;
			select.value = key;
			select.dispatchEvent(new Event('change', { bubbles: true }));
		});

		groupsNode.addEventListener('keydown', (event) => {
			const option = event.target.closest('.cb-automations-capability-picker__option');
			if (!option) return;
			if (event.key === 'Escape') {
				event.preventDefault();
				setExpanded(wrapper, false, true);
			} else if (event.key === 'ArrowDown') {
				event.preventDefault();
				focusRelativeOption(wrapper, option, 'next');
			} else if (event.key === 'ArrowUp') {
				event.preventDefault();
				focusRelativeOption(wrapper, option, 'previous');
			} else if (event.key === 'Home') {
				event.preventDefault();
				focusRelativeOption(wrapper, option, 'first');
			} else if (event.key === 'End') {
				event.preventDefault();
				focusRelativeOption(wrapper, option, 'last');
			}
		});

		wrapper.addEventListener('focusout', () => {
			requestAnimationFrame(() => {
				if (!wrapper.contains(document.activeElement)) setExpanded(wrapper, false);
			});
		});
	}

	function enhance() {
		root.querySelectorAll('.cb-automations-capability-select:not([data-cb-capability-picker-enhanced="1"])').forEach(buildPicker);
	}

	function scheduleEnhance() {
		if (scheduled) return;
		scheduled = true;
		queueMicrotask(() => {
			scheduled = false;
			enhance();
		});
	}

	document.addEventListener('pointerdown', (event) => {
		root.querySelectorAll('.cb-automations-capability-picker.is-open').forEach((wrapper) => {
			if (!wrapper.contains(event.target)) setExpanded(wrapper, false);
		});
	});

	new MutationObserver(scheduleEnhance).observe(root, { childList: true, subtree: true });
	enhance();
})();
