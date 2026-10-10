/**
 * flexicorp TEITOK UI: Stats: collocations table, chart, export and submit.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpFreqsExtend() (flexicorp_freqs.js)
 * through window.ttFlexicorpFreqsParts; `this` is the component.
 */
window.ttFlexicorpFreqsParts = window.ttFlexicorpFreqsParts || {};
window.ttFlexicorpFreqsParts.stats_coll = function () {
	return {
		collocationResult() {
			const c = this.collocation;
			if (!c || !c.response) return null;
			let r = c.response.result || null;
			for (let i = 0; i < 4 && r && typeof r === 'object'; i += 1) {
				if (Array.isArray(r.collocates) || Array.isArray(r.rows) || (r.table && Array.isArray(r.table.rows))) {
					return r;
				}
				if (r.result && typeof r.result === 'object') {
					r = r.result;
					continue;
				}
				break;
			}
			return r && typeof r === 'object' ? r : null;
		},

		collocationMeasureKeys() {
			// Table columns should follow the user's selected measure list.
			// Backend payload shape may only expose a primary/default measure, so
			// result-driven detection alone can incorrectly pin the table to logdice.
			const selected =
				typeof this.normalizeCollocationMeasureKeys === 'function'
					? this.normalizeCollocationMeasureKeys(this.collocation && this.collocation.measureKeys)
					: (this.collocation && Array.isArray(this.collocation.measureKeys) ? this.collocation.measureKeys : []);
			if (Array.isArray(selected) && selected.length) return selected.map((m) => String(m));
			const r = this.collocationResult();
			if (r && Array.isArray(r.measures) && r.measures.length) return r.measures.map((m) => String(m));
			const row0 = this.collocationRows()[0];
			if (!row0 || typeof row0 !== 'object') return [];
			const skip = new Set(['word', 'obs', 'freq']);
			return Object.keys(row0).filter((k) => !skip.has(k));
		},

		collocationMetaLine() {
			const c = this.collocation || {};
			const r = this.collocationResult();
			const attr = (r && r.attribute) || c.field || '';
			const w = r && Array.isArray(r.window) && r.window.length >= 2 ? r.window : [c.left, c.right];
			const m = c.matches != null ? c.matches : r && r.matches;
			let s = 'attribute=' + attr + ' · window L' + w[0] + '/R' + w[1];
			if (m != null && m !== '') s += ' · matches ' + m;
			if (this.collocationHasMultipleNamedQueryBases()) {
				const eff = this.collocationEffectiveNamedQueryId();
				if (eff) s += ' · base ' + eff;
			}
			return s;
		},

		collocationRowsObsTotal() {
			const rows = this.collocationRows();
			return rows.reduce((sum, row) => {
				const n = Number(row && row.obs);
				return sum + (Number.isFinite(n) && n > 0 ? n : 0);
			}, 0);
		},

		collocationEffectiveObsTotal() {
			const r = this.collocationResult();
			if (!r || typeof r !== 'object') return null;
			const keys = [
				'total_obs',
				'obs_total',
				'sum_obs',
				'window_total',
				'total',
				'total_count',
			];
			for (let i = 0; i < keys.length; i += 1) {
				const n = Number(r[keys[i]]);
				if (Number.isFinite(n) && n > 0) return n;
			}
			if (r.table && typeof r.table === 'object') {
				for (let i = 0; i < keys.length; i += 1) {
					const n = Number(r.table[keys[i]]);
					if (Number.isFinite(n) && n > 0) return n;
				}
			}
			return null;
		},

		collocationRestObsCount() {
			const total = this.collocationEffectiveObsTotal();
			if (total === null || total <= 0) return 0;
			const shown = this.collocationRowsObsTotal();
			const rest = total - shown;
			if (!Number.isFinite(rest) || rest <= 0) return 0;
			return Math.round(rest);
		},

		collocationHasRestBucket() {
			return this.collocationRestObsCount() > 0;
		},

		collocationChartMetricOptions() {
			const opts = [{ key: 'obs', label: 'Obs' }];
			const seen = new Set(['obs']);
			const keys = this.collocationMeasureKeys();
			for (let i = 0; i < keys.length; i += 1) {
				const k = String(keys[i] || '').trim();
				if (!k || seen.has(k)) continue;
				seen.add(k);
				opts.push({ key: k, label: k });
			}
			return opts;
		},

		collocationEnsureChartMetricSelection() {
			const opts = this.collocationChartMetricOptions();
			const valid = new Set(opts.map((o) => String(o.key)));
			const cur = String(this.collocationChartMetricKey || '').trim();
			if (cur && valid.has(cur)) return cur;
			const preferred = opts.find((o) => o.key !== 'obs');
			const next = preferred ? preferred.key : 'obs';
			this.collocationChartMetricKey = next;
			return next;
		},

		onCollocationChartMetricChanged() {
			this.collocationEnsureChartMetricSelection();
			this.scheduleCollocationChartRender();
		},

		/** Column used for charts: chosen metric, or first available measure, else obs. */
		collocationChartValueKey() {
			return this.collocationEnsureChartMetricSelection();
		},

		collocationChartValueLabel() {
			return this.collocationChartValueKey();
		},

		collocationChartDataset() {
			const rows = this.collocationRows();
			const key = this.collocationChartValueKey();
			const labels = [];
			const data = [];
			for (let i = 0; i < rows.length; i++) {
				const row = rows[i];
				const w = row && row.word != null ? String(row.word) : '';
				const label = this.truncateFrequencyChartLabel(w === '' ? '(empty)' : w);
				const raw = row && row[key];
				const n = Number(raw);
				const v = Number.isFinite(n) ? n : 0;
				labels.push(label);
				data.push(v);
			}
			return { labels, data, valueKey: key };
		},

		destroyCollocationChart() {
			this._collocationChartEpoch = (this._collocationChartEpoch || 0) + 1;
			const ch = this.collocationChartInstance;
			if (ch) {
				try {
					if (typeof ch.stop === 'function') ch.stop();
				} catch (_) {
					/* ignore */
				}
				try {
					if (typeof ch.destroy === 'function') ch.destroy();
				} catch (_) {
					/* ignore */
				}
			}
			this.collocationChartInstance = null;
		},

		renderCollocationChart() {
			const Chart = typeof window !== 'undefined' ? window.Chart : null;
			if (!Chart || !this.frequencyChartLibraryAvailable()) return;
			const mode = this.collocationVizMode;
			if (mode === 'table') return;
			const canvas = document.getElementById('flexicorp-coll-chart-canvas');
			if (!canvas || !canvas.getContext || !canvas.isConnected) return;
			const { labels, data } = this.collocationChartDataset();
			if (!labels.length) {
				this.destroyCollocationChart();
				return;
			}
			const valueLabel = this.collocationChartValueLabel();
			const pieLike = mode === 'polar' || mode === 'pie' || mode === 'doughnut';
			const dataPlot = pieLike ? data.map((v) => Math.max(0, Number(v))) : data;
			if (pieLike && !dataPlot.some((x) => Number(x) > 0)) {
				this.destroyCollocationChart();
				return;
			}
			this.destroyCollocationChart();
			if (!canvas.isConnected) return;
			const ctx = canvas.getContext('2d');
			if (!ctx) return;
			const palette = this.frequencyChartPalette(labels.length);
			const common = {
				responsive: true,
				maintainAspectRatio: false,
				animation: { duration: 750 },
			};
			const legendSortByIndex = (a, b) => (a.index ?? 0) - (b.index ?? 0);
			const fmtScore = (x) => {
				const n = Number(x);
				return Number.isFinite(n) ? n.toFixed(3) : String(x);
			};
			const barTooltip = {
				callbacks: {
					label: (ctx) => {
						const v =
							mode === 'bar'
								? ctx.parsed.x
								: ctx.parsed.y != null
									? ctx.parsed.y
									: ctx.parsed.x;
						return typeof v === 'number' && Number.isFinite(v) ? `${valueLabel}: ${fmtScore(v)}` : '';
					},
				},
			};
			const pieTooltip = {
				callbacks: {
					label: (ctx) => {
						let raw = ctx.raw;
						if (raw == null && ctx.parsed != null) {
							raw = typeof ctx.parsed === 'object' && ctx.parsed.r != null ? ctx.parsed.r : ctx.parsed;
						}
						const n = Number(raw);
						const arr = pieLike ? dataPlot : data;
						const tot = Array.isArray(arr) ? arr.reduce((a, b) => a + (Number(b) || 0), 0) : 0;
						const pct = tot > 0 && Number.isFinite(n) ? ((n / tot) * 100).toFixed(1) : '';
						const core = `${valueLabel}: ${fmtScore(n)}`;
						return pct !== '' ? `${core} (${pct}%)` : core;
					},
				},
			};

			try {
				if (mode === 'bar') {
					this.collocationChartInstance = new Chart(ctx, {
						type: 'bar',
						data: {
							labels,
							datasets: [
								{
									label: valueLabel,
									data,
									backgroundColor: palette,
									borderColor: 'rgba(0,0,0,0.08)',
									borderWidth: 1,
								},
							],
						},
						options: {
							...common,
							indexAxis: 'y',
							plugins: { legend: { display: false }, title: { display: false }, tooltip: barTooltip },
							scales: {
								x: {
									beginAtZero: false,
									ticks: {
										callback: (v) => (typeof v === 'number' && Number.isFinite(v) ? fmtScore(v) : v),
									},
								},
							},
						},
					});
					this._resizeCollocationChartAfterLayout();
					return;
				}
				if (mode === 'vbar') {
					const borderEach = palette.map(() => 'rgba(0,0,0,0.08)');
					this.collocationChartInstance = new Chart(ctx, {
						type: 'bar',
						data: {
							labels,
							datasets: [
								{
									label: valueLabel,
									data,
									backgroundColor: palette,
									borderColor: borderEach,
									borderWidth: 1,
								},
							],
						},
						options: {
							...common,
							indexAxis: 'x',
							plugins: { legend: { display: false }, title: { display: false }, tooltip: barTooltip },
							scales: {
								x: {
									type: 'category',
									offset: true,
									ticks: { maxRotation: 55, minRotation: 35, autoSkip: true },
								},
								y: {
									type: 'linear',
									beginAtZero: false,
									ticks: {
										callback: (v) => (typeof v === 'number' && Number.isFinite(v) ? fmtScore(v) : v),
									},
								},
							},
						},
					});
					this._resizeCollocationChartAfterLayout();
					return;
				}
				if (mode === 'line') {
					this.collocationChartInstance = new Chart(ctx, {
						type: 'line',
						data: {
							labels,
							datasets: [
								{
									label: valueLabel,
									data,
									borderColor: 'rgb(75, 192, 192)',
									backgroundColor: 'rgba(75, 192, 192, 0.12)',
									borderWidth: 2,
									fill: true,
									tension: 0.25,
									pointRadius: 3,
									pointHoverRadius: 5,
								},
							],
						},
						options: {
							...common,
							plugins: { legend: { display: false }, title: { display: false }, tooltip: barTooltip },
							scales: {
								x: { ticks: { maxRotation: 55, minRotation: 35, autoSkip: true } },
								y: {
									beginAtZero: false,
									ticks: {
										callback: (v) => (typeof v === 'number' && Number.isFinite(v) ? fmtScore(v) : v),
									},
								},
							},
						},
					});
					this._resizeCollocationChartAfterLayout();
					return;
				}
				if (mode === 'polar') {
					this.collocationChartInstance = new Chart(ctx, {
						type: 'polarArea',
						data: {
							labels,
							datasets: [{ data: dataPlot, backgroundColor: palette, borderColor: '#fff', borderWidth: 1 }],
						},
						options: {
							...common,
							layout: { padding: 8 },
							plugins: {
								legend: {
									position: 'bottom',
									labels: { boxWidth: 12, maxWidth: 220, sort: legendSortByIndex },
								},
								tooltip: pieTooltip,
							},
							scales: { r: { beginAtZero: true } },
						},
					});
					this._resizeCollocationChartAfterLayout();
					return;
				}
				const ring = mode === 'doughnut';
				this.collocationChartInstance = new Chart(ctx, {
					type: ring ? 'doughnut' : 'pie',
					data: {
						labels,
						datasets: [
							{
								data: dataPlot,
								backgroundColor: palette,
								borderColor: '#fff',
								borderWidth: 1,
							},
						],
					},
					options: {
						...common,
						layout: { padding: 8 },
						plugins: {
							legend: {
								position: 'bottom',
								labels: { boxWidth: 12, maxWidth: 220, sort: legendSortByIndex },
							},
							tooltip: pieTooltip,
						},
					},
				});
				this._resizeCollocationChartAfterLayout();
			} catch (_) {
				this.destroyCollocationChart();
			}
		},

		_resizeCollocationChartAfterLayout() {
			const ch = this.collocationChartInstance;
			if (!ch || typeof ch.resize !== 'function') return;
			const epoch = this._collocationChartEpoch;
			requestAnimationFrame(() => {
				if (epoch !== this._collocationChartEpoch || this.collocationChartInstance !== ch) return;
				try {
					ch.resize();
				} catch (_) {
					/* ignore */
				}
			});
		},

		scheduleCollocationChartRender() {
			if (!this.frequencyChartLibraryAvailable()) return;
			if (this.statsSubTab && this.statsSubTab !== 'coll') {
				this.destroyCollocationChart();
				return;
			}
			if (this.collocationVizMode === 'table') {
				this.destroyCollocationChart();
				return;
			}
			const epoch = this._collocationChartEpoch;
			requestAnimationFrame(() => {
				requestAnimationFrame(() => {
					if (epoch !== this._collocationChartEpoch) return;
					if (!this.collocation || !this.collocation.ran || !this.collocationRows().length) {
						this.destroyCollocationChart();
						return;
					}
					if (this.statsSubTab && this.statsSubTab !== 'coll') return;
					if (this.collocationVizMode === 'table') return;
					if (this.activeTab && this.activeTab !== 'frequency') return;
					this.renderCollocationChart();
				});
			});
		},

		setCollocationVizMode(mode) {
			const allowed = new Set(['table', 'bar', 'vbar', 'line', 'polar', 'pie', 'doughnut']);
			const next = allowed.has(mode) ? mode : 'table';
			if (next !== 'table' && !this.frequencyChartLibraryAvailable()) return;
			this.collocationVizMode = next;
			if (next === 'table') {
				this.destroyCollocationChart();
			} else {
				this.scheduleCollocationChartRender();
			}
		},

		onCollocationStateApplied() {
			if (this.collocationVizMode && this.collocationVizMode !== 'table') {
				this.scheduleCollocationChartRender();
			}
		},

		collocationChartExportBasename() {
			const field = this.collocation && this.collocation.field ? String(this.collocation.field) : 'coll';
			const safe = field.replace(/[^\w\-]+/g, '_').slice(0, 64) || 'coll';
			const d = new Date();
			const y = d.getFullYear();
			const m = String(d.getMonth() + 1).padStart(2, '0');
			const day = String(d.getDate()).padStart(2, '0');
			return `flexicorp-coll-${safe}-${y}${m}${day}`;
		},

		downloadCollocationChartPNG() {
			const canvas = document.getElementById('flexicorp-coll-chart-canvas');
			if (!canvas || !canvas.getContext) return;
			const url = canvas.toDataURL('image/png');
			this.triggerDownload(url, `${this.collocationChartExportBasename()}.png`);
		},

		downloadCollocationChartJPEG() {
			const canvas = document.getElementById('flexicorp-coll-chart-canvas');
			if (!canvas || !canvas.getContext) return;
			const url = canvas.toDataURL('image/jpeg', 0.92);
			this.triggerDownload(url, `${this.collocationChartExportBasename()}.jpg`);
		},

		downloadCollocationChartPrintForPdf() {
			const canvas = document.getElementById('flexicorp-coll-chart-canvas');
			if (!canvas || !canvas.getContext) return;
			const imgData = canvas.toDataURL('image/png', 1.0);
			const w = window.open('');
			if (!w) return;
			const title = 'Collocation chart — Save as PDF from print dialog';
			w.document.write(
				`<!DOCTYPE html><html><head><meta charset="utf-8"/><title>${title}</title></head><body style="margin:0;padding:16px;text-align:center;font-family:system-ui,sans-serif;"><p style="margin:0 0 12px 0;">Use <strong>Print</strong> (Ctrl+P / Cmd+P) and choose <strong>Save as PDF</strong>.</p><img src="${imgData}" alt="chart" style="max-width:100%;height:auto;border:1px solid #ddd"/></body></html>`
			);
			w.document.close();
		},

		async downloadCollocationChartPDF() {
			const canvas = document.getElementById('flexicorp-coll-chart-canvas');
			if (!canvas || !canvas.getContext) return;
			const imgData = canvas.toDataURL('image/png', 1.0);
			const imgWpx = canvas.width;
			const imgHpx = canvas.height;
			if (imgWpx <= 0 || imgHpx <= 0) return;
			try {
				const JsPDF = await this.loadJsPDF();
				const pdf = new JsPDF({ orientation: imgWpx >= imgHpx ? 'l' : 'p', unit: 'mm', format: 'a4' });
				const pageW = pdf.internal.pageSize.getWidth();
				const pageH = pdf.internal.pageSize.getHeight();
				const margin = 12;
				const maxW = pageW - margin * 2;
				const maxH = pageH - margin * 2;
				const ratio = imgHpx / imgWpx;
				let drawW = maxW;
				let drawH = drawW * ratio;
				if (drawH > maxH) {
					drawH = maxH;
					drawW = drawH / ratio;
				}
				const x = margin + (maxW - drawW) / 2;
				const y = margin + (maxH - drawH) / 2;
				pdf.addImage(imgData, 'PNG', x, y, drawW, drawH);
				pdf.save(`${this.collocationChartExportBasename()}.pdf`);
			} catch (_) {
				this.downloadCollocationChartPrintForPdf();
			}
		},

		submitCollocation(event) {
			if (typeof this.statsSearchScopePersistToSearch === 'function') this.statsSearchScopePersistToSearch();
			const form = this.getEventForm(event);
			if (!form) return;
			const fd = this.buildCommonRequestData();
			const fe = new FormData(form);
			for (const pair of fe.entries()) {
				fd.set(pair[0], pair[1]);
			}
			fd.set('active_tab', 'frequency');
			fd.set('run', 'coll');
			const qo = typeof this.statsOutgoingQueryForCollSubmit === 'function' ? this.statsOutgoingQueryForCollSubmit() : '';
			if (qo) fd.set('query', qo);
			this.submitAjaxData(fd, 'collocation');
		},

		submitCollocationFromButton() {
			if (typeof this.statsSearchScopePersistToSearch === 'function') this.statsSearchScopePersistToSearch();
			const formData = this.buildCommonRequestData();
			const qo = typeof this.statsOutgoingQueryForCollSubmit === 'function' ? this.statsOutgoingQueryForCollSubmit() : '';
			if (qo) formData.set('query', qo);
			formData.set('active_tab', 'frequency');
			formData.set('run', 'coll');
			this.submitAjaxData(formData, 'collocation');
		},
	};
};
