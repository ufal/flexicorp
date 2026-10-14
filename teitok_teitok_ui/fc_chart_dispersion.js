/**
 * flexicorp TEITOK UI: dispersion plot (plain SVG, no library), as AntConc's concordance plot.
 * window.ttFlexicorpCharts.dispersion(container, texts, opts) draws one bar per text with a mark at
 * each hit's relative position in it (start of the text left, end right), and above them the hits of
 * all texts together in 20 bins. texts: [{label, start, length, hits: [corpus positions]}] in the
 * order to show. Returns {texts, hits} drawn.
 * opts: width, onSelect(text) for a click on a text's label.
 */
(function () {
	const NS = 'http://www.w3.org/2000/svg';
	const BINS = 20;
	const el = (name, attrs, parent) => {
		const e = document.createElementNS(NS, name);
		for (const k of Object.keys(attrs || {})) e.setAttribute(k, attrs[k]);
		if (parent) parent.appendChild(e);
		return e;
	};
	const pct = (x) => `${Math.round(x * 100)}%`;

	/** Relative positions (0…1) of a text's hits inside it; hits outside the text are dropped. */
	function relative(t) {
		const len = Math.max(1, Number(t.length) || 0);
		return (t.hits || []).map((p) => (Number(p) - Number(t.start)) / len).filter((x) => x >= 0 && x <= 1);
	}

	/** Hits of all texts in BINS bins by relative position. */
	function bins(texts) {
		const b = new Array(BINS).fill(0);
		for (const t of texts) for (const x of relative(t)) b[Math.min(BINS - 1, Math.floor(x * BINS))] += 1;
		return b;
	}

	function dispersion(container, texts, opts = {}) {
		while (container.firstChild) container.removeChild(container.firstChild);
		const list = (texts || []).filter((t) => t && Number(t.length) > 0);
		if (!list.length) return { texts: 0, hits: 0 };
		const width = Math.max(480, opts.width || container.clientWidth || 900);
		const labelW = Math.min(240, Math.max(120, width * 0.22));
		const countW = 48;
		const x0 = labelW + 8, x1 = width - countW - 8;
		const plotW = x1 - x0;
		const rowH = 16, histH = 46, top = 22;
		const height = top + histH + 14 + list.length * rowH + 8;
		const svg = el('svg', { viewBox: `0 0 ${width} ${height}`, width, height, class: 'flexicorp-dispersion', role: 'img',
			'aria-label': 'Where the hits fall within each text' }, container);
		// axis: start … end of a text
		for (const f of [0, 0.25, 0.5, 0.75, 1]) {
			const x = x0 + f * plotW;
			el('line', { x1: x, x2: x, y1: top - 4, y2: height - 6, stroke: '#eef0f3' }, svg);
			const t = el('text', { x, y: top - 8, 'text-anchor': f === 0 ? 'start' : (f === 1 ? 'end' : 'middle'), class: 'flexicorp-dispersion__axis' }, svg);
			t.textContent = f === 0 ? 'start of the text' : (f === 1 ? 'end' : pct(f));
		}
		// all texts together
		const b = bins(list);
		const maxB = Math.max(1, ...b);
		const bw = plotW / BINS;
		const lab = el('text', { x: labelW, y: top + histH / 2, 'text-anchor': 'end', 'dominant-baseline': 'central', class: 'flexicorp-dispersion__label' }, svg);
		lab.textContent = 'all texts';
		b.forEach((n, i) => {
			const h = (n / maxB) * (histH - 4);
			const r = el('rect', { x: (x0 + i * bw + 1).toFixed(1), y: (top + histH - h).toFixed(1), width: Math.max(1, bw - 2).toFixed(1), height: h.toFixed(1), fill: '#4e79a7', 'fill-opacity': 0.75 }, svg);
			el('title', {}, r).textContent = `${pct(i / BINS)}–${pct((i + 1) / BINS)} of the text: ${n.toLocaleString()} hits`;
		});
		// one bar per text
		let y = top + histH + 14, hits = 0;
		for (const t of list) {
			const rel = relative(t);
			hits += rel.length;
			const label = el('text', { x: labelW, y: y + rowH / 2, 'text-anchor': 'end', 'dominant-baseline': 'central', class: 'flexicorp-dispersion__label' }, svg);
			const name = String(t.label || '');
			label.textContent = name.length > 34 ? `…${name.slice(-33)}` : name;
			el('title', {}, label).textContent = `${name}: ${rel.length} hits in ${Number(t.length).toLocaleString()} tokens`;
			if (typeof opts.onSelect === 'function') {
				label.style.cursor = 'pointer';
				label.addEventListener('click', () => opts.onSelect(t));
			}
			el('rect', { x: x0, y: y + 3, width: plotW, height: rowH - 6, fill: '#f3f4f6', stroke: '#d7dce1' }, svg);
			for (const x of rel) {
				const px = x0 + x * plotW;
				el('line', { x1: px.toFixed(1), x2: px.toFixed(1), y1: y + 2, y2: y + rowH - 2, stroke: '#1f4e8c', 'stroke-width': 1.2 }, svg);
			}
			const c = el('text', { x: x1 + 6, y: y + rowH / 2, 'dominant-baseline': 'central', class: 'flexicorp-dispersion__count' }, svg);
			c.textContent = rel.length.toLocaleString();
			y += rowH;
		}
		return { texts: list.length, hits };
	}

	window.ttFlexicorpCharts = window.ttFlexicorpCharts || {};
	window.ttFlexicorpCharts.dispersion = dispersion;
	window.ttFlexicorpCharts._dispersionBins = bins;   // tests
})();
