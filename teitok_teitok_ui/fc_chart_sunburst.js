/**
 * flexicorp TEITOK UI: word-sketch sunburst (plain SVG, no library).
 * window.ttFlexicorpCharts.sunburst(container, rows, opts) draws dependency collocations as rings
 * around the search word: the inner ring the relations (deprel), the outer ring the collocates
 * within each relation, angles ∝ frequency. rows: [{word, breakdown: {relation: count}, freq}] as
 * `dcoll … by lemma, deprel` returns them; without a breakdown there is one ring of collocates.
 * Returns {relations, collocates} drawn.
 * opts: center (label in the middle), maxPerRelation (12), minAngle (degrees below which collocates
 *   are merged into "other", 2.5), skipRelations (['punct']: punctuation would take half the circle),
 *   width, onSelect(word, relation) for a click on a collocate.
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

	/** relation → [{word, value}] (largest first) from the rows; '' relation when there is no breakdown. */
	function groups(rows, skip) {
		const skipSet = new Set(skip || []);
		const by = new Map();
		for (const r of rows || []) {
			const w = r && r.word != null ? String(r.word) : '';
			if (!w) continue;
			const b = r.breakdown && typeof r.breakdown === 'object' ? r.breakdown : null;
			const entries = b ? Object.entries(b) : [['', Number(r.obs != null ? r.obs : r.freq) || 0]];
			for (const [rel, n] of entries) {
				const v = Number(n) || 0;
				if (v <= 0 || skipSet.has(rel)) continue;
				if (!by.has(rel)) by.set(rel, []);
				by.get(rel).push({ word: w, value: v });
			}
		}
		const out = [...by.entries()].map(([rel, items]) => ({
			relation: rel,
			items: items.sort((a, b) => b.value - a.value || (a.word < b.word ? -1 : 1)),
			value: items.reduce((s, x) => s + x.value, 0),
		}));
		return out.sort((a, b) => b.value - a.value);
	}

	/** Each relation's collocates: the first maxPer, and those wide enough; the rest as one "other". */
	function trim(group, total, maxPer, minAngle) {
		const keep = [];
		let rest = 0, restN = 0;
		for (const it of group.items) {
			const deg = (it.value / total) * 360;
			if (keep.length < maxPer && deg >= minAngle) keep.push(it);
			else { rest += it.value; restN += 1; }
		}
		if (rest > 0) keep.push({ word: `${restN} other`, value: rest, other: true });
		return keep;
	}

	function arcPath(cx, cy, r0, r1, a0, a1) {
		const big = a1 - a0 > Math.PI ? 1 : 0;
		const p = (r, a) => `${(cx + r * Math.sin(a)).toFixed(2)},${(cy - r * Math.cos(a)).toFixed(2)}`;
		if (a1 - a0 >= 2 * Math.PI - 1e-6) {   // a full ring: two halves
			const m = a0 + Math.PI;
			return `M${p(r1, a0)}A${r1},${r1} 0 1 1 ${p(r1, m)}A${r1},${r1} 0 1 1 ${p(r1, a0)}`
				+ `M${p(r0, a0)}A${r0},${r0} 0 1 0 ${p(r0, m)}A${r0},${r0} 0 1 0 ${p(r0, a0)}Z`;
		}
		return `M${p(r1, a0)}A${r1},${r1} 0 ${big} 1 ${p(r1, a1)}L${p(r0, a1)}A${r0},${r0} 0 ${big} 0 ${p(r0, a0)}Z`;
	}

	/** A label along the radius in the middle of a segment, readable (never upside down). */
	function radialLabel(g, cx, cy, r0, r1, a0, a1, str, cls) {
		const a = (a0 + a1) / 2;
		const r = (r0 + r1) / 2;
		const deg = (a * 180) / Math.PI;
		const flip = deg > 180;
		const x = cx + r * Math.sin(a), y = cy - r * Math.cos(a);
		const t = el('text', { x: x.toFixed(1), y: y.toFixed(1), class: cls, 'text-anchor': 'middle', 'dominant-baseline': 'central',
			transform: `rotate(${(flip ? deg + 90 : deg - 90).toFixed(1)} ${x.toFixed(1)} ${y.toFixed(1)})` }, g);
		const room = Math.max(3, Math.floor((r1 - r0) / 6.5));
		t.textContent = str.length > room ? `${str.slice(0, room - 1)}…` : str;
		return t;
	}

	function sunburst(container, rows, opts = {}) {
		while (container.firstChild) container.removeChild(container.firstChild);
		const all = groups(rows, opts.skipRelations || ['punct']);
		if (!all.length) return { relations: 0, collocates: 0 };
		const total = all.reduce((s, g) => s + g.value, 0);
		const maxPer = opts.maxPerRelation || 12;
		const minAngle = opts.minAngle != null ? opts.minAngle : 2.5;
		const oneRing = all.length === 1 && all[0].relation === '';
		const width = Math.min(760, Math.max(360, opts.width || container.clientWidth || 640));
		const cx = width / 2, cy = width / 2;
		const R = width / 2 - 8;
		const r0 = R * 0.2, r1 = oneRing ? r0 : R * 0.45, r2 = R;
		const svg = el('svg', { viewBox: `0 0 ${width} ${width}`, width, height: width, class: 'flexicorp-sunburst',
			role: 'img', 'aria-label': `Dependency collocations of ${opts.center || 'the search'}` }, container);
		const g = el('g', {}, svg);
		let a = 0, collocates = 0;
		all.forEach((grp, gi) => {
			const color = PALETTE[gi % PALETTE.length];
			const span = (grp.value / total) * 2 * Math.PI;
			if (!oneRing) {
				const seg = el('path', { d: arcPath(cx, cy, r0, r1, a, a + span), fill: color, stroke: '#fff', 'stroke-width': 1.5 }, g);
				el('title', {}, seg).textContent = `${grp.relation}: ${grp.value.toLocaleString()} (${((grp.value / total) * 100).toFixed(1)}%)`;
				if (span > 0.12) radialLabel(g, cx, cy, r0, r1, a, a + span, grp.relation, 'flexicorp-sunburst__rel');
			}
			let b = a;
			for (const it of trim(grp, total, maxPer, minAngle)) {
				const s = (it.value / total) * 2 * Math.PI;
				const seg = el('path', { d: arcPath(cx, cy, r1, r2, b, b + s), fill: color, 'fill-opacity': it.other ? 0.25 : 0.55,
					stroke: '#fff', 'stroke-width': 1, class: it.other ? '' : 'flexicorp-sunburst__item' }, g);
				el('title', {}, seg).textContent = `${it.word}${grp.relation ? ` (${grp.relation})` : ''}: ${it.value.toLocaleString()}`;
				if (!it.other && typeof opts.onSelect === 'function') {
					seg.style.cursor = 'pointer';
					seg.addEventListener('click', () => opts.onSelect(it.word, grp.relation));
				}
				if (s * ((r1 + r2) / 2) > 11) radialLabel(g, cx, cy, r1, r2, b, b + s, it.word, 'flexicorp-sunburst__word');
				if (!it.other) collocates += 1;
				b += s;
			}
			a += span;
		});
		el('circle', { cx, cy, r: r0 - 2, fill: '#fff', stroke: '#d7dce1' }, g);
		const c = el('text', { x: cx, y: cy, 'text-anchor': 'middle', 'dominant-baseline': 'central', class: 'flexicorp-sunburst__center' }, g);
		const label = String(opts.center || '');
		c.textContent = label.length > 14 ? `${label.slice(0, 13)}…` : label;
		return { relations: oneRing ? 0 : all.length, collocates };
	}

	window.ttFlexicorpCharts = window.ttFlexicorpCharts || {};
	window.ttFlexicorpCharts.sunburst = sunburst;
	window.ttFlexicorpCharts._sunburstGroups = groups;   // tests
})();
