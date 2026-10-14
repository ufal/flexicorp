/**
 * flexicorp TEITOK UI: Stats: the frequency views beyond the standard charts.
 *  - scatter: one point per row from compared queries — with 4 queries the share of the first in
 *    the first pair (x) against the share of the third in the second pair (y), e.g. languages by
 *    VO vs OV order and AdjN vs NAdj order; with 2 queries their relative frequencies.
 *  - sankey: frequencies by two fields as flows from the first field's values to the second's
 *    (fc_chart_sankey.js), e.g. an English word → its aligned Czech lemmas.
 *  - wordtree: frequencies by the following tokens as a tree of continuations (fc_chart_wordtree.js).
 *  - dispersion: frequencies by text as where the hits fall within each text (fc_stats_dispersion.js).
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpFreqsExtend() (flexicorp_freqs.js)
 * through window.ttFlexicorpFreqsParts; `this` is the component.
 */
window.ttFlexicorpFreqsParts = window.ttFlexicorpFreqsParts || {};
window.ttFlexicorpFreqsParts.stats_freqviz = function () {
	// a pair share needs some hits to mean anything: rows below this many in a pair are left out
	const SCATTER_MIN_PAIR = 20;
	const charts = () => (typeof window !== 'undefined' && window.ttFlexicorpCharts) || {};
	return {
		/** Views drawn as SVG (not Chart.js): no PNG/PDF export, an SVG download instead. */
		frequencyVizSvgMode(mode) {
			return ['sankey', 'wordtree', 'dispersion'].includes(mode === undefined ? this.frequencyVizMode : mode);
		},
		frequencyScatterAvailable() {
			const n = this.frequencyCompareQueries().length;
			return n === 2 || n === 4;
		},
		frequencySankeyAvailable() {
			return !this.frequencyHasCompareQueries() && this.frequencyFieldsArray().length === 2 && !!charts().sankey;
		},
		/** The word tree reads its fields as the following words, so only token attributes qualify. */
		frequencyWordTreeAvailable() {
			if (this.frequencyHasCompareQueries() || !charts().wordtree) return false;
			const raw = this.frequency && Array.isArray(this.frequency.fields) && this.frequency.fields.length
				? this.frequency.fields
				: String((this.frequency && this.frequency.field) || '').split(/\s*,\s*/).filter(Boolean);
			return raw.length >= 1 && raw.every((f) => this.frequencyFieldIsTokenAttr(f));
		},
		/**
		 * A frequency field names a token attribute (form, lemma, b.form, match.upos, feats.Number) rather
		 * than metadata (text_lang, b.u_speaker): checked against the corpus's token attributes when the
		 * corpus information is loaded, else by the region_attribute naming of metadata fields.
		 */
		frequencyFieldIsTokenAttr(field) {
			const f = String(field || '').trim();
			if (!f) return false;
			const r = typeof this.corpusStatsInfoResult === 'function' ? this.corpusStatsInfoResult() : null;
			const list = r && (Array.isArray(r.attributes) ? r.attributes.map((a) => a && a.name) : r.pattributes);
			const tokenAttrs = new Set((Array.isArray(list) ? list : Object.values(list || {})).filter(Boolean).map(String));
			const base = (s) => s.split(/[./]/)[0];
			const isToken = (s) => (tokenAttrs.size ? tokenAttrs.has(base(s)) : !base(s).includes('_'));
			const dot = f.indexOf('.');
			if (dot < 0) return isToken(f);                                  // form; not text_lang
			if (tokenAttrs.has(f.slice(0, dot))) return true;                // feats.Number
			return isToken(f.slice(dot + 1));                                // b.form, match.lemma; not b.text_lang
		},
		/** Whether a view fits the results; before there are results (a share link restoring its view) any view may stay. */
		frequencyVizModeAvailable(mode) {
			const f = this.frequency;
			if (!f || !f.ran || !Array.isArray(f.rows) || !f.rows.length) return true;
			if (mode === 'scatter') return this.frequencyScatterAvailable();
			if (mode === 'sankey') return this.frequencySankeyAvailable();
			if (mode === 'wordtree') return this.frequencyWordTreeAvailable();
			if (mode === 'dispersion') return this.frequencyDispersionAvailable();
			return true;
		},

		/** Scatter points {label, x, y, n} and axis titles; rows with too few hits are skipped. */
		frequencyScatterData() {
			const qs = this.frequencyCompareQueries();
			const rows = this.frequencyChartRowsBase();
			const cnt = (row, q) => this.frequencyRowQueryCount(row, q);
			const points = [];
			let skipped = 0;
			if (qs.length === 4) {
				for (const row of rows) {
					const a = cnt(row, qs[0]), b = cnt(row, qs[1]), c = cnt(row, qs[2]), d = cnt(row, qs[3]);
					if (a + b < SCATTER_MIN_PAIR || c + d < SCATTER_MIN_PAIR) { skipped += 1; continue; }
					points.push({ label: this.frequencyRowValue(row) || '(empty)', x: (100 * a) / (a + b), y: (100 * c) / (c + d), n: a + b + c + d });
				}
				return {
					points, skipped, percent: true,
					xTitle: `% ${qs[0]} (of ${qs[0]} + ${qs[1]})`,
					yTitle: `% ${qs[2]} (of ${qs[2]} + ${qs[3]})`,
				};
			}
			const relative = (this.frequencyChartValueScale || 'relative') === 'relative';
			for (const row of rows) {
				const a = cnt(row, qs[0]), b = cnt(row, qs[1]);
				if (a + b <= 0) { skipped += 1; continue; }
				points.push({
					label: this.frequencyRowValue(row) || '(empty)',
					x: relative ? this.frequencyRowQueryRelativeNumber(row, qs[0]) : a,
					y: relative ? this.frequencyRowQueryRelativeNumber(row, qs[1]) : b,
					n: a + b,
				});
			}
			const unit = relative ? ' (per million)' : ' (count)';
			return { points, skipped, percent: false, xTitle: qs[0] + unit, yTitle: qs[1] + unit };
		},

		/** Chart.js scatter on the frequency canvas, every point labelled with its row value. */
		renderFrequencyScatter(ctx) {
			const Chart = window.Chart;
			const { points, percent, xTitle, yTitle } = this.frequencyScatterData();
			const labelPlugin = {
				id: 'fcScatterLabels',
				afterDatasetsDraw(chart) {
					const meta = chart.getDatasetMeta(0);
					const c = chart.ctx;
					c.save();
					c.font = '11px system-ui, sans-serif';
					c.fillStyle = '#333';
					meta.data.forEach((pt, i) => c.fillText(points[i].label, pt.x + 6, pt.y - 4));
					c.restore();
				},
			};
			const axis = (title) => ({
				title: { display: true, text: title },
				...(percent ? { min: 0, max: 100 } : { beginAtZero: true }),
			});
			this.frequencyChartInstance = new Chart(ctx, {
				type: 'scatter',
				data: {
					datasets: [{
						label: '',
						data: points.map((p) => ({ x: p.x, y: p.y })),
						backgroundColor: 'rgba(54, 162, 235, 0.75)',
						borderColor: 'rgb(54, 162, 235)',
						pointRadius: 5,
						pointHoverRadius: 7,
					}],
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					animation: false,
					layout: { padding: { right: 40, top: 12 } },   // room for the labels of points at the edges
					plugins: {
						legend: { display: false },
						tooltip: {
							callbacks: {
								label: (c) => {
									const p = points[c.dataIndex];
									const f = (v) => (percent ? `${v.toFixed(1)}%` : this.frequencyFormatIpmDisplay(v));
									return `${p.label}: ${f(p.x)}, ${f(p.y)} (${p.n.toLocaleString()} hits)`;
								},
							},
						},
					},
					scales: { x: axis(xTitle), y: axis(yTitle) },
				},
				plugins: [labelPlugin],
			});
		},

		/** The note under the scatter: how to read it, and what was left out. */
		frequencyScatterNote() {
			if (this.frequencyVizMode !== 'scatter') return '';
			const { skipped, percent } = this.frequencyScatterData();
			const qs = this.frequencyCompareQueries();
			const unit = String(this.frequencyFieldsArray()[0] || 'value');
			const one = unit.charAt(0).toLowerCase() + unit.slice(1);
			const how = percent
				? `Each point is one ${one}: across, the share of ${qs[0]} against ${qs[1]}; up, the share of ${qs[2]} against ${qs[3]}.`
				: `Each point is one ${one}: ${qs[0]} across, ${qs[1]} up.`;
			const few = percent ? `fewer than ${SCATTER_MIN_PAIR} hits in a pair` : 'no hits';
			const left = skipped ? ` ${skipped} ${skipped === 1 ? 'value' : 'values'} with ${few} ${skipped === 1 ? 'is' : 'are'} not shown.` : '';
			return how + left;
		},

		/** Sankey links / word tree rows from the frequency rows (all of them, not the chart's top N). */
		frequencyPathRows() {
			return this.frequencyChartRowsBase().map((row) => ({ path: this.frequencyRowValuesArray(row), count: this.frequencyRowCount(row) }));
		},
		/** The word the tree starts from: the searched value when the query names one, else the query. */
		frequencyWordTreeRootLabel() {
			const q = typeof this.statsBaseQueryText === 'function' ? String(this.statsBaseQueryText() || '') : '';
			const m = q.match(/\[\s*(?:lemma|word|form)\s*=\s*"([^"]+)"/);
			if (m) return m[1];
			return q.length > 24 ? q.slice(0, 22) + '…' : (q || '…');
		},
		renderFrequencySvgChart() {
			const box = document.getElementById('flexicorp-freq-svg');
			if (!box) return;
			const c = charts();
			const width = box.clientWidth || 900;
			if (this.frequencyVizMode === 'sankey' && c.sankey) {
				const fields = this.frequencyFieldsArray();
				const links = this.frequencyPathRows().map((r) => ({ source: r.path[0], target: r.path[1], value: r.count }));
				this.frequencySvgInfo = c.sankey(box, links, { leftTitle: fields[0], rightTitle: fields[1], maxLinks: 40, width });
			} else if (this.frequencyVizMode === 'wordtree' && c.wordtree) {
				this.frequencySvgInfo = c.wordtree(box, this.frequencyPathRows(), { root: this.frequencyWordTreeRootLabel(), width });
			} else if (this.frequencyVizMode === 'dispersion' && c.dispersion) {
				this.renderFrequencyDispersion(box);
			}
		},
		frequencySvgNote() {
			const info = this.frequencySvgInfo || {};
			if (this.frequencyVizMode === 'sankey') {
				const more = info.total > info.shown ? ` The ${info.shown} largest of ${info.total} flows are shown.` : '';
				return `Hover over a word to follow its flows.${more}`;
			}
			if (this.frequencyVizMode === 'wordtree') return 'The most frequent continuations; a larger word occurs more often. Hover over a word for its count.';
			if (this.frequencyVizMode === 'dispersion') return this.frequencyDispersionNote();
			return '';
		},
		downloadFrequencySvg() {
			const svg = document.querySelector('#flexicorp-freq-svg svg');
			if (!svg) return;
			const blob = new Blob([new XMLSerializer().serializeToString(svg)], { type: 'image/svg+xml' });
			const a = document.createElement('a');
			a.href = URL.createObjectURL(blob);
			a.download = `${typeof this.frequencyChartExportBasename === 'function' ? this.frequencyChartExportBasename() : 'frequency'}.svg`;
			document.body.appendChild(a);
			a.click();
			setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 0);
		},
	};
};
