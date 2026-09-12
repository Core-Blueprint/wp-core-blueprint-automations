import { createDesignerShell } from '@cb-core/design-editor';

const root = document.querySelector('[data-cb-automations-designer-shell]');

if (root) {
	const workflowSidebar = root.querySelector('.cb-core-design-shell__palette');
	const workflowTitle = workflowSidebar?.querySelector('.cb-automations-panel-eyebrow');
	const workflowLabel = String(workflowTitle?.textContent || '').trim() || 'Workflow';
	const propertiesLabel = String(window.cbAutomationsInspectorStrings?.properties || '').trim() || 'Properties';
	const propertiesSidebar = root.querySelector('.cb-core-design-shell__sidebar');

	if (workflowSidebar) workflowSidebar.setAttribute('aria-label', workflowLabel);
	if (workflowTitle) workflowTitle.remove();
	if (propertiesSidebar) propertiesSidebar.setAttribute('aria-label', propertiesLabel);

	createDesignerShell(root, {
		defaultPanels: {
			sidebar: 'settings',
		},
	});
}
