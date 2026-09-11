import assert from 'node:assert/strict';

await import('../../assets/js/admin-capability-picker.js');

const helpers = globalThis.cbAutomationsCapabilityPickerHelpers;
assert.ok(helpers, 'Capability picker helpers must be available in non-browser test mode.');

const catalog = [
	{
		reference: { kind: 'action', provider: 'woocommerce', id: 'order.note.add', schema_version: '1' },
		label: 'Add order note',
		description: 'Adds a private note to an order.',
		provider: { id: 'woocommerce', name: 'WooCommerce', available: true },
	},
	{
		reference: { kind: 'action', provider: 'core-blueprint.crm', id: 'contact.tag.add', schema_version: '1' },
		label: 'Add contact tag',
		description: 'Applies a CRM tag to the current contact.',
		provider: { id: 'core-blueprint.crm', name: 'Core Blueprint CRM', available: true },
	},
	{
		reference: { kind: 'action', provider: 'core-blueprint.crm', id: 'contact.owner.assign', schema_version: '1' },
		label: 'Assign contact owner',
		description: 'Assigns the contact to a CRM owner.',
		provider: { id: 'core-blueprint.crm', name: 'Core Blueprint CRM', available: true },
	},
];

assert.equal(
	helpers.capabilityKey(catalog[0]),
	'action:woocommerce:order.note.add:1',
	'Capability identity must remain kind + provider + id + schema_version.'
);

assert.equal(helpers.matchesCapability(catalog[0], 'private note'), true, 'Descriptions must be searchable.');
assert.equal(helpers.matchesCapability(catalog[0], 'woocommerce'), true, 'Provider names must be searchable.');
assert.equal(helpers.matchesCapability(catalog[1], 'contact.tag.add'), true, 'Capability IDs must be searchable.');
assert.equal(helpers.matchesCapability(catalog[1], 'invoice'), false, 'Unrelated queries must not match.');

const groups = helpers.groupCapabilities(catalog);
assert.deepEqual(groups.map((group) => group.name), ['Core Blueprint CRM', 'WooCommerce'], 'Providers must be grouped and sorted by human name.');
assert.deepEqual(
	groups[0].items.map((capability) => capability.label),
	['Add contact tag', 'Assign contact owner'],
	'Capabilities inside a provider group must be sorted by human title.'
);

console.log('capability-picker: PASS');
