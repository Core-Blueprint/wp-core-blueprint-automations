/**
 * ADV-03A Base Designer Foundation adapter.
 *
 * Base exclusively owns manual launch, focus-mode, panel chrome, exit modal
 * and unsaved-change authorization. The Advanced graph runtime owns only the
 * graph canvas and native guarded POST. No parallel fullscreen implementation.
 */
import { createDesignerShell } from '@cb-core/design-editor';

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
	}
}
