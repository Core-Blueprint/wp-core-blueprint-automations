(() => {
	'use strict';

	const ASYNC_FIELD = 'cb_automations_builder_async';
	const strings = {
		saving: 'Saving automation…',
		failed: 'The automation could not be saved.',
		savedWithChanges: 'Automation saved. Newer edits are not saved yet.',
		ready: 'Ready',
		needsAttention: 'Needs attention',
		validDescription: 'The current definition is valid against the live capability catalog.',
		invalidDescription: 'Resolve these items before the automation can be enabled.',
		workflowIssue: 'Workflow issue',
		conflictSave: 'This automation was changed elsewhere. Your unsaved draft is still open. Review it before reloading or trying to save again.',
		conflictReview: 'Review latest version in a new tab',
		unknownSave: 'The server response was incomplete. Your draft remains open. Verify the saved workflow before attempting another save.',
		historyUnavailable: 'Designer history is unavailable. No save was attempted.',
		historyReconcileFailed: 'Saved on the server, but newer Designer edits could not be reconciled safely. Your local draft is preserved.',
		activationEnabled: 'Enabled',
		activationDisabled: 'Disabled',
		validationValid: 'Valid',
		validationNeedsReview: 'Needs review',
		validationDependencyUnavailable: 'Dependency unavailable',
		validationInvalid: 'Invalid',
		...(window.cbAutomationsSaveStrings || {}),
	};
	const form = document.querySelector('[data-cb-automations-editor-form]');
	const shell = document.querySelector('[data-cb-automations-designer-shell]');
	const save = shell?.querySelector('[data-cb-design-shell-primary-action]');
	const status = shell?.querySelector('[data-cb-design-shell-status]');
	const revision = form?.querySelector('[name="revision"]');
	const name = form?.querySelector('[name="name"]');
	const activation = form?.querySelector('[name="activation_state"]');
	const definition = form?.querySelector('[data-cb-automations-definition]');
	const principalLabel = form?.querySelector('[data-cb-automations-principal-label]');
	const rebindPrincipal = form?.querySelector('[data-cb-automations-rebind-principal]');
	const health = shell?.querySelector('.cb-automations-validation-panel');
	const aliases = Array.from(form?.querySelectorAll('[data-cb-automations-save-alias]') || []);
	const headingTitle = document.querySelector('.cb-automations-editor-heading .cb-core-title');
	const headingStatuses = document.querySelectorAll('.cb-automations-editor-statuses .cb-core-status');
	const activationStatus = headingStatuses.item(0);
	const validationStatus = headingStatuses.item(1);
	if (!(form instanceof HTMLFormElement) || !shell || !(save instanceof HTMLButtonElement) || typeof window.fetch !== 'function') return;

	let saving = false;
	const parseDefinition = (value) => {
		try {
			const parsed = JSON.parse(String(value || '{}'));
			return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : null;
		} catch (error) {
			return null;
		}
	};
	const emit = (state, message = '', extra = {}) => {
		const text = String(message || '');
		if (status) status.textContent = text;
		shell.toggleAttribute('aria-busy', state === 'saving');
		shell.dispatchEvent(new CustomEvent('cb:design-shell:savechange', {
			bubbles: true,
			detail: Object.freeze({ state, message: text, ...extra }),
		}));
	};
	const responseMessage = (payload, fallback) => {
		const message = payload?.data?.message;
		return typeof message === 'string' && message.trim() ? message.trim() : fallback;
	};
	const updateStatusIndicator = (indicator, label, dotClass) => {
		if (!(indicator instanceof HTMLElement) || !label || !dotClass) return;
		const labelNode = indicator.querySelector('.cb-core-status__label');
		const dot = indicator.querySelector('.cb-core-status__dot');
		if (labelNode) labelNode.textContent = label;
		if (dot) {
			dot.classList.remove(
				'cb-core-status__dot--success',
				'cb-core-status__dot--warning',
				'cb-core-status__dot--danger',
				'cb-core-status__dot--muted'
			);
			dot.classList.add(dotClass);
		}
	};
	const updatePersistedStatuses = (payload, updateValidation) => {
		const activationState = String(payload?.data?.activation_state || '');
		if (activationState === 'enabled') {
			updateStatusIndicator(activationStatus, strings.activationEnabled, 'cb-core-status__dot--success');
		} else if (activationState === 'disabled') {
			updateStatusIndicator(activationStatus, strings.activationDisabled, 'cb-core-status__dot--muted');
		}

		if (!updateValidation) return;
		const validationState = String(payload?.data?.validation_state || '');
		const validationPresentation = {
			valid: [strings.validationValid, 'cb-core-status__dot--success'],
			needs_review: [strings.validationNeedsReview, 'cb-core-status__dot--warning'],
			dependency_unavailable: [strings.validationDependencyUnavailable, 'cb-core-status__dot--warning'],
			invalid: [strings.validationInvalid, 'cb-core-status__dot--danger'],
		}[validationState];
		if (validationPresentation) {
			updateStatusIndicator(validationStatus, validationPresentation[0], validationPresentation[1]);
		}
	};
	const updateHealth = (payload) => {
		if (!health || typeof payload?.data?.valid !== 'boolean') return;
		shell.dispatchEvent(new CustomEvent('cb-automations:validation-saved', {
			bubbles: true,
			detail: Object.freeze({
				valid: payload.data.valid,
				issues: Array.isArray(payload.data.issues) ? payload.data.issues : [],
			}),
		}));
	};
	const cleanLocation = () => {
		try {
			const url = new URL(window.location.href);
			url.searchParams.delete('notice');
			url.searchParams.delete('builder');
			window.history.replaceState({}, '', url.toString());
		} catch (error) {
			// URL cleanup is optional UI polish.
		}
	};
	const reconcileAfterUnparseableSuccess = () => {
		// A successful HTTP status is not authoritative proof of a persisted CAS.
		// Reloading here would silently destroy newer local edits.
		throw new Error(strings.unknownSave);
	};

	form.addEventListener('submit', async (event) => {
		if (event.submitter !== save && !aliases.includes(event.submitter)) return;
		event.preventDefault();
		if (saving) return;

		// Capture a one-use Base v2 save token at the exact submitted authoring
		// snapshot. Keep the token in memory: it is never posted to WordPress.
		const history = window.cbAutomationsBuilderHistory;
		let checkpoint;
		try {
			if (!history?.captureSave || !history?.acknowledgeSave || !history?.snapshot) {
				throw new Error(strings.historyUnavailable);
			}
			checkpoint = history.captureSave();
			const formDefinition = parseDefinition(definition?.value);
			if (!formDefinition || JSON.stringify(formDefinition) !== JSON.stringify(history.snapshot())) {
				throw new Error(strings.historyUnavailable);
			}
		} catch (error) {
			emit('error', error instanceof Error ? error.message : strings.historyUnavailable);
			return;
		}

		saving = true;
		save.disabled = true;
		aliases.forEach((button) => { button.disabled = true; });
		const submittedDefinitionJson = definition instanceof HTMLInputElement ? definition.value : '';
		const submittedDefinition = parseDefinition(submittedDefinitionJson);
		const submittedName = name instanceof HTMLInputElement ? name.value : null;
		const submittedActivation = activation instanceof HTMLSelectElement ? activation.value : null;
		const submittedRebind = rebindPrincipal instanceof HTMLInputElement ? rebindPrincipal.checked : false;
		emit('saving', strings.saving);

		const payload = new FormData(form);
		payload.set(ASYNC_FIELD, '1');
		const endpoint = form.getAttribute('action') || window.location.href;
		try {
			const response = await window.fetch(endpoint, {
				method: 'POST',
				body: payload,
				credentials: 'same-origin',
			});
			const body = await response.text();
			let result = null;
			try {
				result = JSON.parse(body);
			} catch (error) {
				if (response.ok) {
					reconcileAfterUnparseableSuccess();
					return;
				}
			}
			if (!response.ok || result?.success !== true) {
				const failure = new Error(response.status === 409
					? strings.conflictSave
					: responseMessage(result, strings.failed));
				if (response.status === 409) failure.code = 'REVISION_CONFLICT';
				throw failure;
			}

			// Server-Side EditorSaveBoundary reconstructs private bindings without
			// echoing them to the browser. The accepted authoring projection must
			// be exactly the one submitted in this server-confirmed revision CAS.
			// Verify the server acknowledged exactly one revision-CAS before
			// rebasing Base history or telling the operator their draft was saved.
			const sentRevision = String(payload.get('revision') || '');
			const acknowledgedRevision = result?.data?.revision;
			if (typeof acknowledgedRevision !== 'string'
				|| !/^[1-9][0-9]*$/.test(sentRevision)
				|| !/^[1-9][0-9]*$/.test(acknowledgedRevision)
				|| BigInt(acknowledgedRevision) !== BigInt(sentRevision) + 1n) {
				throw new Error(strings.unknownSave);
			}

			let historyReconciled = true;
			try {
				history.acknowledgeSave(checkpoint, submittedDefinition);
			} catch (_error) {
				historyReconciled = false;
			}
			const definitionDirty = definition instanceof HTMLInputElement && definition.value !== submittedDefinitionJson;
			const nameDirty = name instanceof HTMLInputElement && submittedName !== null && name.value !== submittedName;
			const activationDirty = activation instanceof HTMLSelectElement && submittedActivation !== null && activation.value !== submittedActivation;
			const rebindDirty = rebindPrincipal instanceof HTMLInputElement && rebindPrincipal.checked !== submittedRebind;
			const currentDirty = definitionDirty || nameDirty || activationDirty || rebindDirty;

			if (revision instanceof HTMLInputElement) {
				revision.value = acknowledgedRevision;
			}
			if (!activationDirty && activation instanceof HTMLSelectElement && ['enabled', 'disabled'].includes(result.data?.activation_state)) {
				activation.value = result.data.activation_state;
			}
			if (!nameDirty && headingTitle && typeof result.data?.name === 'string' && result.data.name) {
				headingTitle.textContent = result.data.name;
			}
			if (principalLabel && typeof result.data?.execution_principal_label === 'string' && result.data.execution_principal_label) {
				principalLabel.textContent = result.data.execution_principal_label;
			}
			if (!rebindDirty && submittedRebind && rebindPrincipal instanceof HTMLInputElement) {
				rebindPrincipal.checked = false;
			}
			updatePersistedStatuses(result, !definitionDirty);
			if (!definitionDirty) updateHealth(result);
			cleanLocation();
			if (!historyReconciled) {
				emit('error', strings.historyReconcileFailed);
				return;
			}
			emit(
				'saved',
				currentDirty ? strings.savedWithChanges : responseMessage(result, save.textContent?.trim() || ''),
				{
					baselineDefinition: submittedDefinition,
					baselineName: submittedName,
					baselineActivation: String(result.data?.activation_state || submittedActivation || ''),
					currentDirty: definitionDirty,
				}
			);
		} catch (error) {
			emit('error', error instanceof Error && error.message ? error.message : strings.failed);
			if (error?.code === 'REVISION_CONFLICT' && status) {
				// The current tab retains the complete authoring draft and history.
				// Open the authenticated editor separately for manual reconciliation.
				const review = document.createElement('a');
				review.href = window.location.href;
				review.target = '_blank';
				review.rel = 'noopener noreferrer';
				review.textContent = strings.conflictReview;
				status.append(' ', review);
			}
		} finally {
			saving = false;
			save.disabled = false;
			aliases.forEach((button) => { button.disabled = false; });
		}
	});
	window.cbAutomationsAsyncSaveReady = true;
})();
