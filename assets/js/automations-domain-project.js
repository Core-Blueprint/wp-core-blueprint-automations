/**
 * AU-1A: pure, authorable Automations DefinitionCodec → G1a v2.1 DesignProject adapter.
 * Not enqueued by the runtime. Does not load Base, persist workflows, keep history,
 * expose credentials, allocate IDs or infer permission. AU-2 owns server-side
 * reconstruction of redacted stored fields; AU-3 owns the live PHP/JS number gate.
 */
const IDENTIFIER = /^[a-z][a-z0-9_]{0,63}$/;
const FIELD = /^[a-z][a-z0-9_]*$/;
const PROVIDER = /^[a-z][a-z0-9]*(?:-[a-z0-9]+)+$/;
const CAPABILITY_ID = /^[a-z][a-z0-9]*(?:\.[a-z][a-z0-9]*)+$/;
const SCHEMA_VERSION = /^[1-9][0-9]*$/;
const FORBIDDEN = new Set(['__proto__', 'prototype', 'constructor']);
const MAX_BYTES = 262144; // PHP PersistencePolicy::MAX_DEFINITION_BYTES
const MAX_DEPTH = 60; // PHP JSON decoder uses depth 64; leave room for envelope.
const COLLECTION_KINDS = Object.freeze({ states: 'state', actions: 'action', conditions: 'condition' });
const has = (object, key) => Object.prototype.hasOwnProperty.call(object, key);
const plain = (value) => value !== null && typeof value === 'object' && !Array.isArray(value)
  && (Object.getPrototypeOf(value) === Object.prototype || Object.getPrototypeOf(value) === null);
const fail = (reason) => { throw new TypeError('Automation domain project: ' + reason + '.'); };
const conflict = () => { throw new Error('Automation command replay conflict; original project unchanged.'); };

function clone(value, seen = new WeakSet(), depth = 0) {
  if (depth > MAX_DEPTH) fail('depth limit exceeded');
  if (value === null || typeof value === 'string' || typeof value === 'boolean') return value;
  if (typeof value === 'number') {
    if (!Number.isFinite(value)) fail('non-finite number');
    if (Number.isInteger(value) && !Number.isSafeInteger(value)) fail('unsafe integer');
    return value;
  }
  if (typeof value !== 'object' || (!plain(value) && !Array.isArray(value))) fail('unsupported JSON value');
  if (seen.has(value)) fail('cyclic data');
  seen.add(value);
  if (Array.isArray(value) && Array.from({ length: value.length }, (_, index) => index).some((index) => !has(value, index))) fail('sparse array is unsupported');
  const copy = Array.isArray(value) ? value.map((item) => clone(item, seen, depth + 1)) : {};
  if (plain(value)) {
    for (const key of Object.keys(value)) {
      if (FORBIDDEN.has(key)) fail('unsafe object field');
      Object.defineProperty(copy, key, { value: clone(value[key], seen, depth + 1), enumerable: true, writable: true, configurable: true });
    }
  }
  seen.delete(value);
  return copy;
}

function freeze(value) {
  if (value && typeof value === 'object') {
    Object.values(value).forEach(freeze);
    Object.freeze(value);
  }
  return value;
}

