(() => {
	'use strict';

	const config = {
		automationBuilder: 'Automation Builder',
		openBuilder: 'Open builder',
		inspector: 'Inspector',
		history: 'History',
		undo: 'Undo',
		redo: 'Redo',
		...(window.cbAutomationsBuilderStrings || {}),
	};

	const enhanceLibrary = () => {
		const createEyebrow = document.querySelector('.cb-automations-create-panel .cb-automations-panel-eyebrow');
		if (createEyebrow) createEyebrow.textContent = config.automationBuilder;

		document.querySelectorAll('.cb-automations-workflow-card').forEach((card) => {
			const open = card.querySelector('.cb-automations-workflow-card__open');
			if (open) open.textContent = `${config.openBuilder} →`;
		});
	};

	const ensureInspectorPanel = (shell) => {
		const sidebar = shell.querySelector('.cb-automations-design-shell__sidebar');
		const tabs = sidebar?.querySelector('.cb-core-design-shell__tabs');
		if (!sidebar || !tabs) return;

		const settingsTab = sidebar.querySelector('[data-cb-design-shell-tab="settings"]');
		const settingsPanel = sidebar.querySelector('[data-cb-design-shell-panel="settings"]');
		settingsTab?.setAttribute('data-cb-design-shell-sidebar-role', 'settings');
		settingsPanel?.setAttribute('data-cb-design-shell-sidebar-role', 'settings');

		if (!sidebar.querySelector('[data-cb-design-shell-tab="inspector"]')) {
			const tab = document.createElement('button');
			tab.type = 'button';
			tab.className = 'cb-core-design-shell__tab';
			tab.setAttribute('role', 'tab');
			tab.setAttribute('aria-selected', 'false');
			tab.setAttribute('data-cb-design-shell-tab', 'inspector');
			tab.setAttribute('data-cb-design-shell-group', 'sidebar');
			tab.setAttribute('data-cb-design-shell-sidebar-role', 'inspector');
			tab.textContent = config.inspector;
			tabs.insertBefore(tab, tabs.firstChild);

			const panel = document.createElement('section');
			panel.className = 'cb-core-design-shell__panel';
			panel.setAttribute('role', 'tabpanel');
			panel.setAttribute('data-cb-design-shell-panel', 'inspector');
			panel.setAttribute('data-cb-design-shell-group', 'sidebar');
			panel.setAttribute('data-cb-design-shell-sidebar-role', 'inspector');
			panel.hidden = true;
			sidebar.append(panel);
		}

		if (window.cbCoreDesignerLaunch && typeof window.cbCoreDesignerLaunch === 'object') {
			window.cbCoreDesignerLaunch.activeSidebarRole = 'settings';
		}
	};

	const ensureHistoryControls = (shell) => {
		const toolbar = shell.querySelector('.cb-core-design-shell__toolbar');
		if (!toolbar || toolbar.querySelector('[data-cb-design-shell-undo]')) return;
		const group = document.createElement('div');
		group.className = 'cb-core-design-shell__toolbar-group cb-automations-builder-history-controls';
		const label = document.createElement('span');
		label.setAttribute('data-cb-design-shell-group-label', '');
		label.textContent = config.history;
		const undo = document.createElement('button');
		undo.type = 'button';
		undo.className = 'button cb-core-button';
		undo.setAttribute('data-cb-design-shell-undo', '');
		undo.title = `${config.undo} (Ctrl/Cmd+Z)`;
		undo.textContent = config.undo;
		undo.disabled = true;
		const redo = document.createElement('button');
		redo.type = 'button';
		redo.className = 'button cb-core-button';
		redo.setAttribute('data-cb-design-shell-redo', '');
		redo.title = `${config.redo} (Ctrl/Cmd+Shift+Z)`;
		redo.textContent = config.redo;
		redo.disabled = true;
		group.append(label, undo, redo);
		const identity = toolbar.querySelector('.cb-automations-design-shell__identity');
		identity?.insertAdjacentElement('afterend', group);
		if (!identity) toolbar.prepend(group);
	};

	const decorateSidebar = (shell) => {
		const sidebar = shell.querySelector('.cb-automations-design-shell__sidebar');
		if (!sidebar) return;
		const tabs = sidebar.querySelector('.cb-core-design-shell__tabs');
		tabs?.classList.add('cb-core-design-shell__sidebar-tabs');
		sidebar.querySelectorAll('[data-cb-design-shell-tab]').forEach((tab) => {
			tab.classList.add('cb-core-design-shell__sidebar-tab');
		});
		sidebar.querySelectorAll('[data-cb-design-shell-panel]').forEach((panel) => {
			panel.classList.add('cb-core-design-shell__sidebar-panel');
		});
	};

	const prepareEditor = () => {
		const page = document.querySelector('.cb-automations-editor-page');
		const shell = page?.querySelector('[data-cb-automations-designer-shell]');
		const form = page?.querySelector('[data-cb-automations-editor-form]');
		if (!page || !shell || !form) return;

		ensureInspectorPanel(shell);
		ensureHistoryControls(shell);
		decorateSidebar(shell);

		const identity = shell.querySelector('.cb-automations-design-shell__identity strong');
		if (identity) identity.textContent = config.automationBuilder;

		const toolbar = shell.querySelector('.cb-core-design-shell__toolbar');
		const primarySave = toolbar?.querySelector('button[type="submit"]') || null;
		if (primarySave) primarySave.setAttribute('data-cb-design-shell-primary-action', '');

		let status = toolbar?.querySelector('[data-cb-design-shell-status]') || null;
		if (!status && toolbar) {
			status = document.createElement('div');
			status.className = 'cb-automations-builder-status';
			status.setAttribute('data-cb-design-shell-status', '');
			status.setAttribute('aria-live', 'polite');
			const notice = page.querySelector('.notice p');
			if (notice) status.textContent = String(notice.textContent || '').trim();
			if (primarySave) primarySave.insertAdjacentElement('beforebegin', status);
			else toolbar.append(status);
		}
	};

	enhanceLibrary();
	prepareEditor();
})();
