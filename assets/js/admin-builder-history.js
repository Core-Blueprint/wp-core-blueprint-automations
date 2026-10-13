import { createDesignerStateHistory, animateLayoutChange } from '@cb-core/design-editor';

const api = window.cbAutomationsEditorSession;
const shell = document.querySelector('[data-cb-automations-designer-shell]');
const undo = shell?.querySelector('[data-cb-design-shell-undo]');
const redo = shell?.querySelector('[data-cb-design-shell-redo]');

if (api?.snapshot && api?.replace && api?.root && shell && undo instanceof HTMLButtonElement && redo instanceof HTMLButtonElement) {
	const clone = (value) => JSON.parse(JSON.stringify(value));
	let applying = false;
	const history = createDesignerStateHistory({
		initialState: api.snapshot(),
		limit: 100,
		onApply: (definition) => {
			applying = true;
			try {
				animateLayoutChange(api.root, () => api.replace(definition, { source: 'history' }));
			} finally {
				applying = false;
			}
		},
	});
	const syncButtons = () => {
		const current = history.status();
		undo.disabled = !current.canUndo;
		redo.disabled = !current.canRedo;
	};
	const run = (direction) => {
		const changed = direction === 'undo' ? history.undo() : history.redo();
		syncButtons();
		return changed;
	};
	api.root.addEventListener('cb-automations:definitionchange', (event) => {
		if (applying || String(event.detail?.source || '') !== 'editor') return;
		const next = event.detail?.definition;
		if (!next || typeof next !== 'object' || Array.isArray(next)) return;
		history.record(next);
		syncButtons();
	});
	undo.addEventListener('click', () => run('undo'));
	redo.addEventListener('click', () => run('redo'));
	document.addEventListener('keydown', (event) => {
		if (!(event.ctrlKey || event.metaKey) || event.altKey) return;
		const target = event.target;
		if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement || target?.isContentEditable) return;
		if (String(event.key || '').toLowerCase() !== 'z') return;
		event.preventDefault();
		run(event.shiftKey ? 'redo' : 'undo');
	});
	shell.addEventListener('cb:design-shell:savechange', (event) => {
		if (String(event.detail?.state || '') !== 'saved') return;
		const base = event.detail?.baselineDefinition;
		const baseline = base && typeof base === 'object' && !Array.isArray(base) ? clone(base) : api.snapshot();
		const current = api.snapshot();
		history.reset(baseline, { current: event.detail?.currentDirty === true ? current : null });
		syncButtons();
	});
	window.cbAutomationsBuilderHistory = Object.freeze({
		status: () => history.status(),
		resetForContext: (definition) => { history.reset(definition); syncButtons(); },
		undo: () => run('undo'),
		redo: () => run('redo'),
	});
	syncButtons();
}
