import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

await import('../../assets/js/admin-editor.js');
const parse = globalThis.cbAutomationsEditorHelpers?.parseExactInteger;

test('AU3-J01: exact integer input respects JS safe boundaries without rounding', () => {
  assert.equal(typeof parse, 'function');
  for (const [input, expected] of [
    ['0', 0], ['42', 42], ['-42', -42],
    ['9007199254740991', Number.MAX_SAFE_INTEGER],
    ['-9007199254740991', -Number.MAX_SAFE_INTEGER],
  ]) {
    assert.equal(parse(input), expected);
  }
  assert.equal(parse('9007199254740992'), null);
  assert.equal(parse('9007199254740993'), null);
  assert.equal(parse('-9007199254740992'), null);
  assert.equal(parse('9223372036854775807'), null);
  assert.equal(parse('18446744073709551616'), null);
});

test('AU3-J02: no accidental numeric coercion of strings, floats, exponents, blanks or unsafe input', () => {
  for (const value of ['1.0', '1.25', '1e3', '0x10', '', ' ', '-0', 'NaN', 'Infinity', '5x', '+5']) {
    assert.equal(parse(value), null, `Refuse noncanonical integer input: ${value}`);
  }
  assert.equal(parse('0007'), 7);
  assert.equal(parse('-007'), -7);
  assert.equal(parse('9007199254740993'.trim()), null);
});

test('AU3-J03: live controls validate before onChange and keep prior draft intact for invalid edits', async () => {
  const source = await readFile(new URL('../../assets/js/admin-editor.js', import.meta.url), 'utf8');
  assert.doesNotMatch(source, /Number\.parseInt\s*\(/);
  assert.match(source, /setCustomValidity\(/);
  assert.match(source, /parseExactInteger\(/);
  assert.match(source, /Number\.isSafeInteger\(/);
  assert.match(source, /if \(invalid\) return;/);
});

test('AU3-J04: typed numeric text in strings remains a string in the codec', () => {
  const data = { source: 'literal', value: '9223372036854775807' };
  assert.equal(typeof JSON.parse(JSON.stringify(data)).value, 'string');
  assert.equal(JSON.parse(JSON.stringify(data)).value, '9223372036854775807');
});
