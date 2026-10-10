/**
 * flexicorp TEITOK UI: Stats: "Other" results (generic tables) and their chart.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpFreqsExtend() (flexicorp_freqs.js)
 * through window.ttFlexicorpFreqsParts; `this` is the component.
 */
window.ttFlexicorpFreqsParts = window.ttFlexicorpFreqsParts || {};
window.ttFlexicorpFreqsParts.stats_other = function () {
	return {
		otherColumnsList() {
			const other = this.other && typeof this.other === 'object' ? this.other : {};
			const rows = Array.isArray(other.rows) ? other.rows : [];
			const cols = Array.isArray(other.columns) ? other.columns : [];
			if (cols.length) return cols.map((c) => String(c));
			if (rows.length && rows[0] && typeof rows[0] === 'object' && !Array.isArray(rows[0])) {
				return Object.keys(rows[0]);
			}
			if (rows.length && Array.isArray(rows[0])) {
				return rows[0].map((_, i) => `col_${i + 1}`);
			}
			return [];
		},

		otherRowsList() {
			const other = this.other && typeof this.other === 'object' ? this.other : {};
			return Array.isArray(other.rows) ? other.rows : [];
		},

		otherCellValue(row, col, colIndex) {
			if (Array.isArray(row)) return row[colIndex];
			if (row && typeof row === 'object') return row[col];
			return row;
		},

		otherToNumber(value) {
			if (typeof value === 'number' && Number.isFinite(value)) return value;
			if (typeof value === 'string') {
				const cleaned = value.replace(/,/g, '').trim();
				if (!cleaned) return NaN;
				const n = Number(cleaned);
				return Number.isFinite(n) ? n : NaN;
			}
			if (typeof value === 'boolean') return value ? 1 : 0;
			return NaN;
		},

		otherNumericColumns() {
			const cols = this.otherColumnsList();
			const rows = this.otherRowsList();
			if (!cols.length || !rows.length) return [];
			const out = [];
			for (let i = 0; i < cols.length; i += 1) {
				const col = cols[i];
				let seen = 0;
				let numeric = 0;
				for (let r = 0; r < rows.length && seen < 120; r += 1) {
					const v = this.otherCellValue(rows[r], col, i);
					if (v == null || v === '') continue;
					seen += 1;
					if (Number.isFinite(this.otherToNumber(v))) numeric += 1;
				}
				if (seen > 0 && numeric / seen >= 0.7) out.push(col);
			}
			return out;
		},

		otherEnsureChartSelection() {
			const cols = this.otherColumnsList();
			if (!cols.length) {
				this.otherChartXColumn = '';
				this.otherChartYColumn = '';
				return;
			}
			const xNow = String(this.otherChartXColumn || '').trim();
			if (!xNow || !cols.includes(xNow)) this.otherChartXColumn = cols[0];
			const numeric = this.otherNumericColumns();
			const x = String(this.otherChartXColumn || '').trim();
			const preferredY = numeric.find((c) => c !== x) || numeric[0] || '';
			const yNow = String(this.otherChartYColumn || '').trim();
			if (!yNow || !numeric.includes(yNow) || yNow === x) this.otherChartYColumn = preferredY;
			if (!this.otherChartYColumn) this.otherVizMode = 'table';
		},

		otherChartData() {
			this.otherEnsureChartSelection();
			const rows = this.otherRowsList();
			const cols = this.otherColumnsList();
			const xCol = String(this.otherChartXColumn || '').trim();
			const yCol = String(this.otherChartYColumn || '').trim();
			const xi = cols.indexOf(xCol);
			const yi = cols.indexOf(yCol);
			if (xi < 0 || yi < 0 || !rows.length) return { labels: [], values: [], yLabel: yCol || 'Value' };
			const labels = [];
			const values = [];
			for (let i = 0; i < rows.length && labels.length < 120; i += 1) {
				const row = rows[i];
				const xv = this.otherCellValue(row, xCol, xi);
				const yv = this.otherCellValue(row, yCol, yi);
				const n = this.otherToNumber(yv);
				if (!Number.isFinite(n)) continue;
				const lbl = String(xv == null ? '' : xv).trim() || `(row ${i + 1})`;
				labels.push(this.truncateFrequencyChartLabel(lbl));
				values.push(n);
			}
			return { labels, values, yLabel: yCol || 'Value' };
		},

		destroyOtherChart() {
			this._otherChartEpoch = (this._otherChartEpoch || 0) + 1;
			const ch = this.otherChartInstance;
			if (ch) {
				try { if (typeof ch.stop === 'function') ch.stop(); } catch (_) {}
				try { if (typeof ch.destroy === 'function') ch.destroy(); } catch (_) {}
			}
			this.otherChartInstance = null;
		},

		renderOtherChart() {
			const Chart = typeof window !== 'undefined' ? window.Chart : null;
			if (!Chart || !this.frequencyChartLibraryAvailable()) return;
			const mode = this.otherVizMode;
			if (mode === 'table') return;
			const canvas = document.getElementById('flexicorp-other-chart-canvas');
			if (!canvas || !canvas.getContext || !canvas.isConnected) return;
			const { labels, values, yLabel } = this.otherChartData();
			if (!labels.length) {
				this.destroyOtherChart();
				return;
			}
			this.destroyOtherChart();
			const ctx = canvas.getContext('2d');
			if (!ctx) return;
			const palette = this.frequencyChartPalette(labels.length);
			try {
				this.otherChartInstance = new Chart(ctx, {
					type: mode === 'line' ? 'line' : 'bar',
					data: {
						labels,
						datasets: [{
							label: yLabel,
							data: values,
							backgroundColor: mode === 'line' ? 'rgba(59,130,246,0.18)' : palette,
							borderColor: mode === 'line' ? 'rgb(37,99,235)' : 'rgba(0,0,0,0.08)',
							borderWidth: mode === 'line' ? 2 : 1,
							fill: mode === 'line',
							tension: mode === 'line' ? 0.2 : 0,
						}],
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						indexAxis: mode === 'bar' ? 'y' : 'x',
						plugins: { legend: { display: false } },
						scales: {
							x: { ticks: { maxRotation: 55, minRotation: 35, autoSkip: true } },
							y: { beginAtZero: false },
						},
					},
				});
			} catch (_) {
				this.destroyOtherChart();
			}
		},

		scheduleOtherChartRender() {
			this.otherEnsureChartSelection();
			if (!this.frequencyChartLibraryAvailable()) return;
			if (this.statsSubTab && this.statsSubTab !== 'other') {
				this.destroyOtherChart();
				return;
			}
			if (this.otherVizMode === 'table') {
				this.destroyOtherChart();
				return;
			}
			const epoch = this._otherChartEpoch;
			requestAnimationFrame(() => {
				requestAnimationFrame(() => {
					if (epoch !== this._otherChartEpoch) return;
					if (!this.other || !this.other.ran || !this.otherRowsList().length) {
						this.destroyOtherChart();
						return;
					}
					if (this.statsSubTab && this.statsSubTab !== 'other') return;
					if (this.activeTab && this.activeTab !== 'frequency') return;
					this.renderOtherChart();
				});
			});
		},

		setOtherVizMode(mode) {
			const allowed = new Set(['table', 'bar', 'line']);
			const next = allowed.has(mode) ? mode : 'table';
			if (next !== 'table' && !this.frequencyChartLibraryAvailable()) return;
			this.otherVizMode = next;
			if (next === 'table') this.destroyOtherChart();
			else this.scheduleOtherChartRender();
		},

		onOtherChartColumnsChanged() {
			this.otherEnsureChartSelection();
			if (this.otherVizMode !== 'table') this.scheduleOtherChartRender();
		},
	};
};
