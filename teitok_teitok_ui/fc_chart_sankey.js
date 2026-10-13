/**
 * flexicorp TEITOK UI: two-column Sankey diagram (plain SVG, no library).
 * window.ttFlexicorpCharts.sankey(container, links, opts) draws links [{source, target, value}]
 * as flows from the left values to the right ones (band width ∝ value); used for frequencies by
 * two fields, e.g. an English word and its aligned Czech lemmas. Returns {shown, total} links.
 * opts: leftTitle, rightTitle, maxLinks (40), width (container width), format(value) → text.
 */
(function () {
	const NS = 'http://www.w3.org/2000/svg';
	const PALETTE = ['#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f', '#edc948', '#b07aa1', '#ff9da7', '#9c755f', '#bab0ac'];
	const el = (name, attrs, parent) => {
		const e = document.createElementNS(NS, name);
		for (const k of Object.keys(attrs || {})) e.setAttribute(k, attrs[k]);
		if (parent) parent.appendChild(e);
		return e;
	};
	const text = (parent, x, y, str, attrs) => {
		const t = el('text', Object.assign({ x, y }, attrs || {}), parent);
		t.textContent = str;
		return t;
	};

	/** Nodes of one side: value totals, largest first. */
	function side(links, key) {
		const tot = new Map();
		for (const l of links) tot.set(l[key], (tot.get(l[key]) || 0) + l.value);
		return [...tot.entries()].sort((a, b) => b[1] - a[1]).map(([name, value]) => ({ name, value }));
	}

	function sankey(container, allLinks, opts = {}) {
		while (container.firstChild) container.removeChild(container.firstChild);
		const maxLinks = opts.maxLinks || 40;
		const fmt = typeof opts.format === 'function' ? opts.format : (v) => Number(v).toLocaleString();
		const links = (allLinks || []).filter((l) => l && l.value > 0)
			.sort((a, b) => b.value - a.value).slice(0, maxLinks);
		if (!links.length) return { shown: 0, total: (allLinks || []).length };
		const left = side(links, 'source');
		const right = side(links, 'target');
		const width = Math.max(480, opts.width || container.clientWidth || 900);
		const pad = 6;
		const rowMin = 16;   // every node gets at least one label line of room (its band stays to scale)
		const total = links.reduce((s, l) => s + l.value, 0);
		const scale = Math.max(260, 14 * Math.max(left.length, right.length)) / total;
		const labelW = Math.min(220, width * 0.26);
		const nodeW = 12;
		const x0 = labelW, x1 = width - labelW - nodeW;
		const top = 26;
		const slot = (nd) => Math.max(nd.value * scale, rowMin);
		const used = (nodes) => nodes.reduce((s, nd) => s + slot(nd), 0) + pad * (nodes.length - 1);
		const height = Math.max(used(left), used(right));
		const svg = el('svg', { xmlns: NS, width, height: height + top + 8, viewBox: `0 0 ${width} ${height + top + 8}`,
			class: 'flexicorp-sankey', role: 'img', 'font-family': 'system-ui, sans-serif', 'font-size': '12' }, container);
		if (opts.leftTitle) text(svg, x0 + nodeW, 14, opts.leftTitle, { 'text-anchor': 'end', 'font-weight': '600' });
		if (opts.rightTitle) text(svg, x1, 14, opts.rightTitle, { 'font-weight': '600' });
		// node positions: stacked from the top in their slots, each side centred in the height
		const place = (nodes) => {
			let y = top + (height - used(nodes)) / 2;
			for (const nd of nodes) {
				nd.h = Math.max(1, nd.value * scale);
				nd.y = y + (slot(nd) - nd.h) / 2;
				nd.out = 0;
				y += slot(nd) + pad;
			}
			return new Map(nodes.map((nd) => [nd.name, nd]));
		};
		const L = place(left), R = place(right);
		left.forEach((nd, i) => { nd.color = PALETTE[i % PALETTE.length]; });
		const gLinks = el('g', { fill: 'none' }, svg);
		const bands = [];
		// links in the order of their nodes, so that bands do not cross within a node
		const order = [...links].sort((a, b) => (L.get(a.source).y - L.get(b.source).y) || (R.get(a.target).y - R.get(b.target).y));
		for (const l of order) {
			const s = L.get(l.source), t = R.get(l.target);
			const w = Math.max(1, l.value * scale);
			const ys = s.y + s.out + w / 2; s.out += w;
			const yt = t.y + t.out + w / 2; t.out += w;
			const xa = x0 + nodeW, xb = x1, xm = (xa + xb) / 2;
			const p = el('path', { d: `M${xa},${ys} C${xm},${ys} ${xm},${yt} ${xb},${yt}`, stroke: s.color,
				'stroke-width': w, 'stroke-opacity': 0.45 }, gLinks);
			el('title', {}, p).textContent = `${l.source} → ${l.target}: ${fmt(l.value)}`;
			p.dataset.source = l.source;
			p.dataset.target = l.target;
			bands.push(p);
		}
		const highlight = (key, name) => {
			for (const b of bands) b.setAttribute('stroke-opacity', name == null || b.dataset[key] === name ? 0.7 : 0.08);
		};
		const drawNodes = (nodes, x, key, anchorLeft) => {
			for (const nd of nodes) {
				const g = el('g', { style: 'cursor:default' }, svg);
				el('rect', { x, y: nd.y, width: nodeW, height: nd.h, fill: nd.color || '#888', rx: 2 }, g);
				const tx = anchorLeft ? x - 6 : x + nodeW + 6;
				text(g, tx, nd.y + nd.h / 2 + 4, nd.name === '' ? '(empty)' : nd.name, { 'text-anchor': anchorLeft ? 'end' : 'start' });
				el('title', {}, g).textContent = `${nd.name}: ${fmt(nd.value)}`;
				g.addEventListener('mouseenter', () => highlight(key, nd.name));
				g.addEventListener('mouseleave', () => highlight(key, null));
			}
		};
		drawNodes(left, x0, 'source', true);
		drawNodes(right, x1, 'target', false);
		return { shown: links.length, total: (allLinks || []).filter((l) => l && l.value > 0).length };
	}

	window.ttFlexicorpCharts = window.ttFlexicorpCharts || {};
	window.ttFlexicorpCharts.sankey = sankey;
})();
