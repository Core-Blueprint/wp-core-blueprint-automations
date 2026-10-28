import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const source = readFileSync(new URL('../../assets/js/admin-advanced-editor.js', import.meta.url), 'utf8');
test('ADV-03A editor remains scoped to Advanced root and does not touch Standard session', () => {
  assert.match(source, /data-cb-advanced-root/);
  assert.doesNotMatch(source, /data-cb-automations-editor-form|@cb-core\/design-editor|GraphSandboxAdmission/);
  assert.match(source, /definition_version !== 2/);
});
test('ADV-03A supports canonical editor graph operations', () => {
  for (const value of ['trigger', 'state', 'if_else', 'action', 'exclusive_merge', 'end']) {
    assert.match(source, new RegExp('\\b' + value + '\\b'));
  }
  for (const value of ['setPointerCapture', 'canConnect(', 'outgoing(', 'incoming(', 'removeNode(', 'JSON.stringify(draft)', 'beforeunload']) {
    assert.ok(source.includes(value), value);
  }
});
test('ADV-03A keeps type, cap and precision fences on serialized data', () => {
  assert.match(source, /Number\.isSafeInteger/);
  assert.match(source, /field\?\.sensitive/);
  assert.match(source, /documentField\.checkValidity\(\)/);
  assert.match(source, /size > 262144/);
  assert.doesNotMatch(source, /RuntimeWorker|wp\.ajax|admin-ajax\.php/);
});