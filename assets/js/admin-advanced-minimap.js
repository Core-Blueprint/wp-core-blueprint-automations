/**
 * ADV-03D — graph-aware, view-only minimap.
 * The public Base Canvas Navigation controller alone owns transforms.
 * This module neither mutates saved graph layout nor creates History.
 */
const SVG_NS = 'http://www.w3.org/2000/svg';
const finite = value => typeof value === 'number' && Number.isFinite(value);
const svgElement = (doc, kind, attrs) => {
	const el = doc.createElementNS(SVG_NS, kind);
	for (const [key, value] of Object.entries(attrs)) el.setAttribute(key, String(value));
	return el;
};

export const worldViewport = (view, width, height) => ({
	x: ((view.scrollLeft || 0) - view.x) / view.scale,
	y: ((view.scrollTop || 0) - view.y) / view.scale,
	width: width / view.scale, height: height / view.scale,
});

export const minimapBounds = (nodes, visible) => {
	const xs = [visible.x, visible.x + visible.width], ys = [visible.y, visible.y + visible.height];
	for (const node of nodes) {
		if (![node.x, node.y].every(finite)) continue;
		xs.push(node.x, node.x + 184); ys.push(node.y, node.y + 110);
	}
	const pad = 120;
	const x = Math.min(...xs) - pad, y = Math.min(...ys) - pad;
	const right = Math.max(...xs) + pad, bottom = Math.max(...ys) + pad;
	return {x,y,width:Math.max(1,right-x),height:Math.max(1,bottom-y)};
};

