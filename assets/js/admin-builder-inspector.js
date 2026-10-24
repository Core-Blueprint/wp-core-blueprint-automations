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

  // Resolve against the current Base-provided immutable project, never DOM
  // index, capability label or another consumer-owned Selection authority.
  const refKey = (ref) => [ref?.kind || '', ref?.provider || '', ref?.id || '', ref?.schema_version || ''].join(':');
  const capabilityFor = (ref) => catalog.find((item) => refKey(item?.reference) === refKey(ref)) || null;
  const interactive = (target) => target?.closest?.('button, a, input, select, textarea, details, summary, label, [role="button"]');
  const recordForRef = (ref, project) => {
    if (ref?.kind !== 'entity' || !project?.root?.properties?.definition) return null;
    const definition = project.root.properties.definition;
    const types = {
      'automations.trigger': ['trigger', 'step_id', 'WHEN', 'Trigger'],
      'automations.state': ['states', 'step_id', 'GET DATA', 'Data lookup'],
      'automations.action': ['actions', 'step_id', 'THEN', 'Action'],
      'automations.condition': ['conditions', 'condition_id', 'ONLY IF', 'Condition'],
    };
    if (!Object.hasOwn(types, ref.type)) return null;
    const [collection, field, stage, kind] = types[ref.type];
    const values = collection === 'trigger'
      ? (definition.trigger ? [definition.trigger] : [])
      : definition[collection];
    const matches = values.filter((item) => item?.[field] === ref.id);
    if (matches.length !== 1) return null;
    return { key: ref.type + ':' + ref.id, stage, kind, value: matches[0] };
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


  const bindings = new WeakMap();
  let selection = null;
  let observer = null;
  const cards = () => Array.from(root.querySelectorAll(
    '[data-cb-automations-selection-type][data-cb-automations-selection-id]'
  ));
  const cardRef = (card) => Object.freeze({
    kind: 'entity',
    type: String(card.dataset.cbAutomationsSelectionType),
    id: String(card.dataset.cbAutomationsSelectionId),
  });
  const decorate = () => {
    if (!selection) return;
    const origin = selection.captureOrigin();
    for (const card of cards()) {
      if (!(card instanceof HTMLElement)) continue;
      const existing = bindings.get(card);
      if (existing && existing.origin.expectedEpoch === origin.expectedEpoch
        && existing.origin.workflowId === origin.workflowId) continue;
      existing?.controller.abort();
      const controller = new AbortController();
      const ref = cardRef(card);
      const select = () => {
        if (selection.select(ref, origin)) tab.click();
      };
      card.setAttribute('role', 'group');
      card.setAttribute('aria-label', String(card.querySelector('.cb-automations-step-head, .cb-automations-condition-head')?.textContent || strings.selectedStep).trim());
      card.tabIndex = card.tabIndex >= 0 ? card.tabIndex : 0;
      card.addEventListener('click', (event) => {
        if (!interactive(event.target)) select();
      }, { signal: controller.signal });
      card.addEventListener('keydown', (event) => {
        if (event.target !== card || !['Enter', ' '].includes(event.key)) return;
        event.preventDefault();
        select();
      }, { signal: controller.signal });
      bindings.set(card, { origin, controller });
    }
  };
  const syncCanvas = ({ primary }) => {
    decorate();
    for (const card of cards()) {
      const ref = cardRef(card);
      const active = primary?.kind === 'entity'
        && ref.type === primary.type && ref.id === primary.id;
      card.classList.toggle('is-builder-selected', active);
      if (active) card.setAttribute('aria-current', 'true');
      else card.removeAttribute('aria-current');
    }
    return true;
  };
  const bindSelection = () => {
    if (selection) return;
    selection = window.cbAutomationsBuilderSelection || null;
    if (!selection?.registerView) return;
    const inspectorOk = selection.registerView('inspector', (view) => {
      renderInspector(recordForRef(view.primary, view.project));
      return true;
    });
    const canvasOk = selection.registerView('canvas', syncCanvas);
    if (!inspectorOk || !canvasOk) return;
    observer = new MutationObserver(() => decorate());
    observer.observe(root, { childList: true, subtree: true });
    selection.registerCleanup(() => {
      observer?.disconnect();
      for (const card of cards()) bindings.get(card)?.controller.abort();
    });
  };

  renderInspector(null);
  shell.addEventListener('cb-automations:contextloaded', (event) => {
    // Selection has already flushed from the canonical B2 controller.
    event.detail?.acknowledgeView?.('inspector',
      Boolean(selection?.verified('inspector') && selection?.verified('canvas')));
  });
  if (window.cbAutomationsBuilderSelection) bindSelection();
  else window.addEventListener('cb-automations:selection-ready', bindSelection, { once: true });

	return true;
	};
	if (!initialize()) window.addEventListener('cb:design-editor:ready', initialize, { once: true });
})();
