(() => {
	'use strict';
	const root = document.querySelector('.cb-automations-workspace');
	if (!root) return;
	const selectAll = root.querySelector('[data-cb-workflow-select-all]');
	const boxes = Array.from(root.querySelectorAll('[data-cb-workflow-select]:not(:disabled)'));
	const bulkButton = root.querySelector('[data-cb-workflow-bulk-submit]');
	const confirmation = root.querySelector('[data-cb-workflow-bulk-confirm]');
	const toolbar = root.querySelector('[data-cb-workflow-bulk-toolbar]');
	const selectedCount = root.querySelector('[data-cb-workflow-selection-count]');
	const clearSelection = root.querySelector('[data-cb-workflow-clear-selection]');
	const sync = () => {
		const count = boxes.filter((box) => box.checked).length;
		if (bulkButton) bulkButton.disabled = count === 0;
		if (toolbar) toolbar.hidden = count === 0;
		if (selectedCount) selectedCount.textContent = String(count);
		if (selectAll) {
			selectAll.checked = count > 0 && count === boxes.length;
			selectAll.indeterminate = count > 0 && count < boxes.length;
		}
		if (confirmation && count === 0) confirmation.checked = false;
	};
	if (selectAll) selectAll.addEventListener('change', () => {
		boxes.forEach((box) => { box.checked = selectAll.checked; });
		sync();
	});
	boxes.forEach((box) => box.addEventListener('change', sync));
	if (clearSelection) clearSelection.addEventListener('click', () => {
		boxes.forEach((box) => { box.checked = false; });
		sync();
	});
	sync();
	root.querySelectorAll('form[data-cb-workflow-delete-confirm]').forEach((form) => {
		form.addEventListener('submit', (event) => {
			if (!window.confirm(form.getAttribute('data-cb-workflow-delete-confirm') || '')) {
				event.preventDefault();
			}
		});
	});
})();
