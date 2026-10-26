/**
 * Position native HTML popovers next to row action triggers. The Popover API
 * owns dismissal and focus semantics; actions retain their protected POST forms.
 */
(() => {
	'use strict';
	const root = document.querySelector('.cb-automations-workspace');
	if (!root) return;
	root.querySelectorAll('[data-cb-workflow-actions]').forEach((menu) => {
		const trigger = root.querySelector('[popovertarget="' + menu.id + '"]');
		if (!trigger) return;
		menu.addEventListener('toggle', () => {
			if (!menu.matches(':popover-open')) return;
			const rect = trigger.getBoundingClientRect();
			const margin = 8;
			const left = Math.max(margin, Math.min(rect.right - menu.offsetWidth, window.innerWidth - menu.offsetWidth - margin));
			const top = window.innerHeight - rect.bottom >= menu.offsetHeight + margin
				? rect.bottom + 4 : Math.max(margin, rect.top - menu.offsetHeight - 4);
			menu.style.left = left + 'px';
			menu.style.top = top + 'px';
		});
	});
})();