function exact(value, keys, what) {
  if (!plain(value)) fail(what + ' must be an object');
  const actual = Object.keys(value);
  if (actual.length !== keys.length || actual.some((key) => FORBIDDEN.has(key) || !keys.includes(key))) {
    fail(what + ' has unexpected or missing fields');
  }
}
function identifier(value, what) {
  if (typeof value !== 'string' || !IDENTIFIER.test(value)) fail('invalid ' + what + ' identifier');
}
function binding(value) {
  if (!plain(value)) fail('binding must be an object');
  if (value.source === 'step_output') {
    exact(value, ['source', 'step_id', 'field'], 'binding');
    identifier(value.step_id, 'binding step');
    if (typeof value.field !== 'string' || !FIELD.test(value.field)) fail('invalid binding field');
  } else if (value.source === 'literal') {
    exact(value, ['source', 'value'], 'literal binding');
    const supported = (item) => typeof item === 'string' || typeof item === 'boolean'
      || (typeof item === 'number' && Number.isFinite(item) && (!Number.isInteger(item) || Number.isSafeInteger(item)));
    if (!supported(value.value) && !(Array.isArray(value.value) && value.value.every(supported))) {
      fail('unsupported literal value');
    }
  } else fail('unknown binding source');
}
function step(value, kind) {
  exact(value, ['step_id', 'capability', 'bindings'], 'workflow step');
  identifier(value.step_id, 'step');
  exact(value.capability, ['kind', 'provider', 'id', 'schema_version'], 'capability');
  const ref = value.capability;
  if (ref.kind !== kind) fail('capability kind does not match collection');
  if (typeof ref.provider !== 'string' || !PROVIDER.test(ref.provider)
    || typeof ref.id !== 'string' || !CAPABILITY_ID.test(ref.id)
    || typeof ref.schema_version !== 'string' || !SCHEMA_VERSION.test(ref.schema_version)) {
    fail('invalid capability reference');
  }
  if (!plain(value.bindings)) fail('bindings must be an object');
  for (const [field, input] of Object.entries(value.bindings)) {
    if (!FIELD.test(field)) fail('invalid binding field');
    binding(input);
  }
}
function condition(value) {
  exact(value, ['condition_id', 'left', 'operator', 'right'], 'condition');
  identifier(value.condition_id, 'condition');
  if (typeof value.operator !== 'string' || !FIELD.test(value.operator)) fail('invalid condition operator');
  binding(value.left);
  if (value.right !== null) binding(value.right);
}
function validate(definition) {
  exact(definition, ['definition_version', 'trigger', 'states', 'conditions', 'actions'], 'workflow definition');
  if (definition.definition_version !== 1) fail('unsupported workflow definition version');
  if (definition.trigger !== null) step(definition.trigger, 'trigger');
  const stepIds = new Set(definition.trigger ? [definition.trigger.step_id] : []);
  for (const [collection, kind] of [['states', 'state'], ['actions', 'action']]) {
    if (!Array.isArray(definition[collection])) fail(collection + ' must be a list');
    for (const entry of definition[collection]) {
      step(entry, kind);
      if (stepIds.has(entry.step_id)) fail('duplicate step identifier');
      stepIds.add(entry.step_id);
    }
  }
  if (!Array.isArray(definition.conditions)) fail('conditions must be a list');
  const conditionIds = new Set();
  for (const entry of definition.conditions) {
    condition(entry);
    if (conditionIds.has(entry.condition_id)) fail('duplicate condition identifier');
    conditionIds.add(entry.condition_id);
  }
  if (new TextEncoder().encode(JSON.stringify(definition)).byteLength > MAX_BYTES) fail('definition size exceeds byte limit');
  return definition;
}

/** Accept only the sanitized, authorable definition. Never pass the private stored definition here. */
export function toProject(authorableDefinition) {
  const definition = validate(clone(authorableDefinition));
  return freeze({
    schema_version: 1,
    design_type: 'automations.workflow',
    root: {
      type: 'automations.workflow',
      provider: 'core-blueprint-automations',
      properties: { definition },
      children: [],
    },
  });
}

/** A detached, canonical DefinitionCodec-shaped value; not a persistence authorisation. */
export function fromProject(project) {
  const value = clone(project);
  exact(value, ['schema_version', 'design_type', 'root'], 'DesignProject wrapper');
  if (value.schema_version !== 1 || value.design_type !== 'automations.workflow') fail('incorrect DesignProject wrapper');
  exact(value.root, ['type', 'provider', 'properties', 'children'], 'DesignProject root wrapper');
  if (value.root.type !== 'automations.workflow' || value.root.provider !== 'core-blueprint-automations'
    || !Array.isArray(value.root.children) || value.root.children.length !== 0) fail('incorrect DesignProject children or wrapper');
  exact(value.root.properties, ['definition'], 'DesignProject properties');
  return validate(value.root.properties.definition);
}

