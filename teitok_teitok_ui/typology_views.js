/**
 * typology.php's views, as SVG strings (no page state: typology.js passes what to draw): the
 * dendrogram, the similarity space and the similarity matrix. Language labels carry
 * data-lang="<code>" so that the page can make them clickable. window.ttTypologyViews.
 */
(function () {
	'use strict';

	const esc = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	// group colours (colour-blind safe, Okabe–Ito), then grey
	const COLOURS = ['#0072B2', '#E69F00', '#009E73', '#D55E00', '#CC79A7', '#56B4E9', '#F0E442', '#000000'];
	/**
	 * A standalone SVG: the caption lines on top (what it shows, how it was measured), the drawing below,
	 * <title> / <desc> for the same text (for screen readers and for anyone who gets the file), a font
	 * family so that the file renders the same elsewhere. Text stays text: labels are easy to correct.
	 */
	function frame(width, height, body, opts) {
		// caption lines wrapped to the drawing's width (about 6 px per character at 11 px, 7.5 at 13 px)
		const wrap = (line, size) => {
			const max = Math.max(30, Math.floor((width - 16) / (size > 11 ? 7.5 : 6)));
			const out = [];
			let cur = '';
			for (const w of String(line).split(' ')) {
				if (cur && (cur + ' ' + w).length > max) { out.push(cur); cur = w; } else cur = cur ? cur + ' ' + w : w;
			}
			if (cur) out.push(cur);
			return out;
		};
		const given = (opts && opts.caption) || [];
		const lines = [];
		const first = [];
		given.forEach((l, i) => wrap(l, i ? 11 : 13).forEach((w, k) => { lines.push(w); first.push(i === 0); }));
		const capH = lines.length ? lines.length * 16 + 10 : 0;
		const cap = lines.map((l, i) => `<text x="8" y="${16 + i * 16}" font-size="${first[i] ? 13 : 11}" font-weight="${first[i] ? 700 : 400}" fill="${first[i] ? '#121a24' : '#3a4654'}" class="tt-ty-caption">${esc(l)}</text>`).join('');
		const title = given[0] ? `<title>${esc(given[0])}</title>` : '';
		const desc = given.length > 1 ? `<desc>${esc(given.slice(1).join('\n'))}</desc>` : '';
		return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height + capH}" width="${opts && opts.fixedWidth ? width : '100%'}" font-family="Helvetica, Arial, sans-serif" class="tt-ty-svg">${title}${desc}<rect width="${width}" height="${height + capH}" fill="#fff"/>${cap}<g transform="translate(0,${capH})">${body}</g></svg>`;
	}

	const colour = (g) => (g === undefined || g === null || g < 0 ? '#888888' : COLOURS[g % COLOURS.length]);

	/**
	 * A horizontal dendrogram: leaves top to bottom in tree order, joins at their height.
	 * opts: { labels: index → text, codes: index → code, groups: index → group, width }
	 */
	function dendrogram(tree, opts) {
		if (!tree) return '';
		const rowH = 16, labelW = 170, pad = 10;
		const order = window.ttTypologyMath.leafOrder(tree);
		const width = Math.max(420, opts.width || 760);
		const plotW = width - labelW - 2 * pad;
		const height = order.length * rowH + 2 * pad + 18;
		const maxH = tree.height || 1;
		const x = (h) => pad + plotW * (1 - h / maxH);
		const yOf = {};
		order.forEach((leaf, i) => { yOf[leaf] = pad + i * rowH + rowH / 2; });
		const parts = [];
		const draw = (t) => {
			if (t.leaf !== undefined) return { x: x(0), y: yOf[t.leaf] };
			const a = draw(t.left), b = draw(t.right);
			const xh = x(t.height);
			parts.push(`<path d="M${a.x},${a.y}H${xh}V${b.y}H${b.x}" fill="none" stroke="#667" stroke-width="1"/>`);
			return { x: xh, y: (a.y + b.y) / 2 };
		};
		draw(tree);
		order.forEach((leaf) => {
			parts.push(`<text x="${x(0) + 6}" y="${yOf[leaf] + 4}" fill="${colour(opts.groups && opts.groups[leaf])}" data-lang="${esc(opts.codes[leaf])}" class="tt-ty-lang">${esc(opts.labels[leaf])}</text>`);
		});
		// the distance scale, with its title
		const axisY = height - 12;
		parts.push(`<line x1="${x(maxH)}" y1="${axisY}" x2="${x(0)}" y2="${axisY}" stroke="#999"/>`);
		[0, 0.5, 1].forEach((f) => parts.push(`<text x="${x(maxH * f)}" y="${axisY + 11}" font-size="10" fill="#666" text-anchor="middle">${(maxH * f * 2).toFixed(2)}</text>`));
		parts.push(`<text x="${(x(maxH) + x(0)) / 2}" y="${axisY + 24}" font-size="11" fill="#3a4654" text-anchor="middle" class="tt-ty-axis">${esc(opts.axis || 'distance between the groups joined')}</text>`);
		return frame(width, height + 16, parts.join(''), opts);
	}

	/** Languages as points in 2D (the MDS coordinates), labelled and coloured by group. */
	function space(coords, opts) {
		const W = opts.width || 760, H = Math.round((opts.width || 760) * 0.62), pad = 40;
		if (!coords.length) return '';
		const xs = coords.map((c) => c[0]), ys = coords.map((c) => c[1]);
		const [x0, x1] = [Math.min(...xs), Math.max(...xs)], [y0, y1] = [Math.min(...ys), Math.max(...ys)];
		const sx = (v) => pad + (W - 2 * pad) * (x1 > x0 ? (v - x0) / (x1 - x0) : 0.5);
		const sy = (v) => pad + (H - 2 * pad) * (y1 > y0 ? (v - y0) / (y1 - y0) : 0.5);
		const parts = [`<rect x="0" y="0" width="${W}" height="${H}" fill="none" stroke="#e3e6ea"/>`];
		coords.forEach((c, i) => {
			const col = colour(opts.groups && opts.groups[i]);
			const hl = opts.highlight === opts.codes[i];
			parts.push(`<circle cx="${sx(c[0]).toFixed(1)}" cy="${sy(c[1]).toFixed(1)}" r="${hl ? 6 : 4}" fill="${col}" fill-opacity="0.8"/>`);
			parts.push(`<text x="${(sx(c[0]) + 6).toFixed(1)}" y="${(sy(c[1]) + 4).toFixed(1)}" font-size="${hl ? 13 : 11}" font-weight="${hl ? 700 : 400}" fill="${col}" data-lang="${esc(opts.codes[i])}" class="tt-ty-lang">${esc(opts.labels[i])}</text>`);
		});
		parts.push(`<text x="${W / 2}" y="${H - 8}" font-size="11" fill="#3a4654" text-anchor="middle" class="tt-ty-axis">${esc(opts.axis || 'dimension 1')}</text>`);
		parts.push(`<text transform="translate(14,${H / 2}) rotate(-90)" font-size="11" fill="#3a4654" text-anchor="middle" class="tt-ty-axis">${esc(opts.axis2 || 'dimension 2')}</text>`);
		return frame(W, H, parts.join(''), opts);
	}

	/** The similarity matrix in tree order: darker = more similar; hover shows the pair and the distance. */
	function matrix(dist, order, opts) {
		const n = order.length;
		if (!n) return '';
		const labelW = 130, cell = Math.max(4, Math.min(16, Math.floor(((opts.width || 760) - labelW) / n)));
		const size = labelW + n * cell;
		let max = 0;
		for (const row of dist.D) for (const d of row) if (d !== null && d > max) max = d;
		const parts = [];
		order.forEach((i, r) => {
			parts.push(`<text x="${labelW - 4}" y="${labelW + r * cell + cell - 2}" font-size="${Math.min(11, cell)}" text-anchor="end" data-lang="${esc(opts.codes[i])}" class="tt-ty-lang">${esc(opts.labels[i])}</text>`);
			order.forEach((j, c) => {
				const d = i === j ? 0 : dist.D[i][j];
				const fill = d === null ? '#f2f2f2' : `rgba(0, 114, 178, ${(1 - d / (max || 1)).toFixed(3)})`;
				const title = d === null ? `${opts.labels[i]} – ${opts.labels[j]}: too few shared features` : `${opts.labels[i]} – ${opts.labels[j]}: ${d.toFixed(3)} (${dist.shared[i][j] || '—'} features)`;
				// hover texts only for smaller matrices (above ~60 languages they make the drawing megabytes)
				parts.push(`<rect x="${labelW + c * cell}" y="${labelW + r * cell}" width="${cell}" height="${cell}" fill="${fill}">${n <= 60 ? `<title>${esc(title)}</title>` : ''}</rect>`);
			});
			if (cell >= 8) parts.push(`<text transform="translate(${labelW + r * cell + cell - 2},${labelW - 4}) rotate(-90)" font-size="${Math.min(11, cell)}">${esc(opts.labels[i])}</text>`);
		});
		// the colour scale, with its title
		const lg = 160, ly = size + 14;
		for (let k = 0; k <= 10; k += 1) parts.push(`<rect x="${labelW + k * (lg / 11)}" y="${ly}" width="${lg / 11 + 0.5}" height="10" fill="rgba(0, 114, 178, ${(1 - k / 10).toFixed(2)})"/>`);
		parts.push(`<text x="${labelW}" y="${ly + 22}" font-size="10" fill="#666">0</text><text x="${labelW + lg}" y="${ly + 22}" font-size="10" fill="#666" text-anchor="end">${max.toFixed(2)}</text>`);
		parts.push(`<text x="${labelW + lg + 10}" y="${ly + 9}" font-size="11" fill="#3a4654" class="tt-ty-axis">${esc(opts.axis || 'distance (darker = more similar)')}</text>`);
		return frame(size, size + 40, parts.join(''), Object.assign({}, opts, { fixedWidth: false }));
	}

	window.ttTypologyViews = { dendrogram, space, matrix, colour, frame };
})();
