/**
 * Automations G1a v2.1 consumer adapter.
 *
 * Base createDomainSession owns all state/history/checkpoints. Automations owns
 * canonical workflow commands, the redacted authoring projection and server CAS.
 * Never import or reproduce Base's internal history engine.
 */
import { createDomainSession, animateLayoutChange } from '@cb-core/design-editor';
import { toProject, fromProject, workflowCommands } from './automations-domain-project.js';

const api = window.cbAutomationsEditorSession;
const shell = document.querySelector('[data-cb-automations-designer-shell]');
const undo = shell?.querySelector('[data-cb-design-shell-undo]');
const redo = shell?.querySelector('[data-cb-design-shell-redo]');
const status = shell?.querySelector('[data-cb-design-shell-status]');

if (api?.snapshot && api?.replace && api?.root && shell
    && undo instanceof HTMLButtonElement && redo instanceof HTMLButtonElement
    && typeof createDomainSession === 'function') {
  const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
  let applying = false;
  const session = createDomainSession({
    project: toProject(api.snapshot()),
    validateProject: (project) => { fromProject(project); },
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
      if (status) status.textContent = 'This edit could not be applied safely. The previous workflow was restored.';
    }
  });

  undo.addEventListener('click', () => run('undo'));
  redo.addEventListener('click', () => run('redo'));
  document.addEventListener('keydown', (event) => {
    if (event.defaultPrevented || !(event.ctrlKey || event.metaKey) || event.altKey) return;
    const target = event.target;
    if (target instanceof HTMLInputElement || target instanceof HTMLTextAreaElement
      || target instanceof HTMLSelectElement || target?.isContentEditable) return;
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

  const resetForContext = (definition) => {
    // Base replacement invalidates outstanding save tokens and history.
    session.replace(toProject(definition), { source: 'context', contextChanged: true });
    syncButtons();
  };

  window.cbAutomationsBuilderHistory = Object.freeze({
    status: () => session.historyStatus(),
    snapshot: () => fromProject(session.snapshot()),
    baseline: () => fromProject(session.baselineSnapshot()),
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
  if (status) status.textContent = 'Designer history is unavailable. Workflow saving is paused.';
}