export const createAutomationsGraphMinimap = ({
	root, viewport, navigation, getGraphSnapshot,
} = {}) => {
	const container = root?.querySelector('[data-cb-advanced-minimap]');
	const svg = container?.querySelector('[data-cb-advanced-minimap-svg]');
	const body = container?.querySelector('[data-cb-advanced-minimap-body]');
	const toggle = container?.querySelector('[data-cb-advanced-minimap-toggle]');
	const sizeButton = container?.querySelector('[data-cb-advanced-minimap-size]');
	if (!container || !svg || !body || !toggle || !sizeButton
		|| typeof getGraphSnapshot !== 'function'
		|| typeof navigation?.panBy !== 'function') {
		throw new TypeError('ADV03D_MINIMAP_CONFIG_INVALID');
	}
	const doc = svg.ownerDocument;
	let destroyed = false, frame = null, drag = null, frozenBounds = null, lastBounds = null, queued = false;
	let collapsed = false, large = false;
	const preferenceKey = 'cb:automations:advanced:minimap:v1';
	try {
		const pref = JSON.parse(sessionStorage.getItem(preferenceKey) || '{}');
		collapsed = typeof pref.collapsed === 'boolean' ? pref.collapsed
			: !!doc.defaultView?.matchMedia?.('(max-width: 760px)')?.matches;
		large = pref.large === true;
	} catch { collapsed = false; large = false; }
	const remember = () => {
		try { sessionStorage.setItem(preferenceKey, JSON.stringify({collapsed,large})); } catch { /* optional */ }
	};
	const updateControls = () => {
		container.classList.toggle('is-collapsed', collapsed);
		container.classList.toggle('is-large', large);
		body.hidden = collapsed;
		toggle.setAttribute('aria-expanded', String(!collapsed));
		toggle.title = collapsed ? 'Expand minimap' : 'Collapse minimap';
		toggle.textContent = collapsed ? '+' : '−';
		sizeButton.disabled = collapsed;
		sizeButton.setAttribute('aria-pressed', String(large));
	};
	const renderNow = (view = navigation.getView()) => {
		queued = false;
		if (destroyed || collapsed || !root.isConnected || root.hidden
			|| !viewport.clientWidth || !viewport.clientHeight) return;
		if (!finite(view.scale) || view.scale <= 0) return;
		const {nodes,edges} = getGraphSnapshot();
		const visible = worldViewport(view, viewport.clientWidth, viewport.clientHeight);
		const bounds = frozenBounds || minimapBounds(nodes,visible);
		lastBounds = bounds;
		svg.setAttribute('viewBox', [bounds.x,bounds.y,bounds.width,bounds.height].join(' '));
		const byId = new Map(nodes.map(n=>[n.id,n]));
		const children = [];
		for (const edge of edges) {
			const a=byId.get(edge.from), b=byId.get(edge.to);
			if (!a || !b) continue;
			children.push(svgElement(doc,'path',{
				d:`M${a.x+184} ${a.y+55} L${b.x} ${b.y+55}`, class:'cb-minimap-edge',
			}));
		}
		for (const node of nodes) {
			children.push(svgElement(doc,'rect',{
				x:node.x,y:node.y,width:184,height:110,rx:12,class:'cb-minimap-node',
			}));
		}
		frame=svgElement(doc,'rect',{
			x:visible.x,y:visible.y,width:visible.width,height:visible.height,
			class:'cb-minimap-viewport','aria-label':'Visible viewport',
		});
		children.push(frame);
		svg.replaceChildren(...children);
	};
	const queue = () => {
		if (queued || destroyed) return;
		queued = true;
		(doc.defaultView?.requestAnimationFrame || (cb=>setTimeout(cb,16)))(() => renderNow());
	};
	const point = (event, bounds) => {
		const rect = svg.getBoundingClientRect();
		if (!rect.width || !rect.height || !bounds) return null;
		// The SVG uses preserveAspectRatio=xMidYMid meet (letterboxed).
		const scale = Math.min(rect.width / bounds.width, rect.height / bounds.height);
		const left = (rect.width - bounds.width * scale) / 2;
		const top = (rect.height - bounds.height * scale) / 2;
		return {
			x: bounds.x + (event.clientX - rect.left - left) / scale,
			y: bounds.y + (event.clientY - rect.top - top) / scale,
		};
	};
	const centerAt = world => {
		if (!world) return;
		const v = navigation.getView();
		const current = worldViewport(v,viewport.clientWidth,viewport.clientHeight);
		const dx = (current.x + current.width / 2 - world.x) * v.scale;
		const dy = (current.y + current.height / 2 - world.y) * v.scale;
		if (dx || dy) navigation.panBy(dx,dy);
	};
	const onDown = event => {
		if (event.button !== 0 || collapsed || !lastBounds) return;
		const current = point(event,lastBounds);
		if (!current) return;
		event.preventDefault();
		if (event.target?.classList?.contains('cb-minimap-viewport')) {
			// Freeze map projection during drag to avoid shaking as the
			// visible viewport changes. Unlock and refit after release.
			frozenBounds = lastBounds;
			drag = {id:event.pointerId,world:current};
			svg.setPointerCapture?.(event.pointerId);
		} else centerAt(current);
	};
	const onMove = event => {
		if (!drag || drag.id !== event.pointerId) return;
		const next = point(event,frozenBounds);
		if (!next) return;
		const v=navigation.getView();
		const dx=(next.x-drag.world.x)*v.scale,dy=(next.y-drag.world.y)*v.scale;
		drag.world=next;
		if (dx || dy) navigation.panBy(-dx,-dy);
	};
	const onEnd = event => {
		if (!drag || drag.id !== event.pointerId) return;
		if (svg.hasPointerCapture?.(event.pointerId)) svg.releasePointerCapture(event.pointerId);
		drag=null; frozenBounds=null; queue();
	};
	const collapse = () => {collapsed=!collapsed; updateControls(); remember(); queue();};
	const resize = () => {large=!large; updateControls(); remember(); queue();};
	const onKey = event => {
		if (event.target !== svg) return;
		if (['ArrowLeft','ArrowRight','ArrowUp','ArrowDown'].includes(event.key)) {
			const step=event.shiftKey?128:48;
			const delta={
				ArrowLeft:[step,0],ArrowRight:[-step,0],
				ArrowUp:[0,step],ArrowDown:[0,-step],
			}[event.key];
			event.preventDefault(); navigation.panBy(...delta);
		}
	};
	toggle.addEventListener('click',collapse);
	sizeButton.addEventListener('click',resize);
	svg.addEventListener('pointerdown',onDown);
	svg.addEventListener('pointermove',onMove);
	svg.addEventListener('pointerup',onEnd);
	svg.addEventListener('pointercancel',onEnd);
	svg.addEventListener('keydown',onKey);
	root.addEventListener('cb:automations-advanced:graphchange',queue);
	updateControls();queue();
	return Object.freeze({
		render: queue,
		destroy() {
			if (destroyed) return;
			destroyed=true; drag=null; frozenBounds=null;
			toggle.removeEventListener('click',collapse);
			sizeButton.removeEventListener('click',resize);
			svg.removeEventListener('pointerdown',onDown);
			svg.removeEventListener('pointermove',onMove);
			svg.removeEventListener('pointerup',onEnd);
			svg.removeEventListener('pointercancel',onEnd);
			svg.removeEventListener('keydown',onKey);
			root.removeEventListener('cb:automations-advanced:graphchange',queue);
		},
	});
};
