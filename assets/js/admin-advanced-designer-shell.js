/**
 * ADV-03A Base Designer Foundation adapter.
 *
 * Base exclusively owns manual launch, focus-mode, panel chrome, exit modal
 * and unsaved-change authorization. The Advanced graph runtime owns only the
 * graph canvas and native guarded POST. No parallel fullscreen implementation.
 */
import { createDesignerShell } from '@cb-core/design-editor';
import { createDesignerCanvasNavigation, createDesignerContextMenu, createDomainSession } from '@cb-core/design-editor';
import { createAutomationsGraphMinimap } from './admin-advanced-minimap.js';

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
		let minimap = null;
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
				const resetZoomButton = root.querySelector('[data-cb-advanced-zoom-reset]');
				const zoomOutButton = root.querySelector('[data-cb-advanced-zoom-out]');
				const zoomInButton = root.querySelector('[data-cb-advanced-zoom-in]');
				const paintGrid = view => {
					// Base owns pan, scale and scroll coordinates. The grid lives
					// on the viewport and therefore has no physical end.
					const scale = Math.max(0.0001, view.scale);
					viewport.style.setProperty('--cb-advanced-grid-step', (22 * scale) + 'px');
					viewport.style.setProperty('--cb-advanced-grid-x', (view.x - view.scrollLeft) + 'px');
					viewport.style.setProperty('--cb-advanced-grid-y', (view.y - view.scrollTop) + 'px');
					if (resetZoomButton) resetZoomButton.textContent = Math.round(view.scale * 100) + '%';
					if (zoomOutButton) zoomOutButton.disabled = view.scale <= .1 + 1e-9;
					if (zoomInButton) zoomInButton.disabled = view.scale >= 4 - 1e-9;
					minimap?.render();
				};
				canvasNavigation = createDesignerCanvasNavigation({
					root, viewport, scene,
					getContentBounds: graph.getContentBounds,
					getContextKey: graph.contextKey,
					isReady: () => root.isConnected && !root.hidden,
					onViewChange: paintGrid,
				});
				if (typeof canvasNavigation.zoomAt !== 'function' || typeof canvasNavigation.zoomBy !== 'function'
					|| typeof graph.getMinimapGraph !== 'function') {
					throw new TypeError('Advanced Canvas requires the paired Base CN-2 module.');
				}
				minimap = createAutomationsGraphMinimap({
					root, viewport, navigation: canvasNavigation,
					getGraphSnapshot: graph.getMinimapGraph,
				});
				const zoomBy = factor => canvasNavigation?.zoomBy({factor});
				zoomOutButton?.addEventListener('click', () => zoomBy(1 / 1.2));
				zoomInButton?.addEventListener('click', () => zoomBy(1.2));
				resetZoomButton?.addEventListener('click', () => canvasNavigation?.zoomAt({scale:1}));
				// Keep native wheel/trackpad scrolling in sync with Base's
				// public viewport snapshot, rather than introducing a pan engine.
				viewport.addEventListener('scroll', () => {
					if (canvasNavigation) paintGrid(canvasNavigation.getView());
				}, {passive:true});
				// Removing a finite scene removes the browser's former overflow
				// scrollbar. Route wheel/trackpad gestures to the SAME Base pan
				// controller, never to a second matrix or transform implementation.
				const wheelDelta = event => {
					const unit = event.deltaMode === 1 ? 16
						: event.deltaMode === 2 ? Math.max(1, viewport.clientHeight) : 1;
					return {dx: event.deltaX * unit, dy: event.deltaY * unit};
				};
				viewport.addEventListener('wheel', event => {
					if (root.hidden || event.altKey) return;
					const {dx,dy} = wheelDelta(event);
					// Only the canvas catches Ctrl/Cmd+wheel or trackpad pinch.
					// Outside this viewport the browser keeps its native zoom.
					if (event.ctrlKey || event.metaKey) {
						event.preventDefault();
						if (dy) canvasNavigation?.zoomBy({
							factor: Math.exp(-dy * 0.002),
							clientX: event.clientX, clientY: event.clientY,
						});
						return;
					}
					if ((dx || dy) && canvasNavigation?.panBy(-dx, -dy)) event.preventDefault();
				}, {passive:false});
				viewport.addEventListener('keydown', event => {
					// Node-title arrow keys move nodes. Only the viewport itself pans.
					if (event.target !== viewport || event.ctrlKey || event.metaKey || event.altKey) return;
					const step = event.shiftKey ? 128 : 48;
					const delta = {
						ArrowLeft:[step,0], ArrowRight:[-step,0],
						ArrowUp:[0,step], ArrowDown:[0,-step],
					}[event.key];
					if (delta && canvasNavigation?.panBy(...delta)) event.preventDefault();
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
						if (!root.hidden && root.isConnected) { canvasNavigation?.fitToScreen(); minimap?.render(); }
					});
				});
				window.addEventListener('pagehide', () => {
					contextMenu?.destroy();
					minimap?.destroy();
					canvasNavigation?.destroy();
					historySession?.dispose();
				}, {once: true});
				mounted = true;
			} catch (_) {
				contextMenu?.destroy();
				minimap?.destroy();
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