function collectionKind(collection) {
  if (!has(COLLECTION_KINDS, collection)) fail('unknown workflow collection');
  return COLLECTION_KINDS[collection];
}
const entryId = (collection, value) => collection === 'conditions' ? value.condition_id : value.step_id;
const equal = (left, right) => JSON.stringify(left) === JSON.stringify(right);
const position = (value) => {
  if (!Number.isSafeInteger(value) || value < 0) fail('invalid command index');
  return value;
};
function makeCommand(label, mutate) {
  return Object.freeze({
    label,
    apply(project) {
      const definition = fromProject(project);
      mutate(definition);
      return Object.freeze({ project: toProject(definition) });
    },
  });
}

/** Each command is a single user intent. Callers supply immutable IDs before command creation. */
export const workflowCommands = Object.freeze({
  /**
   * One editor-originated, fully validated user intent. Base owns history; this
   * command only defines a deterministic, immutable domain transition.
   * Reject a replay against another workflow revision rather than merging data.
   */
  editorChange({ expected, next }) {
    const before = freeze(fromProject(toProject(expected)));
    const after = freeze(fromProject(toProject(next)));
    return makeCommand('editor-change', (definition) => {
      if (!equal(definition, before)) conflict();
      for (const key of Object.keys(definition)) delete definition[key];
      Object.assign(definition, clone(after));
    });
  },
  insert({ collection, index, entry }) {
    const kind = collectionKind(collection);
    position(index);
    const captured = freeze(clone(entry));
    if (kind === 'condition') condition(captured); else step(captured, kind);
    return makeCommand('insert-' + collection, (definition) => {
      const target = definition[collection];
      if (index > target.length || (kind === 'condition'
        ? definition.conditions.some((item) => item.condition_id === captured.condition_id)
        : [definition.trigger, ...definition.states, ...definition.actions].filter(Boolean).some((item) => item.step_id === captured.step_id))) conflict();
      target.splice(index, 0, clone(captured));
    });
  },
  remove({ collection, id, expected }) {
    const kind = collectionKind(collection);
    identifier(id, 'command target');
    const old = freeze(clone(expected));
    if (kind === 'condition') condition(old); else step(old, kind);
    if (entryId(collection, old) !== id) fail('command identifier differs from expected entry');
    return makeCommand('remove-' + collection, (definition) => {
      const list = definition[collection];
      const index = list.findIndex((item) => entryId(collection, item) === id);
      if (index < 0 || !equal(list[index], old)) conflict();
      list.splice(index, 1);
    });
  },
  move({ collection, id, fromIndex, toIndex }) {
    collectionKind(collection);
    identifier(id, 'command target');
    position(fromIndex);
    position(toIndex);
    return makeCommand('move-' + collection, (definition) => {
      const list = definition[collection];
      if (fromIndex >= list.length || toIndex >= list.length || entryId(collection, list[fromIndex]) !== id) conflict();
      const [moving] = list.splice(fromIndex, 1);
      list.splice(toIndex, 0, moving);
    });
  },
  replace({ collection, id, expected, entry }) {
    const kind = collectionKind(collection);
    identifier(id, 'command target');
    const old = freeze(clone(expected));
    const next = freeze(clone(entry));
    if (kind === 'condition') { condition(old); condition(next); }
    else { step(old, kind); step(next, kind); }
    if (entryId(collection, old) !== id || entryId(collection, next) !== id) fail('command identifier cannot change');
    return makeCommand('replace-' + collection, (definition) => {
      const list = definition[collection];
      const index = list.findIndex((item) => entryId(collection, item) === id);
      if (index < 0 || !equal(list[index], old)) conflict();
      list[index] = clone(next);
    });
  },
  setTrigger({ expected, entry }) {
    const old = freeze(clone(expected));
    const next = freeze(clone(entry));
    if (old !== null) step(old, 'trigger');
    if (next !== null) step(next, 'trigger');
    if (old !== null && next !== null && old.step_id !== next.step_id) fail('trigger identifier cannot change');
    return makeCommand('set-trigger', (definition) => {
      if (!equal(definition.trigger, old)) conflict();
      definition.trigger = clone(next);
    });
  },
});
