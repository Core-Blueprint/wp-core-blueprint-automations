import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import vm from 'node:vm';

const code = readFileSync(new URL('../../assets/js/admin-workflow-delete.js', import.meta.url), 'utf8');

test('W3.2 Select All selects only disabled workflows, tracks changes and prevents empty bulk deletion', () => {
	const listeners = new Map();
	const makeBox = () => ({
		checked: false,
		listen: new Map(),
		addEventListener(name, callback) { this.listen.set(name, callback); },
		fire() { this.listen.get('change')?.(); },
	});
	const all = makeBox();
	const a = makeBox();
	const b = makeBox();
	const confirm = { checked: false };
	const button = { disabled: false };
	const root = {
		querySelector(selector) {
			return { '[data-cb-workflow-select-all]': all, '[data-cb-workflow-bulk-submit]': button, '[data-cb-workflow-bulk-confirm]': confirm }[selector];
		},
		querySelectorAll(selector) {
			if (selector === '[data-cb-workflow-select]') return [a, b];
			return [];
		},
	};
	vm.runInNewContext(code, { document: { querySelector: () => root }, window: { confirm: () => true } });
	assert.equal(button.disabled, true);
	all.checked = true;
	all.fire();
	assert.equal(a.checked, true);
	assert.equal(b.checked, true);
	assert.equal(button.disabled, false);
	b.checked = false;
	b.fire();
	assert.equal(all.indeterminate, true);
	a.checked = false;
	a.fire();
	assert.equal(button.disabled, true);
	assert.equal(confirm.checked, false);
});
test('W3.2 individual destructive submit requires browser confirmation', () => {
	let callback;
	const form = {
		getAttribute: () => 'Are you sure?',
		addEventListener(type, fn) { if (type === 'submit') callback = fn; },
	};
	const root = {
		querySelector: () => null,
		querySelectorAll: (sel) => sel.startsWith('form[') ? [form] : [],
	};
	vm.runInNewContext(code, { document: { querySelector: () => root }, window: { confirm: () => false } });
	let prevented = false;
	callback({ preventDefault() { prevented = true; } });
	assert.equal(prevented, true);
});
