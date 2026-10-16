/**
 * flexicorp TEITOK UI library: a Chart.js chart drawn again as SVG (Chart.js only draws on a canvas),
 * for downloads that can be edited: every label, axis title and caption is text. Reads the chart's
 * own type and data (bar — vertical, horizontal, stacked, with error bars —, line, radar, pie,
 * doughnut, polar area). The caption on top says what the chart shows (the query, the corpus, what
 * is measured); the same text goes into <title> / <desc>. Also: an existing SVG (Sankey, word tree,
 * dispersion) framed with such a caption.
 *
 *   window.ttFlexicorpChartSvg.fromChart(chart, { caption: [lines], valueTitle, categoryTitle, width, height })
 *   window.ttFlexicorpChartSvg.frame(svgElementOrString, { caption: [lines] })
 *   window.ttFlexicorpChartSvg.download(filename, svgString)
 */
(function () {
	'use strict';

	const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const FONT = 'Helvetica, Arial, sans-serif';
	const fmt = (v) => {
		const n = Number(v);
		if (!Number.isFinite(n)) return '';
		if (Math.abs(n) >= 1000) return n.toLocaleString('en', { maximumFractionDigits: 0 });
		return Number.isInteger(n) ? String(n) : n.toFixed(Math.abs(n) < 1 ? 3 : 2).replace(/\.?0+$/, '');
	};

	/** Round tick values from 0 to at least max (about 5 steps). */
	function ticks(max) {
		const m = max > 0 ? max : 1;
		const raw = m / 5;
		const p = 10 ** Math.floor(Math.log10(raw));
		const step = [1, 2, 2.5, 5, 10].map((k) => k * p).find((s) => s >= raw) || raw;
		const out = [];
		for (let v = 0; v <= m + step * 0.001; v += step) out.push(Number(v.toFixed(10)));
		if (out[out.length - 1] < m) out.push(Number((out[out.length - 1] + step).toFixed(10)));
		return out;
	}

	/** Caption lines wrapped to the width; first line bold. → { svg, height } */
	function caption(lines, width) {
		const out = [];
		(lines || []).filter(Boolean).forEach((line, i) => {
			const size = i ? 11 : 13;
			const max = Math.max(30, Math.floor((width - 16) / (size > 11 ? 7.5 : 6)));
			let cur = '';
			const push = (t) => out.push({ t, first: i === 0 });
			for (const w of String(line).split(' ')) {
				if (cur && (cur + ' ' + w).length > max) { push(cur); cur = w; } else cur = cur ? cur + ' ' + w : w;
			}
			if (cur) push(cur);
		});
		const svg = out.map((l, k) => `<text x="8" y="${16 + k * 16}" font-size="${l.first ? 13 : 11}" font-weight="${l.first ? 700 : 400}" fill="${l.first ? '#121a24' : '#3a4654'}" class="fc-caption">${esc(l.t)}</text>`).join('');
		return { svg, height: out.length ? out.length * 16 + 10 : 0 };
	}

	function wrapSvg(width, height, body, lines) {
		const cap = caption(lines, width);
		const title = lines && lines[0] ? `<title>${esc(lines[0])}</title>` : '';
		const desc = lines && lines.length > 1 ? `<desc>${esc(lines.slice(1).join('\n'))}</desc>` : '';
		return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height + cap.height}" width="${width}" height="${height + cap.height}" font-family="${FONT}">`
			+ `${title}${desc}<rect width="${width}" height="${height + cap.height}" fill="#fff"/>${cap.svg}<g transform="translate(0,${cap.height})">${body}</g></svg>`;
	}

	const colourAt = (c, i) => (Array.isArray(c) ? c[i % c.length] : c) || '#4e79a7';
	const solid = (c) => String(c || '#4e79a7').replace(/rgba\(([^,]+),([^,]+),([^,]+),[^)]+\)/, 'rgb($1,$2,$3)');

	/** A legend (datasets, or the slices of a pie): rows of swatch + label, from (x, y). */
	function legend(items, x, y, maxW) {
		let cx = x, cy = y;
		const parts = [];
		for (const it of items) {
			const w = 18 + String(it.label).length * 6.2;
			if (cx + w > x + maxW && cx > x) { cx = x; cy += 16; }
			parts.push(`<rect x="${cx}" y="${cy - 9}" width="11" height="11" fill="${esc(it.colour)}"/><text x="${cx + 15}" y="${cy}" font-size="11" fill="#333">${esc(it.label)}</text>`);
			cx += w + 10;
		}
		return { svg: parts.join(''), height: items.length ? cy - y + 20 : 0 };
	}

	function axisTitleSvg(text, x, y, rotate) {
		if (!text) return '';
		const t = rotate ? ` transform="translate(${x},${y}) rotate(-90)"` : ` x="${x}" y="${y}"`;
		return `<text${t} font-size="12" fill="#3a4654" text-anchor="middle" class="fc-axis-title">${esc(text)}</text>`;
	}

	/** Bars and lines on two axes (categories × values). */
	function cartesian(chart, o, W) {
		const type = chart.config.type;
		// the chart's configuration (config.options), not chart.options (Chart.js's resolving proxy)
		const cfg = chart.config.options || {};
		const horizontal = type === 'bar' && cfg.indexAxis === 'y';
		const sc = cfg.scales || {};
		const stacked = !!((sc.x && sc.x.stacked) || (sc.y && sc.y.stacked));
		const labels = (chart.data.labels || []).map((l) => (Array.isArray(l) ? l.join(' ') : String(l)));
		const ds = (chart.data.datasets || []).filter((d) => !d.hidden);
		const n = labels.length;
		let max = 0;
		for (let i = 0; i < n; i += 1) {
			if (stacked) max = Math.max(max, ds.reduce((a, d) => a + Math.max(0, Number(d.data[i]) || 0), 0));
			else for (const d of ds) max = Math.max(max, Number(d.data[i]) || 0, Number(d.errorUpper && d.errorUpper[i]) || 0);
		}
		const tv = ticks(max);
		const top = tv[tv.length - 1] || 1;
		const parts = [];
		const leg = ds.length > 1 || (ds[0] && ds[0].label && o.legendSingle) ? legend(ds.map((d, i) => ({ label: d.label || `series ${i + 1}`, colour: solid(colourAt(d.backgroundColor, 0)) })), 10, 14, W - 20) : { svg: '', height: 0 };
		parts.push(leg.svg);
		const maxLabel = Math.min(40, Math.max(...labels.map((l) => l.length), 1));
		if (horizontal) {
			const left = Math.min(320, 20 + maxLabel * 6.4), right = 20, topY = leg.height + 10;
			const rowH = Math.max(14, Math.min(28, 22));
			const plotH = n * rowH, plotW = W - left - right;
			const x = (v) => left + (plotW * v) / top;
			tv.forEach((t) => parts.push(`<line x1="${x(t)}" y1="${topY}" x2="${x(t)}" y2="${topY + plotH}" stroke="#e5e7eb"/><text x="${x(t)}" y="${topY + plotH + 14}" font-size="10" fill="#555" text-anchor="middle">${fmt(t)}</text>`));
			labels.forEach((lab, i) => {
				const y0 = topY + i * rowH;
				parts.push(`<text x="${left - 6}" y="${y0 + rowH / 2 + 4}" font-size="11" fill="#333" text-anchor="end">${esc(lab.length > 48 ? lab.slice(0, 47) + '…' : lab)}</text>`);
				let acc = 0;
				const bh = stacked ? rowH * 0.7 : (rowH * 0.8) / ds.length;
				ds.forEach((d, k) => {
					const v = Math.max(0, Number(d.data[i]) || 0);
					const yb = stacked ? y0 + rowH * 0.15 : y0 + rowH * 0.1 + k * bh;
					const xa = stacked ? x(acc) : x(0);
					parts.push(`<rect x="${xa.toFixed(1)}" y="${yb.toFixed(1)}" width="${Math.max(0, x(v) - x(0)).toFixed(1)}" height="${bh.toFixed(1)}" fill="${esc(colourAt(d.backgroundColor, i))}"><title>${esc(`${lab}${d.label ? ' — ' + d.label : ''}: ${fmt(v)}`)}</title></rect>`);
					if (stacked) acc += v;
				});
			});
			parts.push(`<line x1="${left}" y1="${topY}" x2="${left}" y2="${topY + plotH}" stroke="#888"/>`);
			parts.push(axisTitleSvg(o.valueTitle, left + plotW / 2, topY + plotH + 32));
			parts.push(axisTitleSvg(o.categoryTitle, 14, topY + plotH / 2, true));
			return { body: parts.join(''), height: topY + plotH + 44 };
		}
		// vertical bars and lines
		const left = 64, right = 20, topY = leg.height + 10;
		const slant = n > 8 || maxLabel > 10;
		const bottom = slant ? Math.min(160, 20 + maxLabel * 5.2) : 34;
		const plotH = Math.max(220, (o.height || 360) - topY - bottom), plotW = W - left - right;
		const y = (v) => topY + plotH - (plotH * v) / top;
		const colW = plotW / Math.max(1, n);
		const cx = (i) => left + colW * (i + 0.5);
		tv.forEach((t) => parts.push(`<line x1="${left}" y1="${y(t)}" x2="${left + plotW}" y2="${y(t)}" stroke="#e5e7eb"/><text x="${left - 6}" y="${y(t) + 3}" font-size="10" fill="#555" text-anchor="end">${fmt(t)}</text>`));
		labels.forEach((lab, i) => {
			const t = esc(lab.length > 40 ? lab.slice(0, 39) + '…' : lab);
			parts.push(slant
				? `<text transform="translate(${cx(i)},${topY + plotH + 10}) rotate(-45)" font-size="10" fill="#333" text-anchor="end">${t}</text>`
				: `<text x="${cx(i)}" y="${topY + plotH + 14}" font-size="10" fill="#333" text-anchor="middle">${t}</text>`);
		});
		if (type === 'bar') {
			labels.forEach((lab, i) => {
				let acc = 0;
				const bw = stacked ? colW * 0.7 : (colW * 0.8) / ds.length;
				ds.forEach((d, k) => {
					const v = Math.max(0, Number(d.data[i]) || 0);
					const xb = stacked ? cx(i) - bw / 2 : left + colW * i + colW * 0.1 + k * bw;
					const ya = stacked ? y(acc + v) : y(v);
					parts.push(`<rect x="${xb.toFixed(1)}" y="${ya.toFixed(1)}" width="${bw.toFixed(1)}" height="${Math.max(0, y(stacked ? acc : 0) - ya).toFixed(1)}" fill="${esc(colourAt(d.backgroundColor, i))}"><title>${esc(`${lab}${d.label ? ' — ' + d.label : ''}: ${fmt(v)}`)}</title></rect>`);
					// error bars (vbar-error)
					if (!stacked && d.errorLower && d.errorUpper && Number.isFinite(Number(d.errorLower[i])) && Number.isFinite(Number(d.errorUpper[i]))) {
						const xm = xb + bw / 2, lo = y(Number(d.errorLower[i])), hi = y(Number(d.errorUpper[i])), cap = Math.min(8, bw / 3);
						parts.push(`<path d="M${xm},${lo}V${hi}M${xm - cap},${lo}H${xm + cap}M${xm - cap},${hi}H${xm + cap}" stroke="#222" stroke-width="1.2" fill="none"/>`);
					}
					if (stacked) acc += v;
				});
			});
		} else {
			ds.forEach((d) => {
				const pts = labels.map((_, i) => `${cx(i).toFixed(1)},${y(Math.max(0, Number(d.data[i]) || 0)).toFixed(1)}`);
				const col = solid(d.borderColor || colourAt(d.backgroundColor, 0));
				if (d.fill) parts.push(`<path d="M${cx(0)},${y(0)}L${pts.join('L')}L${cx(n - 1)},${y(0)}Z" fill="${esc(colourAt(d.backgroundColor, 0))}" stroke="none"/>`);
				parts.push(`<polyline points="${pts.join(' ')}" fill="none" stroke="${esc(col)}" stroke-width="2"/>`);
				pts.forEach((p, i) => { const [px, py] = p.split(','); parts.push(`<circle cx="${px}" cy="${py}" r="2.5" fill="${esc(col)}"><title>${esc(`${labels[i]}: ${fmt(d.data[i])}`)}</title></circle>`); });
			});
		}
		parts.push(`<line x1="${left}" y1="${topY + plotH}" x2="${left + plotW}" y2="${topY + plotH}" stroke="#888"/>`);
		parts.push(axisTitleSvg(o.valueTitle, 16, topY + plotH / 2, true));
		parts.push(axisTitleSvg(o.categoryTitle, left + plotW / 2, topY + plotH + bottom + 6));
		return { body: parts.join(''), height: topY + plotH + bottom + 16 };
	}

	/** Pie, doughnut, polar area: slices with a legend (label and value). */
	function circular(chart, o, W) {
		const type = chart.config.type;
		const labels = (chart.data.labels || []).map(String);
		const d = (chart.data.datasets || [])[0] || { data: [] };
		const vals = labels.map((_, i) => Math.max(0, Number(d.data[i]) || 0));
		const sum = vals.reduce((a, b) => a + b, 0) || 1;
		const R = 140, cx = 170, cy = 160;
		const parts = [];
		let a0 = -Math.PI / 2;
		const maxV = Math.max(...vals, 1);
		vals.forEach((v, i) => {
			const share = type === 'polarArea' ? 1 / vals.length : v / sum;
			const r = type === 'polarArea' ? R * Math.sqrt(v / maxV) : R;
			const a1 = a0 + share * 2 * Math.PI;
			const big = a1 - a0 > Math.PI ? 1 : 0;
			const p = (a, rr) => `${(cx + rr * Math.cos(a)).toFixed(1)},${(cy + rr * Math.sin(a)).toFixed(1)}`;
			const inner = type === 'doughnut' ? R * 0.5 : 0;
			const path = inner
				? `M${p(a0, r)}A${r},${r} 0 ${big} 1 ${p(a1, r)}L${p(a1, inner)}A${inner},${inner} 0 ${big} 0 ${p(a0, inner)}Z`
				: `M${cx},${cy}L${p(a0, r)}A${r},${r} 0 ${big} 1 ${p(a1, r)}Z`;
			parts.push(`<path d="${path}" fill="${esc(colourAt(d.backgroundColor, i))}" stroke="#fff"><title>${esc(`${labels[i]}: ${fmt(v)}`)}</title></path>`);
			a0 = a1;
		});
		const items = labels.map((l, i) => ({ label: `${l}: ${fmt(vals[i])}`, colour: solid(colourAt(d.backgroundColor, i)) }));
		const lg = items.map((it, i) => `<rect x="${cx + R + 30}" y="${20 + i * 16 - 9}" width="11" height="11" fill="${esc(it.colour)}"/><text x="${cx + R + 45}" y="${20 + i * 16}" font-size="11" fill="#333">${esc(it.label)}</text>`).join('');
		parts.push(lg);
		parts.push(`<text x="${cx}" y="${cy + R + 26}" font-size="12" fill="#3a4654" text-anchor="middle" class="fc-axis-title">${esc(o.valueTitle || '')}</text>`);
		return { body: parts.join(''), height: Math.max(cy + R + 40, 30 + items.length * 16) };
	}

	/** Radar: one spoke per category, a polygon per dataset. */
	function radar(chart, o, W) {
		const labels = (chart.data.labels || []).map(String);
		const ds = (chart.data.datasets || []).filter((d) => !d.hidden);
		let max = 0;
		for (const d of ds) for (const v of d.data) max = Math.max(max, Number(v) || 0);
		const tv = ticks(max);
		const top = tv[tv.length - 1] || 1;
		const leg = legend(ds.map((d, i) => ({ label: d.label || `series ${i + 1}`, colour: solid(d.borderColor || colourAt(d.backgroundColor, 0)) })), 10, 14, W - 20);
		const R = 170, cx = W / 2, cy = leg.height + 30 + R;
		const n = labels.length;
		const ang = (i) => -Math.PI / 2 + (2 * Math.PI * i) / n;
		const pt = (i, v) => `${(cx + (R * v) / top * Math.cos(ang(i))).toFixed(1)},${(cy + (R * v) / top * Math.sin(ang(i))).toFixed(1)}`;
		const parts = [leg.svg];
		tv.slice(1).forEach((t) => parts.push(`<polygon points="${labels.map((_, i) => pt(i, t)).join(' ')}" fill="none" stroke="#e5e7eb"/><text x="${cx + 3}" y="${cy - (R * t) / top + 3}" font-size="9" fill="#777">${fmt(t)}</text>`));
		labels.forEach((l, i) => {
			parts.push(`<line x1="${cx}" y1="${cy}" x2="${pt(i, top).split(',')[0]}" y2="${pt(i, top).split(',')[1]}" stroke="#e5e7eb"/>`);
			const lx = cx + (R + 14) * Math.cos(ang(i)), ly = cy + (R + 14) * Math.sin(ang(i));
			parts.push(`<text x="${lx.toFixed(1)}" y="${(ly + 4).toFixed(1)}" font-size="11" fill="#333" text-anchor="${Math.abs(Math.cos(ang(i))) < 0.2 ? 'middle' : Math.cos(ang(i)) > 0 ? 'start' : 'end'}">${esc(l)}</text>`);
		});
		ds.forEach((d) => {
			const col = solid(d.borderColor || colourAt(d.backgroundColor, 0));
			parts.push(`<polygon points="${labels.map((_, i) => pt(i, Math.max(0, Number(d.data[i]) || 0))).join(' ')}" fill="${esc(colourAt(d.backgroundColor, 0))}" fill-opacity="0.35" stroke="${esc(col)}" stroke-width="2"/>`);
		});
		parts.push(`<text x="${cx}" y="${cy + R + 34}" font-size="12" fill="#3a4654" text-anchor="middle" class="fc-axis-title">${esc(o.valueTitle || '')}</text>`);
		return { body: parts.join(''), height: cy + R + 46 };
	}

	/** The chart as a standalone SVG string ('' for a chart type it does not know). */
	function fromChart(chart, opts) {
		if (!chart || !chart.config) return '';
		const o = opts || {};
		const W = o.width || 900;
		const type = chart.config.type;
		let r;
		if (type === 'bar' || type === 'line') r = cartesian(chart, o, W);
		else if (type === 'pie' || type === 'doughnut' || type === 'polarArea') r = circular(chart, o, W);
		else if (type === 'radar') r = radar(chart, o, W);
		else return '';
		return wrapSvg(W, r.height, r.body, o.caption || []);
	}

	/** An existing SVG (element or string) with a caption above it, as a standalone SVG string. */
	function frame(svg, opts) {
		const el = typeof svg === 'string' ? new DOMParser().parseFromString(svg, 'image/svg+xml').documentElement : svg;
		if (!el) return '';
		const vb = (el.getAttribute('viewBox') || '').split(/[\s,]+/).map(Number);
		const w = vb.length === 4 && vb[2] ? vb[2] : (Number(el.getAttribute('width')) || el.getBoundingClientRect().width || 800);
		const h = vb.length === 4 && vb[3] ? vb[3] : (Number(el.getAttribute('height')) || el.getBoundingClientRect().height || 600);
		const inner = el.cloneNode(true);
		inner.setAttribute('width', w);
		inner.setAttribute('height', h);
		if (!inner.getAttribute('viewBox')) inner.setAttribute('viewBox', `0 0 ${w} ${h}`);
		return wrapSvg(Math.max(w, 600), h, new XMLSerializer().serializeToString(inner), (opts && opts.caption) || []);
	}

	function download(filename, svg) {
		const blob = new Blob(['<?xml version="1.0" encoding="UTF-8"?>\n' + svg], { type: 'image/svg+xml;charset=utf-8' });
		const a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = filename;
		document.body.appendChild(a);
		a.click();
		setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 0);
	}

	window.ttFlexicorpChartSvg = { fromChart, frame, download, ticks };
})();
