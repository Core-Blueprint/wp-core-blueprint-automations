import { createDesignerShell } from '@cb-core/design-editor';

const root = document.querySelector('[data-cb-automations-designer-shell]');

if (root) {
	const workflowSwitcher = root.querySelector('[data-cb-automations-workflow-switcher]');
	if (workflowSwitcher instanceof HTMLSelectElement) {
		workflowSwitcher.addEventListener('change', () => {
			const target = String(workflowSwitcher.value || '').trim();
			if (!target) return;

			try {
				const url = new URL(target, window.location.href);
				if (url.origin === window.location.origin) window.location.assign(url.href);
			} catch (error) {
				// Server-rendered context URLs are expected to be same-origin and valid.
			}
		});
	}

	const workflowSidebar = root.querySelector('.cb-core-design-shell__palette');
	const workflowTitle = workflowSidebar?.querySelector('.cb-automations-panel-eyebrow');
	const workflowLabel = String(workflowTitle?.textContent || '').trim() || 'Workflow';
	const propertiesLabel = String(window.cbAutomationsInspectorStrings?.properties || '').trim() || 'Properties';
	const propertiesSidebar = root.querySelector('.cb-core-design-shell__sidebar');

	const applyPanelTitle = (panel, title) => {
		if (!panel || !title) return;
		panel.dataset.cbDesignShellPanelTitle = title;
		const heading = panel.querySelector(':scope > .cb-core-design-shell__panel-header [data-cb-design-shell-panel-heading]');
		if (heading) heading.textContent = title;
	};

	applyPanelTitle(workflowSidebar, workflowLabel);
	applyPanelTitle(propertiesSidebar, propertiesLabel);
	if (workflowTitle) workflowTitle.remove();

	createDesignerShell(root, {
		defaultPanels: {
			sidebar: 'settings',
		},
	});
}
