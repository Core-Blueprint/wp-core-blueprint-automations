/**
 * ADV-03A Base Designer Foundation adapter.
 *
 * Base exclusively owns manual launch, focus-mode, panel chrome, exit modal
 * and unsaved-change authorization. The Advanced graph runtime owns only the
 * graph canvas and native guarded POST. No parallel fullscreen implementation.
 */
import { createDesignerShell } from '@cb-core/design-editor';
import { createDesignerCanvasNavigation, createDesignerContextMenu } from '@cb-core/design-editor';

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
		const mountGraph = () => {
			const graph = root.cbAutomationsAdvancedGraph;
			if (mounted || !graph || typeof graph.connectControllers !== 'function') return;
			const viewport = root.querySelector('[data-cb-design-canvas-viewport]');
			const scene = root.querySelector('[data-cb-design-canvas-scene]');
			if (!viewport || !scene) return;
			try {
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
				graph.connectControllers({navigation: canvasNavigation, menu: contextMenu});
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
				}, {once: true});
				mounted = true;
			} catch (_) {
				contextMenu?.destroy();
				canvasNavigation?.destroy();
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
