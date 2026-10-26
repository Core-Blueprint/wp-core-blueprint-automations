import assert from 'node:assert/strict';
import test from 'node:test';
import { readFileSync } from 'node:fs';

const source = (path) => readFileSync(new URL('../../assets/js/' + path, import.meta.url), 'utf8');

test('R3 condition preview has one renderer and stable post-grid placement', () => {
  const polish = source('admin-editor-polish.js');
  const builder = source('admin-condition-builder.js');

  // The polish layer retains its own step titles and advanced metadata.
  assert.match(polish, /function polishConditions\(\)/);
  assert.match(polish, /setStepTitle\(card, `\$\{strings\.condition/);
  assert.match(polish, /ensureConditionDetails\(card, conditionId\)/);

  // The domain-aware condition builder handles unary arity and type-aware
  // operator wording. It is the only module to construct the summary node.
  assert.match(builder, /function updatePreview\(card, grid, leftSelect, operatorSelect, rightWrap\)/);
  assert.match(builder, /const arity = Number\(operatorCatalog\[operator\]\?\.arity \|\| 2\)/);
  assert.match(builder, /grid\.insertAdjacentElement\('afterend', summary\)/);
  assert.match(builder, /summary\.replaceChildren\(\)/);

  // Never reintroduce a second summary writer that races on the same
  // conditionchange/input/click microtasks and moves the DOM above the grid.
  assert.doesNotMatch(polish, /cb-automations-condition-summary/);
  assert.doesNotMatch(polish, /conditionRightText\(|function literalText\(/);
  assert.match(builder, /summary\.className = 'cb-automations-condition-summary';/);
});
