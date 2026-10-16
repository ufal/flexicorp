/**
 * flexicorp TEITOK UI: Stats charts that say what they show. A description of the Frequency and
 * Collocation charts (the query, the corpus, what is counted and how it is normalised) as axis titles
 * on the page and as the caption of an SVG download (fc_chart_svg.js: the chart drawn again as SVG,
 * text throughout, so labels and titles can be corrected in any editor). The SVG diagrams (Sankey,
 * word tree, dispersion) get the same caption.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpFreqsExtend() (flexicorp_freqs.js)
 * through window.ttFlexicorpFreqsParts; `this` is the component.
 */
window.ttFlexicorpFreqsParts = window.ttFlexicorpFreqsParts || {};
window.ttFlexicorpFreqsParts.stats_svgexport = function () {
	// what an association measure is, in words (collocations)
	const MEASURES = {
		obs: 'co-occurrences (observed count)', freq: 'co-occurrences (observed count)',
		logdice: 'logDice (association score; at most 14)', mi: 'MI (mutual information)', mi3: 'MI³ (cubed mutual information)',
		t: 't-score', tscore: 't-score', 't-score': 't-score', ll: 'log-likelihood', loglik: 'log-likelihood', dice: 'Dice coefficient',
		zscore: 'z-score', z: 'z-score', 'delta-p': 'ΔP', deltap: 'ΔP', mi_log: 'MI-log-frequency',
	};
	const corpusName = () => String(document.title || '').trim();
	const today = () => new Date().toISOString().slice(0, 10);
	return {
		/** The Frequency chart in words: { valueTitle, categoryTitle, caption: [lines] }. */
		frequencyChartDescription() {
			// the fields' display names (the page's line starts with "Fields: ")
			const line = typeof this.statsFrequencyFieldLabelsLine === 'function' ? String(this.statsFrequencyFieldLabelsLine() || '') : '';
			const fields = line ? line.replace(/^\s*Fields?:\s*/i, '') : String((this.frequency && this.frequency.field) || '');
			const scale = this.frequencyChartValueScale || 'relative';
			const compare = typeof this.frequencyHasCompareQueries === 'function' && this.frequencyHasCompareQueries();
			const hasIpm = (this.frequencyMetricSampleRows() || []).some((row) => this.frequencyRowIpmNumber(row) != null) || (compare && this.frequencyHasBackendProvidedIpm());
			let valueTitle, measure;
			if (scale === 'absolute') {
				valueTitle = 'Number of hits';
				measure = 'Values: the number of hits (absolute counts).';
			} else if (hasIpm && this.frequencyUsesSubcorpusIpm()) {
				valueTitle = `Hits per million words of each ${fields || 'group'}`;
				measure = `Values: hits per million words of the texts with that ${fields || 'value'} (relative to the size of each group, so groups of different sizes can be compared).`;
			} else if (hasIpm) {
				valueTitle = 'Hits per million words of the corpus (ipm)';
				measure = 'Values: hits per million words of the whole corpus (ipm).';
			} else {
				valueTitle = 'Share of all hits (%)';
				measure = 'Values: each value\'s share of all hits of the query (%).';
			}
			const query = typeof this.statsBaseQueryText === 'function' ? this.statsBaseQueryText() : '';
			const queries = compare ? this.frequencyCompareQueries() : [];
			const total = typeof this.frequencyEffectiveTotal === 'function' ? this.frequencyEffectiveTotal() : null;
			const caption = [
				`Frequency by ${fields || 'value'}${corpusName() ? ' — ' + corpusName() : ''}`,
				compare ? `Compared queries (one series each): ${queries.join(', ')}.` : (query ? `Query: ${query}` + (total ? ` (${Number(total).toLocaleString('en')} hits)` : '') : ''),
				measure,
				`flexicorp, ${today()}`,
			].filter(Boolean);
			return { valueTitle, categoryTitle: fields, caption };
		},

		/** The Collocation chart in words. */
		collocationChartDescription() {
			const key = String(typeof this.collocationChartValueKey === 'function' ? this.collocationChartValueKey() : '').trim();
			const measure = MEASURES[key.toLowerCase()] || key || 'score';
			const field = (this.collocation && this.collocation.field) || '';
			const query = typeof this.statsBaseQueryText === 'function' ? this.statsBaseQueryText() : '';
			const c = this.collocation || {};
			const win = c.left != null || c.right != null ? `, window ${c.left ?? '?'} left / ${c.right ?? '?'} right` : '';
			return {
				// capitalised, unless the name has its own capitals (logDice, MI)
				valueTitle: /^[a-z][a-z-]*\b/.test(measure) && !/^\S*[A-Z]/.test(measure) ? measure.charAt(0).toUpperCase() + measure.slice(1) : measure,
				categoryTitle: field ? `Collocate (${field})` : 'Collocate',
				caption: [
					`Collocations${field ? ' by ' + field : ''}${corpusName() ? ' — ' + corpusName() : ''}`,
					query ? `Node: ${query}${win}` : '',
					`Values: ${measure}.`,
					`flexicorp, ${today()}`,
				].filter(Boolean),
			};
		},

		/** Axis titles on a Chart.js chart on the page (bar, line: value and category; radar, pie: none). */
		_applyChartAxisTitles(chart, desc) {
			// the chart's own configuration (chart.config.options), not chart.options (Chart.js's resolving proxy)
			const cfg = chart && chart.config && chart.config.options;
			if (!cfg || !desc) return;
			const type = chart.config.type;
			if (type !== 'bar' && type !== 'line') return;
			const horizontal = cfg.indexAxis === 'y';
			const sc = cfg.scales || (cfg.scales = {});
			const set = (axis, text) => {
				if (!text) return;
				sc[axis] = sc[axis] || {};
				const cur = sc[axis].title;
				if (cur && cur.display && cur.text === text) return;
				sc[axis].title = { display: true, text, font: { size: 12 } };
				changed = true;
			};
			let changed = false;
			set(horizontal ? 'x' : 'y', desc.valueTitle);
			set(horizontal ? 'y' : 'x', desc.categoryTitle);
			if (changed) { try { chart.update('none'); } catch (_) { /* a chart being destroyed */ } }
		},
		frequencyChartApplyAxisTitles() { this._applyChartAxisTitles(this.frequencyChartInstance, this.frequencyChartDescription()); },
		collocationChartApplyAxisTitles() { this._applyChartAxisTitles(this.collocationChartInstance, this.collocationChartDescription()); },

		downloadFrequencyChartSVG() {
			const lib = window.ttFlexicorpChartSvg;
			if (!lib || !this.frequencyChartInstance) return;
			const svg = lib.fromChart(this.frequencyChartInstance, this.frequencyChartDescription());
			if (svg) lib.download(`${this.frequencyChartExportBasename()}.svg`, svg);
		},
		downloadCollocationChartSVG() {
			const lib = window.ttFlexicorpChartSvg;
			if (!lib || !this.collocationChartInstance) return;
			const svg = lib.fromChart(this.collocationChartInstance, this.collocationChartDescription());
			const field = (this.collocation && this.collocation.field) || 'coll';
			if (svg) lib.download(`flexicorp-coll-${String(field).replace(/[^\w-]+/g, '_')}-${today().replace(/-/g, '')}.svg`, svg);
		},
		/** The SVG diagrams (Sankey, word tree, dispersion), with the caption above them. */
		frequencySvgWithCaption(svgEl) {
			const lib = window.ttFlexicorpChartSvg;
			if (!lib || !svgEl) return new XMLSerializer().serializeToString(svgEl);
			const d = this.frequencyChartDescription();
			const what = { sankey: 'Flows (Sankey diagram)', wordtree: 'Word tree', dispersion: 'Dispersion' }[this.frequencyVizMode] || '';
			const caption = what ? [`${what}: ${d.caption[0]}`, ...d.caption.slice(1)] : d.caption;
			return lib.frame(svgEl, { caption });
		},
	};
};
