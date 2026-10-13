/**
 * flexicorp TEITOK UI: word tree (plain SVG, no library), after Wattenberg & Viégas' Word Tree.
 * window.ttFlexicorpCharts.wordtree(container, rows, opts) draws the continuations of a word as a
 * tree: rows [{path: [w1, w2, …], count}] are sequences after the root (frequencies by the next
 * tokens' fields); a word's size grows with how often that branch occurs. Returns {leaves}.
 * opts: root (label of the root), maxChildren ([8, 5, 3] per level), width, format(value) → text.
 */
(function () {
	const NS = 'http://www.w3.org/2000/svg';
	const COLORS = ['#1f3a68', '#2f5d9a', '#4a7fc0', '#6f9fd8', '#93b8e3'];
	const el = (name, attrs, parent) => {
		const e = document.createElementNS(NS, name);
		for (const k of Object.keys(attrs || {})) e.setAttribute(k, attrs[k]);
		if (parent) parent.appendChild(e);
		return e;
	};

	/** Prefix tree of the sequences; each node counts the rows passing through it. */
	function build(rows) {
		const root = { word: '', count: 0, children: new Map() };
		for (const r of rows || []) {
			const c = Number(r && r.count) || 0;
			if (c <= 0 || !Array.isArray(r.path)) continue;
			root.count += c;
			let node = root;
			for (const w of r.path) {
				if (w == null || w === '') break;   // the sequence ended (sentence end)
				let ch = node.children.get(w);
				if (!ch) { ch = { word: String(w), count: 0, children: new Map() }; node.children.set(w, ch); }
				ch.count += c;
				node = ch;
			}
		}
		return root;
	}

	/** Keep the most frequent children per level; returns the number of leaves. */
	function prune(node, depth, limits) {
		const lim = limits[Math.min(depth, limits.length - 1)];
		node.kids = [...node.children.values()].sort((a, b) => b.count - a.count).slice(0, lim);
		if (!node.kids.length) return (node.leaves = 1);
		node.leaves = node.kids.reduce((s, k) => s + prune(k, depth + 1, limits), 0);
		return node.leaves;
	}

	function depthOf(node) {
		return node.kids && node.kids.length ? 1 + Math.max(...node.kids.map(depthOf)) : 0;
	}

	function wordtree(container, rows, opts = {}) {
		while (container.firstChild) container.removeChild(container.firstChild);
		const fmt = typeof opts.format === 'function' ? opts.format : (v) => Number(v).toLocaleString();
		const root = build(rows);
		if (!root.count || !root.children.size) return { leaves: 0 };
		root.word = opts.root || '…';
		prune(root, 0, opts.maxChildren || [8, 5, 3, 2]);
		const levels = depthOf(root);
		const width = Math.max(520, opts.width || container.clientWidth || 900);
		const rowH = 22;
		const height = Math.max(120, root.leaves * rowH + 20);
		const size = (c) => Math.round(11 + 15 * Math.sqrt(c / root.count));
		const textW = (w, fs) => Math.min(w.length * fs * 0.58, 200);
		// each column as wide as its longest word, plus room for the links
		const colX = [4];
		const widest = [];
		const measure = (node, depth) => {
			const fs = depth === 0 ? 26 : size(node.count);
			widest[depth] = Math.max(widest[depth] || 0, textW(node.word, fs));
			for (const k of node.kids) measure(k, depth + 1);
		};
		measure(root, 0);
		const gap = Math.min(90, Math.max(40, (width - widest.reduce((a, b) => a + b, 0)) / Math.max(1, levels)));
		for (let d = 1; d <= levels; d += 1) colX[d] = colX[d - 1] + widest[d - 1] + gap;
		const drawnW = Math.max(width, Math.ceil(colX[levels] + widest[levels] + 8));
		const svg = el('svg', { xmlns: NS, width: drawnW, height, viewBox: `0 0 ${drawnW} ${height}`, class: 'flexicorp-wordtree',
			role: 'img', 'font-family': 'system-ui, sans-serif' }, container);
		const gLinks = el('g', { fill: 'none', stroke: '#9aa7b6', 'stroke-width': 1.2 }, svg);
		let leaf = 0;
		// leaves get the rows top to bottom; a node sits in the middle of its leaves
		const layout = (node, depth) => {
			node.x = colX[depth];
			node.fs = depth === 0 ? 26 : size(node.count);
			if (!node.kids.length) { node.y = 10 + leaf * rowH + rowH / 2; leaf += 1; return; }
			for (const k of node.kids) layout(k, depth + 1);
			node.y = (node.kids[0].y + node.kids[node.kids.length - 1].y) / 2;
		};
		layout(root, 0);
		const draw = (node, depth) => {
			const t = el('text', { x: node.x, y: node.y + node.fs * 0.35, 'font-size': node.fs,
				fill: COLORS[Math.min(depth, COLORS.length - 1)], 'font-weight': depth === 0 ? '700' : '400' }, svg);
			t.textContent = node.word;
			el('title', {}, t).textContent = depth === 0 ? `${node.word}: ${fmt(node.count)}` : `${node.word}: ${fmt(node.count)} (${(100 * node.count / root.count).toFixed(1)}%)`;
			const xa = node.x + textW(node.word, node.fs) + 4;
			for (const k of node.kids) {
				const xb = k.x - 3, xm = (xa + xb) / 2;
				el('path', { d: `M${xa},${node.y} C${xm},${node.y} ${xm},${k.y} ${xb},${k.y}` }, gLinks);
				draw(k, depth + 1);
			}
		};
		draw(root, 0);
		return { leaves: root.leaves };
	}

	window.ttFlexicorpCharts = window.ttFlexicorpCharts || {};
	window.ttFlexicorpCharts.wordtree = wordtree;
})();
