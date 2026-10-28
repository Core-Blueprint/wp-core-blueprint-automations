(() => {
	'use strict';
	const root = document.querySelector('[data-cb-advanced-root]');
	const form = document.querySelector('[data-cb-advanced-form]');
	const data = document.getElementById('cb-advanced-editor-data');
	if (!root || !(form instanceof HTMLFormElement) || !data) return;
	const documentField = form.querySelector('[data-cb-advanced-definition]');
	const scene = root.querySelector('[data-cb-advanced-scene]');
	const layer = root.querySelector('[data-cb-advanced-nodes]');
	const wires = root.querySelector('[data-cb-advanced-wires]');
	const inspector = root.querySelector('[data-cb-advanced-inspector]');
	const dirtyLabel = root.querySelector('[data-cb-advanced-dirty]');
	const nameField = form.querySelector('[name="name"]');
	if (!(documentField instanceof HTMLInputElement) || !scene || !layer || !wires || !inspector) return;

	let bootstrap;
	try { bootstrap = JSON.parse(data.textContent); } catch { return; }
	const schema = bootstrap?.definition;
	if (schema?.mode !== 'advanced' || schema?.definition_version !== 2
		|| !Array.isArray(schema.graph?.nodes) || !Array.isArray(schema.graph?.edges)) return;
	const clone = (item) => JSON.parse(JSON.stringify(item));
	let draft = clone(schema);
	if (!draft.layout || !draft.layout.nodes || Array.isArray(draft.layout.nodes)) draft.layout = {nodes: {}};
	const capabilities = Array.isArray(bootstrap.capabilities) ? bootstrap.capabilities : [];
	const validId = (value) => /^[a-z][a-z0-9_]{0,63}$/.test(value);
	const labels = Object.freeze({trigger: 'Trigger', state: 'State', if_else: 'IF / ELSE', action: 'Action', exclusive_merge: 'Merge', end: 'End'});
	const occupied = (obj) => obj && typeof obj === 'object' && !Array.isArray(obj) && Object.keys(obj).length > 0;
	const eqRef = (a, b) => ['kind', 'provider', 'id', 'schema_version'].every(key => a?.[key] === b?.[key]);
	const make = (tag, className, text) => {
		const el = document.createElement(tag);
		if (className) el.className = className;
		if (text !== undefined) el.textContent = text;
		return el;
	};
	const input = (label, type = 'text', value = '') => {
		const wrap = make('label', 'cb-advanced-field');
		wrap.append(make('span', '', label));
		const control = make('input');
		control.type = type;
		control.value = String(value);
		wrap.append(control);
		return {wrap, control};
	};
	const select = (label, options, value = '') => {
		const wrap = make('label', 'cb-advanced-field');
		wrap.append(make('span', '', label));
		const control = make('select');
		for (const [id, text] of options) {
			const option = new Option(String(text), String(id));
			control.add(option);
		}
		if (!options.some(([id]) => String(id) === String(value)) && value !== '') {
			control.add(new Option(String(value) + ' (unavailable)', String(value)));
		}
		control.value = String(value);
		wrap.append(control);
		return {wrap, control};
	};
	const button = (label, callback, secondary = false) => {
		const el = make('button', 'button' + (secondary ? '' : ' button-primary'), label);
		el.type = 'button';
		el.addEventListener('click', callback);
		return el;
	};
	const uid = (prefix) => {
		if (!globalThis.crypto?.randomUUID) throw new Error('Secure node identity is unavailable.');
		return prefix + '_' + crypto.randomUUID().replaceAll('-', '').slice(0, 24);
	};
	const find = (id) => draft.graph.nodes.find(node => node.id === id);
	let current = draft.graph.nodes[0]?.id || null;
	let changed = false;
	let saving = false;
	root.dataset.cbAdvancedDirty = 'false';
	const mark = () => {
		changed = true;
		root.dataset.cbAdvancedDirty = 'true';
		if (dirtyLabel) dirtyLabel.textContent = 'Unsaved changes';
	};
	const coords = (id, index = 0) => {
		const p = draft.layout.nodes[id];
		if (p && Number.isFinite(p.x) && Number.isFinite(p.y)) return p;
		const fresh = {x: 80 + index * 210, y: 90 + (index % 3) * 155};
		draft.layout.nodes[id] = fresh;
		return fresh;
	};
	const configCap = (node) => capabilities.find(item => item.available && eqRef(item.reference, node.config?.capability));
	const typeOptions = (kind) => capabilities.filter(cap => cap.reference?.kind === kind && cap.available)
		.map(cap => [JSON.stringify(cap.reference), cap.label + ' · ' + cap.reference.provider]);

	const rebuild = () => { draw(); inspect(); sync(); };
	const sync = () => { documentField.value = JSON.stringify(draft); };
	const outgoing = (type) => type === 'end' ? [] : type === 'if_else' ? ['true', 'false'] : ['next'];
	const incoming = (type) => type === 'trigger' ? [] : type === 'exclusive_merge' ? ['true', 'false'] : ['in'];
	const canConnect = (from, fromPort, to, toPort) => {
		const a = find(from), b = find(to);
		return a && b && from !== to && outgoing(a.type).includes(fromPort) && incoming(b.type).includes(toPort)
			&& !draft.graph.edges.some(edge => edge.from === from && edge.from_port === fromPort)
			&& !draft.graph.edges.some(edge => edge.to === to && edge.to_port === toPort);
	};
	const connect = (from, fromPort, to, toPort) => {
		if (!canConnect(from, fromPort, to, toPort) || draft.graph.edges.length >= 1024) return false;
		draft.graph.edges.push({id: uid('e'), from, from_port: fromPort, to, to_port: toPort});
		mark(); rebuild(); return true;
	};
	const addNode = (type) => {
		if (!Object.hasOwn(labels, type) || draft.graph.nodes.length >= 512) return;
		if ((type === 'trigger' || type === 'end') && draft.graph.nodes.some(node => node.type === type)) return;
		const id = uid('n');
		draft.graph.nodes.push({id, type, config: {}});
		coords(id, draft.graph.nodes.length - 1);
		current = id;
		mark(); rebuild();
	};
	const removeNode = (id) => {
		const node = find(id);
		if (!node || node.type === 'trigger' || node.type === 'end') return;
		draft.graph.nodes = draft.graph.nodes.filter(item => item.id !== id);
		draft.graph.edges = draft.graph.edges.filter(edge => edge.from !== id && edge.to !== id);
		delete draft.layout.nodes[id];
		for (const branch of draft.graph.nodes) {
			if (branch.type === 'if_else' && branch.config?.merge_id === id) branch.config = {};
			if (occupied(branch.config?.bindings)) {
				for (const [field, binding] of Object.entries(branch.config.bindings)) {
					if (binding?.source === 'step_output' && binding.step_id === id) delete branch.config.bindings[field];
				}
			}
		}
		current = draft.graph.nodes[0]?.id || null;
		mark(); rebuild();
	};
	const draw = () => {
		layer.replaceChildren(); wires.replaceChildren();
		for (const edge of draft.graph.edges) {
			const a = find(edge.from), b = find(edge.to);
			if (!a || !b) continue;
			const p = coords(a.id), q = coords(b.id);
			const sx = p.x + 184, sy = p.y + (edge.from_port === 'false' ? 72 : edge.from_port === 'true' ? 45 : 56);
			const ex = q.x, ey = q.y + (edge.to_port === 'false' ? 70 : edge.to_port === 'true' ? 40 : 55);
			const dx = Math.max(60, Math.abs(ex - sx) * 0.52);
			const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
			path.setAttribute('d', `M${sx} ${sy} C${sx + dx} ${sy}, ${ex - dx} ${ey}, ${ex} ${ey}`);
			path.setAttribute('class', 'cb-advanced-edge');
			wires.append(path);
			const group = document.createElementNS('http://www.w3.org/2000/svg', 'text');
			group.textContent = edge.from_port === 'next' ? '' : edge.from_port.toUpperCase();
			group.setAttribute('x', String((sx + ex) / 2));
			group.setAttribute('y', String((sy + ey) / 2 - 7));
			group.setAttribute('class', 'cb-advanced-edge-label');
			wires.append(group);
		}
		draft.graph.nodes.forEach((node, index) => {
			const point = coords(node.id, index);
			const card = make('div', 'cb-advanced-node' + (current === node.id ? ' is-selected' : ''));
			card.style.left = point.x + 'px'; card.style.top = point.y + 'px';
			card.dataset.nodeId = node.id;
			card.setAttribute('role', 'group');
			card.setAttribute('aria-label', labels[node.type] + ' ' + node.id);
			const title = make('button', 'cb-advanced-node-title', labels[node.type]);
			title.type = 'button'; title.title = 'Drag to move · Click to inspect';
			title.addEventListener('click', () => { current = node.id; rebuild(); });
			const detail = make('span', 'cb-advanced-node-details', configCap(node)?.label || (occupied(node.config) ? 'Configured' : 'Not configured'));
			const idlabel = make('span', 'cb-advanced-node-id', node.id.slice(0, 16));
			card.append(title, detail, idlabel); layer.append(card);
			title.addEventListener('pointerdown', event => {
				if (event.button !== 0) return;
				const origin = {x: event.clientX, y: event.clientY, left: point.x, top: point.y};
				const move = e => {
					const nx = Math.round(Math.max(0, Math.min(1400 - 184, origin.left + e.clientX - origin.x)));
					const ny = Math.round(Math.max(0, Math.min(900 - 110, origin.top + e.clientY - origin.y)));
					point.x = nx; point.y = ny;
					card.style.left = nx + 'px'; card.style.top = ny + 'px';
					for (const edge of wires.querySelectorAll('path')) edge.setAttribute('opacity', '.2');
				};
				const end = e => {
					title.releasePointerCapture?.(e.pointerId);
					title.removeEventListener('pointermove', move);
					title.removeEventListener('pointerup', end);
					title.removeEventListener('pointercancel', end);
					if (origin.left !== point.x || origin.top !== point.y) mark();
					current = node.id; rebuild();
				};
				title.setPointerCapture?.(event.pointerId);
				title.addEventListener('pointermove', move);
				title.addEventListener('pointerup', end);
				title.addEventListener('pointercancel', end);
			});
		});
	};

	const parseLiteral = (raw, field) => {
		const type = field?.type;
		if (type === 'boolean') return raw === 'true';
		if (type === 'integer') {
			if (!/^-?(?:0|[1-9]\d*)$/.test(raw)) throw new Error('Enter a valid integer.');
			const n = Number(raw);
			if (!Number.isSafeInteger(n)) throw new Error('Integer exceeds safe browser precision.');
			return n;
		}
		if (type === 'number') {
			const n = Number(raw);
			if (!raw.trim() || !Number.isFinite(n) || (Number.isInteger(n) && !Number.isSafeInteger(n))) throw new Error('Enter a valid number.');
			return n;
		}
		if (type === 'array') {
			const items = raw.trim() ? raw.split('\n') : [];
			return items.map(item => parseLiteral(item, {type: field.items}));
		}
		return raw;
	};
	const formatLiteral = (binding) => {
		const v = binding?.value;
		return Array.isArray(v) ? v.join('\n') : typeof v === 'boolean' ? String(v) : v === undefined || v === null ? '' : String(v);
	};
	const outputChoices = (target) => {
		const options = [['', 'Select output']];
		for (const node of draft.graph.nodes) {
			if (node.id === target.id) continue;
			const cap = configCap(node);
			for (const field of Object.keys(cap?.output_schema || {})) options.push([node.id + ':' + field, labels[node.type] + ' / ' + field]);
		}
		return options;
	};
	const addBinding = (holder, label, node, initial, field, setter) => {
		const block = make('div', 'cb-advanced-binding'); holder.append(block);
		const source = initial?.source || '';
		const choices = [['', 'Not connected'], ['literal', 'Value'], ['step_output', 'Workflow data']];
		if (field?.sensitive) choices.splice(1, 1);
		const picker = select(label, choices, source);
		block.append(picker.wrap);
		const details = make('div'); block.append(details);
		const write = value => { setter(value); mark(); sync(); };
		const renderDetails = (selection) => {
			details.replaceChildren();
			if (selection === 'literal') {
				if (field?.sensitive) return;
				let raw = formatLiteral(initial);
				const type = field?.type || 'string';
				const text = type === 'boolean' ? select('Value', [['true', 'True'], ['false', 'False']], raw || 'false') : input('Value', 'text', raw);
				if (type === 'array') {
					const textarea = make('textarea'); textarea.value = raw; textarea.rows = 3;
					text.control.replaceWith(textarea); text.control = textarea;
				}
				details.append(text.wrap);
				const error = make('small', 'cb-advanced-error'); details.append(error);
				const commit = () => {
					try { write({source: 'literal', value: parseLiteral(text.control.value, field)}); error.textContent = ''; }
					catch (e) { error.textContent = e.message; documentField.setCustomValidity(e.message); }
				};
				text.control.addEventListener('change', () => { documentField.setCustomValidity(''); commit(); });
				if (source !== 'literal') commit();
			} else if (selection === 'step_output') {
				const value = initial?.source === 'step_output' ? initial.step_id + ':' + initial.field : '';
				const output = select('Output', outputChoices(node), value);
				details.append(output.wrap);
				output.control.addEventListener('change', () => {
					const [step_id, field] = output.control.value.split(':');
					write(step_id && field ? {source:'step_output', step_id, field} : null);
				});
				if (source !== 'step_output') write(null);
			} else if (source !== '') write(null);
		};
		picker.control.addEventListener('change', () => renderDetails(picker.control.value));
		renderDetails(source);
	};
	const inspect = () => {
		inspector.replaceChildren();
		const node = find(current);
		if (!node) { inspector.append(make('p', 'description', 'Select a node on the canvas.')); return; }
		inspector.append(make('h3', '', labels[node.type]));
		inspector.append(make('p', 'description', node.id));
		if (['trigger', 'state', 'action'].includes(node.type)) {
			const options = [['', 'Choose capability'], ...typeOptions(node.type)];
			const matched = capabilities.find(item => eqRef(item.reference, node.config?.capability));
			const selected = matched ? JSON.stringify(matched.reference) : occupied(node.config) ? JSON.stringify(node.config.capability) : '';
			const picker = select('Capability', options, selected);
			inspector.append(picker.wrap);
			picker.control.addEventListener('change', () => {
				let ref;
				try { ref = JSON.parse(picker.control.value); } catch { ref = null; }
				node.config = ref ? {capability: ref, bindings: {}} : {};
				mark(); rebuild();
			});
			const capability = configCap(node);
			if (capability) {
				inspector.append(make('p', 'description', capability.description));
				if (node.type !== 'trigger') {
					for (const [fieldName, field] of Object.entries(capability.input_schema || {})) {
						const currentBinding = node.config.bindings[fieldName] || null;
						addBinding(inspector, fieldName + (field.required ? ' *' : ''), node, currentBinding, field, binding => {
							if (binding) node.config.bindings[fieldName] = binding;
							else delete node.config.bindings[fieldName];
						});
					}
				}
			}
		} else if (node.type === 'if_else') {
			const mergeOptions = [['', 'Select merge node'], ...draft.graph.nodes.filter(n => n.type === 'exclusive_merge').map(n => [n.id, 'Merge · ' + n.id.slice(0, 12)])];
			const merge = select('Merge', mergeOptions, node.config.merge_id || ''); inspector.append(merge.wrap);
			const operators = ['equals', 'not_equals', 'contains', 'not_contains', 'greater_than', 'greater_than_or_equal', 'less_than', 'less_than_or_equal', 'is_empty', 'is_not_empty'];
			const operator = select('Operator', operators.map(op => [op, op.replaceAll('_', ' ')]), node.config.operator || 'equals');
			inspector.append(operator.wrap);
			const apply = () => {
				if (!merge.control.value) { node.config = {}; mark(); sync(); return; }
				const old = node.config;
				const unary = operator.control.value === 'is_empty' || operator.control.value === 'is_not_empty';
				node.config = {left: old.left || {source: 'literal', value: ''}, operator: operator.control.value, right: unary ? null : old.right || {source: 'literal', value: ''}, merge_id: merge.control.value};
				mark(); sync();
			};
			merge.control.addEventListener('change', () => { apply(); inspect(); });
			operator.control.addEventListener('change', () => { apply(); inspect(); });
			if (occupied(node.config)) {
				addBinding(inspector, 'Left value', node, node.config.left, {type: 'string'}, value => { if (value) node.config.left = value; });
				if (!['is_empty', 'is_not_empty'].includes(node.config.operator)) {
					addBinding(inspector, 'Compare with', node, node.config.right, {type: 'string'}, value => { if (value) node.config.right = value; });
				}
			}
		}
		// Existing connections may always be removed. Adding is constrained by the
		// same typed ports and occupancy as GraphValidator.
		const entries = draft.graph.edges.filter(edge => edge.from === node.id || edge.to === node.id);
		const heading = make('h4', '', 'Connections'); inspector.append(heading);
		for (const edge of entries) {
			const row = make('div', 'cb-advanced-connection');
			row.append(make('span', '', edge.from.slice(0, 12) + ':' + edge.from_port + ' → ' + edge.to.slice(0, 12) + ':' + edge.to_port));
			row.append(button('Remove', () => { draft.graph.edges = draft.graph.edges.filter(e => e.id !== edge.id); mark(); rebuild(); }, true));
			inspector.append(row);
		}
		if (outgoing(node.type).length) {
			const out = select('From port', outgoing(node.type).map(p => [p, p])); inspector.append(out.wrap);
			const target = select('To node', [['', 'Choose node'], ...draft.graph.nodes.filter(n => n.id !== node.id && incoming(n.type).length).map(n => [n.id, labels[n.type] + ' · ' + n.id.slice(0, 12)])]);
			inspector.append(target.wrap);
			const to = select('To port', [['', 'Choose port']]); inspector.append(to.wrap);
			const refreshPorts = () => {
				to.control.replaceChildren();
				const other = find(target.control.value);
				for (const p of other ? incoming(other.type) : []) to.control.add(new Option(p, p));
			};
			target.control.addEventListener('change', refreshPorts);
			inspector.append(button('Connect', () => {
				if (!connect(node.id, out.control.value, target.control.value, to.control.value)) {
					window.alert('Connection unavailable. Each input and output port can be used only once.');
				}
			}));
		}
		if (node.type !== 'trigger' && node.type !== 'end') inspector.append(button('Remove node', () => removeNode(node.id), true));
	};
	root.querySelectorAll('[data-cb-advanced-add]').forEach(el => {
		el.addEventListener('click', () => { try { addNode(el.dataset.cbAdvancedAdd); } catch (e) { window.alert(e.message); } });
	});
	form.addEventListener('submit', event => {
		if (saving) { event.preventDefault(); return; }
		if (!nameField?.value.trim()) return;
		if (draft.graph.nodes.length > 512 || draft.graph.edges.length > 1024) {
			event.preventDefault(); window.alert('This draft exceeds the supported graph size.'); return;
		}
		sync();
		if (new Blob([documentField.value]).size > 262144 || !documentField.checkValidity()) {
			event.preventDefault(); window.alert('The workflow definition cannot be saved safely.'); return;
		}
		// A validated, intentional native WordPress POST is a navigation, not
		// an unapproved Designer close. Keep the shared Exit Guard armed for
		// every other navigation until the form passes all local guards.
		saving = true;
		root.dataset.cbAdvancedDirty = 'false';
	});
	window.addEventListener('beforeunload', event => { if (changed && !saving) { event.preventDefault(); event.returnValue = ''; } });
	nameField?.addEventListener('input', mark);
	// Reject nonunique IDs and unsupported shapes rather than rebuilding them.
	if (draft.graph.nodes.some(n => !validId(n.id)) || new Set(draft.graph.nodes.map(n => n.id)).size !== draft.graph.nodes.length) return;
	rebuild();
})();