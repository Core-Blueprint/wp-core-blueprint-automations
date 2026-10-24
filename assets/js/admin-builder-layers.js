/**
 * Automations projection of the PUBLIC Base B2 LayerTree.
 * Selection belongs only to the existing typed Domain Session; this adapter
 * never stores its own selected identity or invokes a second History engine.
 */
import { createDesignerLayerTree } from '@cb-core/design-editor';

const shell = document.querySelector('[data-cb-automations-designer-shell]');
const host = shell?.querySelector('[data-cb-automations-layers-host]');
const editor = window.cbAutomationsEditorSession;
const selection = window.cbAutomationsBuilderSelection;

if (shell && host && editor?.layerAction && selection?.registerView) {
  const navigation = window.cbAutomationsInspectorStrings?.navigation || {};
  const labels = window.cbAutomationsInspectorStrings?.layers || {};
  const controller = new AbortController();
  const { signal } = controller;
  const tree = createDesignerLayerTree({
    documentRef: document,
    ariaLabel: String(labels.ariaLabel || 'Workflow layers'),
  });
  host.append(tree.element);

  const reference = (kind, id) => Object.freeze({
    kind: 'entity', type: 'automations.' + kind, id,
  });
  const render = ({ selection: selected, project }) => {
    const origin = selection.captureOrigin(); // Concrete render-time epoch AND workflow ID.
    const definition = project.root.properties.definition;
    const primary = selected.primary;
    const active = (kind, id) => primary?.kind === 'entity'
      && primary.type === 'automations.' + kind && primary.id === id;
    const select = (kind, id) => () => selection.select(reference(kind, id), origin);
    const allowed = (kind, id, operation, target = -1) => {
      // An old callback from workflow A may not mutate B even if their IDs match.
      if (!selection.isCurrentOrigin(origin) || shell.hasAttribute('aria-busy')) return false;
      return editor.layerAction(operation, kind, id, target) === true;
    };
    const nodes = (items, kind, label) => {
      if (!Array.isArray(items)) return [];
      const field = kind === 'condition' ? 'condition_id' : 'step_id';
      return items.flatMap((item, index) => {
        const id = item?.[field];
        if (typeof id !== 'string' || !id
          || items.filter((candidate) => candidate?.[field] === id).length !== 1) return [];
        return [{
          key: kind + ':' + id,
          label: String(label || kind) + ' ' + (index + 1),
          selected: active(kind, id),
          onSelect: select(kind, id),
          actions: { remove: () => allowed(kind, id, 'remove') },
          ...(items.length > 1 ? { reorder: {
            index, minIndex: 0, maxIndex: items.length - 1,
            onMove: (target) => allowed(kind, id, 'move', target),
          } } : {}),
        }];
      });
    };
    tree.render([
      { key: 'stage:trigger', label: String(navigation.trigger || 'WHEN'), children: [{
        key: 'trigger',
        label: String(navigation.triggerDescription || 'Trigger'),
        selected: Boolean(definition.trigger && active('trigger', definition.trigger.step_id)),
        ...(definition.trigger ? { onSelect: select('trigger', definition.trigger.step_id) } : {}),
      }] },
      { key: 'stage:states', label: String(navigation.states || 'GET DATA'),
        children: nodes(definition.states, 'state', labels.state || navigation.statesDescription || 'Data lookup') },
      { key: 'stage:conditions', label: String(navigation.conditions || 'ONLY IF'),
        children: nodes(definition.conditions, 'condition', labels.condition || navigation.conditionsDescription || 'Condition') },
      { key: 'stage:actions', label: String(navigation.actions || 'THEN'),
        children: nodes(definition.actions, 'action', labels.action || navigation.actionsDescription || 'Action') },
    ]);
    return true;
  };

  shell.addEventListener('cb-automations:contextloaded', (event) => {
    // The single B2 controller already published final views at History.
    // This is only A2-R1's synchronous postcommit acknowledgement.
    event.detail?.acknowledgeView?.('layers', selection.verified('layers'));
  }, { signal });
  if (!selection.registerView('layers', render)) {
    host.setAttribute('role', 'alert');
    host.textContent = String(window.cbAutomationsInspectorStrings?.contextUnavailable || 'Workflow layers are unavailable. Reload the editor.');
  }
  selection.registerCleanup?.(() => controller.abort());
} else if (host) {
  host.setAttribute('role', 'alert');
  host.textContent = String(window.cbAutomationsInspectorStrings?.contextUnavailable || 'Workflow layers are unavailable. Reload the editor.');
}
