/**
 * typology.php's downloads (no page state): a view as SVG (text: labels and captions easy to correct)
 * or PNG, the dendrogram as a Newick tree (R ape, iTOL, SplitsTree), tables as CSV.
 * window.ttTypologyExport.
 */
(function () {
	'use strict';

	function save(name, blob) {
		const a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = name;
		document.body.appendChild(a);
		a.click();
		setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 0);
	}
	const text = (name, s, type) => save(name, new Blob([s], { type: `${type}; charset=utf-8` }));

	/** An SVG string as a PNG blob (scale × its viewBox size, white background). */
	function svgToPng(svg, scale) {
		return new Promise((resolve, reject) => {
			const vb = /viewBox="0 0 ([\d.]+) ([\d.]+)"/.exec(svg);
			const w = vb ? Number(vb[1]) : 800, h = vb ? Number(vb[2]) : 600;
			const sized = svg.replace(/<svg ([^>]*?)width="[^"]*"/, `<svg $1width="${w}" height="${h}"`);
			const img = new Image();
			img.onload = () => {
				const c = document.createElement('canvas');
				c.width = Math.round(w * scale);
				c.height = Math.round(h * scale);
				const g = c.getContext('2d');
				g.fillStyle = '#fff';
				g.fillRect(0, 0, c.width, c.height);
				g.drawImage(img, 0, 0, c.width, c.height);
				c.toBlob((b) => (b ? resolve(b) : reject(new Error('PNG failed'))), 'image/png');
			};
			img.onerror = () => reject(new Error('the drawing could not be converted'));
			img.src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(sized);
		});
	}

	/** A tree (typology_math.js upgma) in Newick: leaves named, branch lengths = height differences. */
	function newick(tree, names) {
		const q = (s) => (/[\s(),:;'[\]]/.test(s) ? `'${String(s).replace(/'/g, "''")}'` : s);
		const walk = (t, parentH) => {
			const len = Math.max(0, parentH - (t.height || 0)).toFixed(5);
			if (t.leaf !== undefined) return `${q(names[t.leaf])}:${len}`;
			return `(${walk(t.left, t.height)},${walk(t.right, t.height)}):${len}`;
		};
		if (!tree) return ';';
		if (tree.leaf !== undefined) return `${q(names[tree.leaf])};`;
		return `(${walk(tree.left, tree.height)},${walk(tree.right, tree.height)});`;
	}

	/** Rows (arrays) as CSV, with a header row. */
	function csv(header, rows) {
		const cell = (v) => {
			const s = v === null || v === undefined ? '' : String(v);
			return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
		};
		return [header, ...rows].map((r) => r.map(cell).join(',')).join('\n') + '\n';
	}

	window.ttTypologyExport = {
		svg: (name, svg) => text(name, '<?xml version="1.0" encoding="UTF-8"?>\n' + svg, 'image/svg+xml'),
		png: async (name, svg) => save(name, await svgToPng(svg, 2)),
		text, newick, csv, svgToPng,
	};
})();
