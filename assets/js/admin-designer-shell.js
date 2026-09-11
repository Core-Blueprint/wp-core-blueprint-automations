import { createDesignerShell } from '@cb-core/design-editor';

const root = document.querySelector('[data-cb-automations-designer-shell]');

if (root) {
	createDesignerShell(root, {
		defaultPanels: {
			sidebar: 'settings',
		},
	});
}
