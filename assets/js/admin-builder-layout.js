(() => {
	'use strict';

	const shell = document.querySelector('[data-cb-automations-designer-shell]');
	const editor = document.querySelector('[data-cb-automations-editor]');
	const palette = shell?.querySelector('.cb-automations-stage-nav');
	const sidebar = shell?.querySelector('.cb-automations-design-shell__sidebar');
	if (!shell || !editor || !palette || !sidebar) return;

	const PALETTE_KEY = 'cb-automations-builder-palette-collapsed';
	const SIDEBAR_KEY = 'cb-automations-builder-sidebar-collapsed';
	const strings = {
		collapseWorkflow: 'Collapse workflow navigation',
		expandWorkflow: 'Expand workflow navigation',
		collapseDetails: 'Collapse details sidebar',
		expandDetails: 'Expand details sidebar',
		...(window.cbAutomationsLayoutStrings || {}),
	};
	const storageGet = (key) => {
		try {
			return window.sessionStorage.getItem(key) === '1';
		} catch (error) {
			return false;
		}
	};
	const storageSet = (key, value) => {
		try {
			window.sessionStorage.setItem(key, value ? '1' : '0');
		} catch (error) {
			// Layout preference is optional session state.
		}
	};

	const paletteToggle = document.createElement('button');
	paletteToggle.type = 'button';
	paletteToggle.className = 'button cb-core-button cb-automations-builder-pane-toggle cb-automations-builder-pane-toggle--palette';
	paletteToggle.setAttribute('aria-label', strings.collapseWorkflow);
	paletteToggle.textContent = '‹';
	palette.prepend(paletteToggle);

	const sidebarToggle = document.createElement('button');
	sidebarToggle.type = 'button';
	sidebarToggle.className = 'button cb-core-button cb-automations-builder-pane-toggle cb-automations-builder-pane-toggle--sidebar';
	sidebarToggle.setAttribute('aria-label', strings.collapseDetails);
	sidebarToggle.textContent = '›';
	sidebar.prepend(sidebarToggle);

	const setPalette = (collapsed, persist = true) => {
		shell.classList.toggle('is-palette-collapsed', collapsed);
		paletteToggle.textContent = collapsed ? '›' : '‹';
		paletteToggle.setAttribute('aria-label', collapsed ? strings.expandWorkflow : strings.collapseWorkflow);
		paletteToggle.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
		if (persist) storageSet(PALETTE_KEY, collapsed);
	};
	const setSidebar = (collapsed, persist = true) => {
		shell.classList.toggle('is-sidebar-collapsed', collapsed);
		sidebarToggle.textContent = collapsed ? '‹' : '›';
		sidebarToggle.setAttribute('aria-label', collapsed ? strings.expandDetails : strings.collapseDetails);
		sidebarToggle.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
		if (persist) storageSet(SIDEBAR_KEY, collapsed);
	};
	const responsiveReset = () => {
		if (window.matchMedia?.('(max-width: 900px)').matches) {
			setPalette(false, false);
			setSidebar(false, false);
		}
	};

	paletteToggle.addEventListener('click', () => setPalette(!shell.classList.contains('is-palette-collapsed')));
	sidebarToggle.addEventListener('click', () => setSidebar(!shell.classList.contains('is-sidebar-collapsed')));

	editor.addEventListener('click', (event) => {
		const card = event.target?.closest?.('.cb-automations-step[data-cb-builder-selectable="1"], .cb-automations-condition[data-cb-builder-selectable="1"]');
		if (!card) return;
		if (event.target?.closest?.('button, a, input, select, textarea, details, summary, label')) return;
		setSidebar(false);
	});

	setPalette(storageGet(PALETTE_KEY), false);
	setSidebar(storageGet(SIDEBAR_KEY), false);
	responsiveReset();
	window.addEventListener('resize', responsiveReset, { passive: true });
})();
