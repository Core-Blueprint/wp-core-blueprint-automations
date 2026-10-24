/**
 * Automations G1a v2.1 consumer adapter.
 *
 * Base createDomainSession owns all state/history/checkpoints. Automations owns
 * canonical workflow commands, the redacted authoring projection and server CAS.
 * Never import or reproduce Base's internal history engine.
 */
import { createDomainSession, createDesignerSelectionController, animateLayoutChange } from '@cb-core/design-editor';
import { toProject, fromProject, workflowCommands } from 'cb-automations-domain-project';

const api = window.cbAutomationsEditorSession;
const shell = document.querySelector('[data-cb-automations-designer-shell]');
const undo = shell?.querySelector('[data-cb-design-shell-undo]');
const redo = shell?.querySelector('[data-cb-design-shell-redo]');
const status = shell?.querySelector('[data-cb-automations-history-warning]');

if (api?.snapshot && api?.replace && api?.root && shell
    && undo instanceof HTMLButtonElement && redo instanceof HTMLButtonElement
    && typeof createDomainSession === 'function') {
  const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
  const workflowId = () => String(shell.closest('form')?.querySelector('[name="workflow_id"]')?.value || '');
  const types = Object.freeze({
    'automations.trigger': ['trigger', 'step_id'],
    'automations.state': ['states', 'step_id'],
    'automations.action': ['actions', 'step_id'],
    'automations.condition': ['conditions', 'condition_id'],
  });
  // Pure and synchronous: the project from Base is the sole membership input.
  const resolveSelectionEntity = (ref, project) => {
    if (!ref || ref.kind !== 'entity' || !Object.hasOwn(types, ref.type)) return false;
    const [collection, field] = types[ref.type];
    const definition = fromProject(project);
    const values = collection === 'trigger'
      ? (definition.trigger ? [definition.trigger] : [])
      : definition[collection];
    return values.filter((item) => item?.[field] === ref.id).length === 1;
  };
  let applying = false;
  // Base shell is initialized in presentation-only mode by the existing
  // workspace. The single Base domain session below remains the sole history
  // authority; no local project/history engine is constructed.
  const session = createDomainSession({
    project: toProject(api.snapshot()),
    validateProject: (project) => { fromProject(project); },
    resolveSelectionEntity,
    historyLimit: 100,
    onChange: (project) => {
      // Rendering an Undo/Redo must not create a second history entry.
      const definition = fromProject(project);
      if (same(api.snapshot(), definition)) return;
      applying = true;
      try {
        animateLayoutChange(api.root, () => api.replace(definition, { source: 'history' }));
      } finally {
        applying = false;
      }
    },
  });

  // One lifecycle-managed adapter registry, NOT a second Selection store.
  // A new epoch is never shown before the final Base History notification,
  // including contextChanged with structurally equal DesignProjects.
  const views = new Map();
  const viewResults = new Map();
  let controller = null;
  let renderedEpoch = session.selection().contextEpoch;
  let boundCanvasOrigin = null;
  let pendingEpoch = null;
  let disposed = false;
  const notice = shell.querySelector('[data-cb-design-shell-persistent-notice]');
  const fenceView = () => {
    shell.dataset.cbDesignShellSaveOutcomeUnknown = 'true';
    shell.dataset.cbDesignShellSaveState = 'unknown';
    const message = 'Workflow selection could not be synchronized. Reload the editor before saving.';
    if (notice) {
      notice.hidden = false;
      notice.textContent = message;
      notice.setAttribute('role', 'alert');
    }
    try { shell.dispatchEvent(new CustomEvent('cb:design-shell:failure', {
      bubbles: true, detail: Object.freeze({ phase: 'selection-integrity', message }),
    })); } catch { /* The save fence remains authoritative. */ }
  };
  const captureOrigin = () => Object.freeze({
    expectedEpoch: session.selection().contextEpoch,
    workflowId: workflowId(),
  });
  const isCurrentOrigin = (origin) => Boolean(origin
    && Number.isSafeInteger(origin.expectedEpoch)
    && origin.expectedEpoch === session.selection().contextEpoch
    && origin.workflowId !== '' && origin.workflowId === workflowId()
    && shell.dataset.cbDesignShellSaveOutcomeUnknown !== 'true'
    && shell.dataset.cbDesignShellSaveState !== 'unknown');
  const renderView = (name, context) => {
    if (context.selection.contextEpoch !== renderedEpoch) {
      pendingEpoch = context.selection.contextEpoch;
      return; // Never acknowledge an early B2 Selection projection.
    }
    const render = views.get(name);
    let ok = false;
    try {
      if (typeof render !== 'function') throw new Error('Missing critical view adapter.');
      if (name === 'layers') {
        const origin = captureOrigin();
        if (!boundCanvasOrigin || !same(boundCanvasOrigin, origin)) {
          // Update stale Canvas DOM bindings after Base committed the epoch.
          api.replace(api.snapshot(), { source: 'selection-binding' });
          boundCanvasOrigin = origin;
        }
      }
      ok = render(context) === true;
    } catch (_error) {
      // Base subscriber exception isolation cannot be a success witness.
    } finally {
      viewResults.set(name, ok);
      if (!ok) fenceView();
    }
  };
  const renderAdapters = Object.freeze({
    renderLayers: (context) => renderView('layers', context),
    renderInspector: (context) => renderView('inspector', context),
    syncCanvas: (context) => renderView('canvas', context),
  });
  const registerView = (name, render) => {
    if (disposed || controller || views.has(name)
      || !['layers', 'inspector', 'canvas'].includes(name)
      || typeof render !== 'function') {
      fenceView();
      return false;
    }
    views.set(name, render);
    if (views.size === 3) {
      try {
        controller = createDesignerSelectionController({
          typed: 'domain', session, ...renderAdapters,
          openInspectorOnInsert: false,
        });
        // Subscribe AFTER Base controller to guarantee last-in-order flush.
        releaseFinalHistory = session.subscribeHistory(finalizeSelection);
      } catch (_error) {
        fenceView();
        return false;
      }
    }
    return true;
  };
  let releaseFinalHistory = null;
  const finalizeSelection = () => {
    if (!controller) return;
    const epoch = session.selection().contextEpoch;
    if (epoch === renderedEpoch) return;
    renderedEpoch = epoch;
    pendingEpoch = null;
    viewResults.clear();
    // The controller's own History listener runs FIRST and suppresses any
    // early new-epoch adapter calls. Publish exactly one visible final flush.
    try { controller.refresh(); } catch (_error) { fenceView(); }
  };
  const selectionApi = Object.freeze({
    registerView,
    captureOrigin,
    isCurrentOrigin,
    select: (ref, origin) => {
      if (!controller || !isCurrentOrigin(origin)) return false;
      try { return controller.select(ref, { expectedEpoch: origin.expectedEpoch, source: 'ui' }); }
      catch { return false; }
    },
    clear: (origin) => {
      if (!controller || !isCurrentOrigin(origin)) return false;
      try { return controller.clear({ expectedEpoch: origin.expectedEpoch, source: 'ui' }); }
      catch { return false; }
    },
    verified: (name) => viewResults.get(name) === true
      && pendingEpoch === null
      && renderedEpoch === session.selection().contextEpoch,
    reportView: (name, ok) => {
      if (!['layers', 'inspector', 'canvas'].includes(name) || ok !== true) {
        fenceView();
        return false;
      }
      return true;
    },
    ready: () => controller !== null && views.size === 3,
  });
  window.cbAutomationsBuilderSelection = selectionApi;
  // Classic Inspector initializes before this ESM; it subscribes to this signal.
  window.dispatchEvent(new CustomEvent('cb-automations:selection-ready'));

  const syncButtons = () => {
    const { canUndo, canRedo } = session.historyStatus();
    undo.disabled = !canUndo;
    redo.disabled = !canRedo;
  };
  session.subscribeHistory(syncButtons);

  const run = (direction) => {
    if (shell.hasAttribute('aria-busy')) return false;
    const changed = direction === 'undo' ? session.undo() : session.redo();
    syncButtons();
    return changed;
  };

  api.root.addEventListener('cb-automations:definitionchange', (event) => {
    if (applying || String(event.detail?.source || '') !== 'editor') return;
    const next = event.detail?.definition;
    if (!next || typeof next !== 'object' || Array.isArray(next)) return;
    const previous = fromProject(session.snapshot());
    try {
      session.execute(workflowCommands.editorChange({ expected: previous, next }));
    } catch (_error) {
      // Unsafe commands never enter Base history and must not leave a divergent
      // browser projection available to the Save form.
      applying = true;
      try { api.replace(previous, { source: 'history' }); }
      finally { applying = false; }
      if (status) {
        status.textContent = 'This edit could not be applied safely. The previous workflow was restored.';
        status.hidden = false;
      }
    }
  });

  undo.addEventListener('click', () => run('undo'));
  redo.addEventListener('click', () => run('redo'));
  document.addEventListener('keydown', (event) => {
    if (event.defaultPrevented || !(event.ctrlKey || event.metaKey) || event.altKey) return;
    // Never steal Undo while a Base dialog, another interactive editor, or
    // an active save/context transition owns focus.
    if (shell.hasAttribute('aria-busy')
      || document.querySelector('dialog[open], [role="dialog"][aria-modal="true"]')) return;
    const target = event.target;
    if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement
      || target instanceof HTMLSelectElement || target?.isContentEditable
      || target?.closest?.('[contenteditable], [role="textbox"], [role="combobox"], [role="menu"], [role="listbox"]')) return;
    if (String(event.key || '').toLowerCase() !== 'z') return;
    event.preventDefault();
    run(event.shiftKey ? 'redo' : 'undo');
  });

  const captureSave = () => {
    const checkpoint = session.captureSave();
    const submitted = fromProject(checkpoint.submittedProject);
    // The HTML form and Base project must describe the very same authoring
    // projection. A stale editor/view can never silently save another version.
    if (!same(submitted, api.snapshot())) {
      throw new Error('The Designer session and workflow form no longer agree. No data was saved.');
    }
    return checkpoint; // Token remains in this request's JavaScript memory.
  };

  const acknowledgeSave = (checkpoint, acceptedDefinition) => {
    if (!checkpoint || !checkpoint.token) throw new TypeError('Missing Base save checkpoint.');
    const submitted = fromProject(checkpoint.submittedProject);
    // The server must acknowledge exactly the projection it received. Private
    // fields rebuilt in PHP EditorSaveBoundary never enter the browser.
    if (!same(submitted, acceptedDefinition)) throw new Error('Save acknowledgement does not match the submitted definition.');
    const result = session.rebase({
      saveToken: checkpoint.token,
      baselineProject: toProject(acceptedDefinition),
      source: 'save-baseline',
    });
    syncButtons();
    return result;
  };

  const prepareContext = (definition) => fromProject(toProject(definition));

  // The public Base Domain Session is the only History authority. A witness
  // is observational, not a restorable copy of Base's private Undo/Redo stacks.
  const contextWitness = () => ({
    project: session.snapshot(),
    baseline: session.baselineSnapshot(),
    history: session.historyStatus(),
  });
  const resetForContext = (definition) => {
    const next = prepareContext(definition);
    // All consumer rendering must finish before the irreversible Base commit.
    if (!same(api.snapshot(), next)) throw new Error('Workflow form and context baseline disagree.');
    const before = contextWitness();
    try {
      // Base B1 stages and atomically commits Project, Selection, History,
      // baseline and context epoch. A thrown Base operation restores its own
      // precommit state; callbacks run after commit and are isolated by Base.
      session.replace(toProject(next), { source: 'context', contextChanged: true, resetSelection: true });
    } catch (error) {
      // Never infer refusal from a matching definition alone. Verify the
      // baseline AND Undo/Redo status. If that cannot be observed, fail closed.
      try {
        const after = contextWitness();
        return Object.freeze({
          status: same(before, after) ? 'refused' : 'unknown',
          error,
        });
      } catch {
        return Object.freeze({ status: 'unknown', error });
      }
    }
    // A returned Base replace means commit completed. Never throw a
    // presentation error back through the caller's rollback handler.
    try { syncButtons(); } catch (_error) {
      // Base's own history listener has already isolated presentation errors.
      // Buttons can be refreshed by a later History event; not a rollback.
    }
    // If the postcommit state cannot be verified, it is UNKNOWN, not refused.
    try {
      const after = contextWitness();
      if (!same(fromProject(after.project), next)
        || !same(fromProject(after.baseline), next)
        || after.history.canUndo || after.history.canRedo || after.history.size !== 0) {
        return Object.freeze({ status: 'unknown' });
      }
    } catch {
      return Object.freeze({ status: 'unknown' });
    }
    return Object.freeze({ status: 'committed' });
  };

  // Destroy views and their observers before the sole Base Domain Session.
  window.addEventListener('pagehide', () => {
    if (disposed) return;
    disposed = true;
    try {
      releaseFinalHistory?.();
      controller?.destroy();
    } finally { session.dispose(); }
  }, { once: true });

  window.cbAutomationsBuilderHistory = Object.freeze({
    status: () => session.historyStatus(),
    snapshot: () => fromProject(session.snapshot()),
    baseline: () => fromProject(session.baselineSnapshot()),
    prepareContext,
    contextWitness,
    resetForContext,
    captureSave,
    acknowledgeSave,
    undo: () => run('undo'),
    redo: () => run('redo'),
  });
  syncButtons();
} else {
  if (undo instanceof HTMLButtonElement) undo.disabled = true;
  if (redo instanceof HTMLButtonElement) redo.disabled = true;
  if (status) {
    status.textContent = 'Designer history is unavailable. Workflow saving is paused.';
    status.hidden = false;
  }
}
