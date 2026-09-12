(() => {
	'use strict';

	const STORAGE_KEY = 'cb-automations-builder-workflow';
	const config = {
		automationBuilder: 'Automation Builder',
		openBuilder: 'Open builder',
		...(window.cbAutomationsBuilderStrings || {}),
	};

	const storedBuilder = () => {
		try {
			return String(window.sessionStorage.getItem(STORAGE_KEY) || '').trim();
		} catch (error) {
			return '';
		}
	};

	const rememberBuilder = (workflowId) => {
		try {
			window.sessionStorage.setItem(STORAGE_KEY, String(workflowId || '').trim());
		} catch (error) {
			// Builder session state is optional UI state.
		}
	};

	const forgetBuilder = (workflowId = '') => {
		try {
			const current = storedBuilder();
			if (!workflowId || current === workflowId || current === 'pending') {
				window.sessionStorage.removeItem(STORAGE_KEY);
			}
		} catch (error) {
			// Builder session state is optional UI state.
		}
	};

	const enhanceLibrary = () => {
		const createEyebrow = document.querySelector('.cb-automations-create-panel .cb-automations-panel-eyebrow');
		if (createEyebrow) createEyebrow.textContent = config.automationBuilder;

		document.querySelectorAll('.cb-automations-workflow-card').forEach((card) => {
			if (!(card instanceof HTMLAnchorElement)) return;
			let url;
			try {
				url = new URL(card.href, window.location.href);
			} catch (error) {
				return;
			}
			const workflowId = String(url.searchParams.get('workflow') || '').trim();
			if (!workflowId) return;
			url.searchParams.set('builder', '1');
			card.href = url.toString();
			const open = card.querySelector('.cb-automations-workflow-card__open');
			if (open) open.textContent = `${config.openBuilder} →`;
			card.addEventListener('click', () => rememberBuilder(workflowId));
		});

		const createForm = document.querySelector('.cb-automations-create-form');
		createForm?.addEventListener('submit', () => rememberBuilder('pending'));
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
		const heading = page?.querySelector('.cb-automations-editor-heading');
		const form = page?.querySelector('[data-cb-automations-editor-form]');
		if (!page || !shell || !heading || !form) return;

		page.setAttribute('data-cb-design-launch-root', '');
		heading.setAttribute('data-cb-design-launch-context', '');
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

		const workflowId = String(form.querySelector('[name="workflow_id"]')?.value || '').trim();
		const params = new URLSearchParams(window.location.search);
		const stored = storedBuilder();
		const autoStart = params.get('builder') === '1'
			|| params.get('notice') === 'created'
			|| stored === workflowId
			|| stored === 'pending';

		if (autoStart && workflowId) rememberBuilder(workflowId);

		shell.addEventListener('cb:design-shell:fullscreenchange', (event) => {
			if (event.detail?.fullscreen) {
				if (workflowId) rememberBuilder(workflowId);
				return;
			}
			forgetBuilder(workflowId);
		});

		form.addEventListener('submit', () => {
			const fullscreen = shell.querySelector('[data-cb-design-shell-fullscreen]');
			if (workflowId && fullscreen?.getAttribute('aria-pressed') === 'true') rememberBuilder(workflowId);
		});

		if (!autoStart) return;

		const openBuilder = () => {
			const button = page.querySelector('[data-cb-design-launch] .cb-core-design-launch');
			if (!(button instanceof HTMLButtonElement)) return false;
			button.click();
			return true;
		};

		if (openBuilder()) return;
		const observer = new MutationObserver(() => {
			if (!openBuilder()) return;
			observer.disconnect();
		});
		observer.observe(page, { childList: true, subtree: true });
	};

	enhanceLibrary();
	prepareEditor();
})();
