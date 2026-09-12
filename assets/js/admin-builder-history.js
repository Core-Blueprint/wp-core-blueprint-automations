import { CommandHistory, EditorState, ProjectState, animateLayoutChange } from '@cb-core/design-editor';

const api = window.cbAutomationsEditorSession;
const shell = document.querySelector('[data-cb-automations-designer-shell]');
const undo = shell?.querySelector('[data-cb-design-shell-undo]');
const redo = shell?.querySelector('[data-cb-design-shell-redo]');

if (api?.snapshot && api?.replace && api?.root && shell && undo instanceof HTMLButtonElement && redo instanceof HTMLButtonElement) {
	const clone = (value) => JSON.parse(JSON.stringify(value));
	const projectFor = (definition) => ({
		schema_version: 1,
		design_type: 'automation-workflow',
		root: {
			id: 'workflow',
			type: 'automation-workflow',
			props: { definition: clone(definition) },
			children: [],
		},
	});
	const definitionFrom = (project) => clone(project?.root?.props?.definition || {});
	const projectState = new ProjectState(projectFor(api.snapshot()));
	const editorState = new EditorState();
	const history = new CommandHistory(projectState, editorState, { limit: 100 });
	let applying = false;

	const syncButtons = () => {
		undo.disabled = !history.canUndo;
		redo.disabled = !history.canRedo;
	};
	const commandFor = (definition) => ({
		label: 'Edit automation workflow',
		apply(project) {
			const next = clone(project);
			next.root.props.definition = clone(definition);
			return { project: next };
		},
	});
	const sameDefinition = (left, right) => JSON.stringify(left) === JSON.stringify(right);
	const applyHistory = (direction) => {
		applying = true;
		try {
			const changed = direction === 'undo' ? history.undo() : history.redo();
			if (!changed) return;
			const applyDefinition = () => api.replace(definitionFrom(projectState.current()), { source: 'history' });
			animateLayoutChange(api.root, applyDefinition);
		} finally {
			applying = false;
			syncButtons();
		}
	};

	api.root.addEventListener('cb-automations:definitionchange', (event) => {
		if (applying || String(event.detail?.source || '') !== 'editor') return;
		const next = event.detail?.definition;
		if (!next || typeof next !== 'object' || Array.isArray(next)) return;
		const current = definitionFrom(projectState.current());
		if (sameDefinition(current, next)) return;
		history.execute(commandFor(next));
		syncButtons();
	});

	undo.addEventListener('click', () => applyHistory('undo'));
	redo.addEventListener('click', () => applyHistory('redo'));

	document.addEventListener('keydown', (event) => {
		if (!(event.ctrlKey || event.metaKey) || event.altKey) return;
		const target = event.target;
		if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement || target instanceof HTMLSelectElement || target?.isContentEditable) return;
		if (String(event.key || '').toLowerCase() !== 'z') return;
		event.preventDefault();
		applyHistory(event.shiftKey ? 'redo' : 'undo');
	});

	shell.addEventListener('cb:design-shell:savechange', (event) => {
		if (String(event.detail?.state || '') !== 'saved') return;
		const suppliedBaseline = event.detail?.baselineDefinition;
		const baseline = suppliedBaseline && typeof suppliedBaseline === 'object' && !Array.isArray(suppliedBaseline)
			? clone(suppliedBaseline)
			: api.snapshot();
		const current = api.snapshot();
		projectState.replace(projectFor(baseline), { source: 'baseline' });
		history.clear();
		if (event.detail?.currentDirty === true && !sameDefinition(baseline, current)) {
			history.execute(commandFor(current));
		}
		syncButtons();
	});

	window.cbAutomationsBuilderHistory = Object.freeze({
		projectState,
		editorState,
		history,
		undo: () => applyHistory('undo'),
		redo: () => applyHistory('redo'),
	});

	syncButtons();
}
