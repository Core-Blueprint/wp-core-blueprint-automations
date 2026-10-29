/**
 * ADV-03A Base Designer Foundation adapter.
 *
 * Base exclusively owns manual launch, focus-mode, panel chrome, exit modal
 * and unsaved-change authorization. The Advanced graph runtime owns only the
 * graph canvas and native guarded POST. No parallel fullscreen implementation.
 */
import { createDesignerShell } from '@cb-core/design-editor';
import { createDesignerCanvasNavigation, createDesignerContextMenu, createDomainSession } from '@cb-core/design-editor';

const root = document.querySelector('[data-cb-advanced-root][data-cb-design-shell]');

if (root instanceof HTMLElement) {
	const guard = window.cbCore?.designerExitGuard;
	if (typeof guard?.register !== 'function') {
		root.dispatchEvent(new CustomEvent('cb:design-shell:failure', {
			bubbles: true,
			detail: Object.freeze({
				phase: 'exit-guard',
				message: 'The shared Designer Exit Guard is unavailable. Reload the editor.',
			}),
		}));
	} else {
		guard.register(root, {
			isDirty: () => root.dataset.cbAdvancedDirty === 'true',
			save: async () => {
				// A native POST cannot return the authoritative save receipt to
				// this page. Trigger the existing guarded WordPress form but DO
				// NOT claim Save/close success before server readback.
				const form = root.closest('[data-cb-advanced-form]');
				const button = root.querySelector('[data-cb-advanced-save]');
				if (form instanceof HTMLFormElement && button instanceof HTMLButtonElement) {
					form.requestSubmit(button);
				}
				return false;
			},
		});
		createDesignerShell(root);
		let mounted = false;
		let canvasNavigation = null;
		let contextMenu = null;
		let historySession = null;
		const mountGraph = () => {
			const graph = root.cbAutomationsAdvancedGraph;
			if (mounted || !graph || typeof graph.connectControllers !== 'function') return;
			const viewport = root.querySelector('[data-cb-design-canvas-viewport]');
			const scene = root.querySelector('[data-cb-design-canvas-scene]');
			if (!viewport || !scene) return;
			try {
				historySession = createDomainSession({
					project: graph.getHistoryProject(),
					validateProject(project) {
						if (project.schema_version !== 0 || project.design_type !== 'automations-advanced-graph'
							|| !project.root || project.root.definition?.definition_version !== 2
							|| project.root.definition?.mode !== 'advanced'
							|| !Array.isArray(project.root.definition?.graph?.nodes)
							|| !Array.isArray(project.root.definition?.graph?.edges)
							|| typeof project.root.name !== 'string')
							throw new TypeError('Invalid Advanced history project.');
					},
					onChange(project, event) {
						if (event?.source === 'history') graph.restoreHistory(project.root);
					},
					historyLimit: 100,
				});
				canvasNavigation = createDesignerCanvasNavigation({
					root, viewport, scene,
					getContentBounds: graph.getContentBounds,
					getContextKey: graph.contextKey,
					isReady: () => root.isConnected && !root.hidden,
				});
				contextMenu = createDesignerContextMenu({
					root, viewport,
					getContextKey: graph.contextKey,
					getItems: graph.getMenuItems,
					canInvoke: graph.canInvokeMenu,
					onInvoke: graph.invokeMenu,
					ariaLabel: 'Workflow canvas actions',
				});
				graph.connectControllers({navigation: canvasNavigation, menu: contextMenu, history: historySession});
				const undoButton = root.querySelector('[data-cb-advanced-undo]');
				const redoButton = root.querySelector('[data-cb-advanced-redo]');
				const updateHistory = () => {
					const status = historySession.historyStatus();
					if (undoButton) undoButton.disabled = !status.canUndo;
					if (redoButton) redoButton.disabled = !status.canRedo;
				};
				historySession.subscribeHistory(updateHistory);
				updateHistory();
				root.querySelector('[data-cb-advanced-fit]')?.addEventListener('click', () => {
					canvasNavigation.fitToScreen();
				});
				// The Designer starts hidden. Defer initial fit until Base has
				// actually entered its measurable fullscreen workspace.
				root.addEventListener('cb:design-shell:fullscreenchange', () => {
					if (root.hidden) return;
					requestAnimationFrame(() => {
						if (!root.hidden && root.isConnected) canvasNavigation?.fitToScreen();
					});
				});
				window.addEventListener('pagehide', () => {
					contextMenu?.destroy();
					canvasNavigation?.destroy();
					historySession?.dispose();
				}, {once: true});
				mounted = true;
			} catch (_) {
				contextMenu?.destroy();
				canvasNavigation?.destroy();
				historySession?.dispose();
				root.dispatchEvent(new CustomEvent('cb:design-shell:failure', {
					bubbles: true, detail: Object.freeze({
						phase: 'canvas-interaction',
						message: 'Canvas interactions could not start. Reload the editor.',
					}),
				}));
			}
		};
		root.addEventListener('cb:automations-advanced:ready', mountGraph);
		mountGraph();
	}
}
