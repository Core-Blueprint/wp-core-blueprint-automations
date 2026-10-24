/**
 * Automations Layers consumer for the PUBLIC Base Designer layer tree.
 *
 * Base owns tree rendering, keyboard navigation, disclosure, drag/reorder,
 * action iconography and accessibility. This module only projects existing
 * workflow structure and forwards actions to the canonical workflow editor.
 * It never holds a second workflow state or command history.
 */
import { createDesignerLayerTree } from '@cb-core/design-editor';

const shell = document.querySelector('[data-cb-automations-designer-shell]');
const host = shell?.querySelector('[data-cb-automations-layers-host]');
const hidden = shell?.closest('form')?.querySelector('[data-cb-automations-definition]');
const editor = window.cbAutomationsEditorSession;

if (shell && host && hidden && editor?.snapshot && editor?.layerAction) {
	const nav = window.cbAutomationsInspectorStrings?.navigation || {};
	const messages = window.cbAutomationsInspectorStrings?.layers || {};
	let selectedKey = '';
	const controller = new AbortController();
	const { signal } = controller;
	const tree = createDesignerLayerTree({
		documentRef: document,
		ariaLabel: String(messages.ariaLabel || 'Workflow layers'),
	});
	host.append(tree.element);

	const select = (key) => () => {
		shell.dispatchEvent(new CustomEvent('cb-automations:layerselect', {
			bubbles: true,
			detail: Object.freeze({ key }),
		}));
	};
	const allowed = (kind, id, operation, target = -1) => {
		if (shell.hasAttribute('aria-busy')) return false;
		return editor.layerAction(operation, kind, id, target) === true;
	};
	const nodes = (values, kind, label) => {
		if (!Array.isArray(values)) return [];
		const idField = kind === 'condition' ? 'condition_id' : 'step_id';
		return values.flatMap((item, index) => {
			const id = item && typeof item[idField] === 'string' ? item[idField] : '';
			// Missing, malformed or duplicate persisted identities are not
			// actionable. Never guess an index-based identity for a write.
			if (!id || values.findIndex((v) => v?.[idField] === id) !== index) return [];
			const key = kind + ':' + id;
			return [{
				key,
				label: String(label || kind) + ' ' + (index + 1),
				selected: selectedKey === key,
				onSelect: select(key),
				actions: {
					remove: () => allowed(kind, id, 'remove'),
				},
				...(values.length > 1 ? {
					reorder: {
						index,
						minIndex: 0,
						maxIndex: values.length - 1,
						onMove: (target) => allowed(kind, id, 'move', target),
					},
				} : {}),
			}];
		});
	};
	const render = () => {
		// Editor snapshot is the same definition that the Base Domain Session
		// receives through the existing definitionchange → workflowCommands path.
		const definition = editor.snapshot();
		const groups = [
			{
				key: 'stage:trigger',
				label: String(nav.trigger || 'WHEN'),
				children: [{
					key: 'trigger',
					label: String(nav.triggerDescription || 'Trigger'),
					selected: selectedKey === 'trigger',
					onSelect: select('trigger'),
				}],
			},
			{
				key: 'stage:states',
				label: String(nav.states || 'GET DATA'),
				children: nodes(definition.states, 'state', messages.state || nav.statesDescription || 'Data lookup'),
			},
			{
				key: 'stage:conditions',
				label: String(nav.conditions || 'ONLY IF'),
				children: nodes(definition.conditions, 'condition', messages.condition || nav.conditionsDescription || 'Condition'),
			},
			{
				key: 'stage:actions',
				label: String(nav.actions || 'THEN'),
				children: nodes(definition.actions, 'action', messages.action || nav.actionsDescription || 'Action'),
			},
		];
		tree.render(groups);
	};
	shell.addEventListener('cb-automations:inspector-selection', (event) => {
		selectedKey = String(event.detail?.key || '');
		render();
	}, { signal });
	shell.addEventListener('cb-automations:definitionchange', () => {
		render();
	}, { signal });
	shell.addEventListener('cb-automations:contextloaded', (event) => {
		let synchronized = false;
		try {
			selectedKey = '';
			tree.reset();
			render();
			synchronized = true;
		} catch (_error) {
			// The committed Base History cannot be rolled back for a view error.
		} finally {
			event.detail?.acknowledgeView?.('layers', synchronized);
		}
	}, { signal });
	window.addEventListener('pagehide', () => controller.abort(), { once: true });
	render();
} else if (host) {
	// Required public consumer state is absent: do not show inert controls or
	// make up a second source of workflow truth.
	host.setAttribute('role', 'alert');
	host.textContent = String(window.cbAutomationsInspectorStrings?.contextUnavailable || 'Workflow layers are unavailable. Reload the editor.');
}
