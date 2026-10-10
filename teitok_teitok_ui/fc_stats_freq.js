/**
 * flexicorp TEITOK UI: Stats: frequency table, charts, compare metrics, export and submit.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpFreqsExtend() (flexicorp_freqs.js)
 * through window.ttFlexicorpFreqsParts; `this` is the component.
 */
window.ttFlexicorpFreqsParts = window.ttFlexicorpFreqsParts || {};
window.ttFlexicorpFreqsParts.stats_freq = function () {
	return {
		truncateFrequencyChartLabel(val, maxLen) {
			const max = typeof maxLen === 'number' && maxLen > 8 ? maxLen : 48;
			const s = val == null ? '' : String(val);
			if (s.length <= max) return s;
			return s.slice(0, Math.max(0, max - 1)) + '…';
		},

		frequencyChartPalette(count) {
			const out = [];
			for (let i = 0; i < count; i++) {
				const h = (200 + i * 47) % 360;
				const s = 52 + (i % 3) * 7;
				const l = 48 + (i % 5) * 4;
				out.push(`hsla(${h}, ${s}%, ${l}%, 0.88)`);
			}
			return out;
		},

		/** Row count for charts: same as the frequency limit (no extra cap). */
		frequencyChartMaxSlices() {
			return Math.max(1, this.normalizePositiveInt(this.frequency && this.frequency.limit, 100));
		},

		frequencyChartSumRowCounts(rows) {
			if (!Array.isArray(rows)) return 0;
			return rows.reduce((sum, row) => {
				const raw = row && (row.count ?? row.freq ?? row.n);
				const n = Number(raw);
				const v = Number.isFinite(n) && n >= 0 ? n : 0;
				return sum + v;
			}, 0);
		},

		frequencyRowIpmNumber(row) {
			if (!row || typeof row !== 'object') return null;
			const raw = row.ipm ?? row.IPM ?? row.relfreq ?? row.relative ?? row.relative_ipm;
			if (raw != null && raw !== '') {
				const n = typeof raw === 'number' ? raw : Number(String(raw).replace(/,/g, ''));
				if (Number.isFinite(n) && n >= 0) return n;
			}
			const ct = this.frequencyCorpusTokensForIpm();
			const c = Number(row.count ?? row.freq ?? row.n);
			if (ct !== null && ct > 0 && Number.isFinite(c) && c >= 0) return (c / ct) * 1000000;
			return null;
		},

		frequencyRowNormalizedIpmNumber(row) {
			const ct = this.frequencyCorpusTokensForIpm();
			const c = Number(row && (row.count ?? row.freq ?? row.n));
			if (ct !== null && ct > 0 && Number.isFinite(c) && c >= 0) return (c / ct) * 1000000;
			return null;
		},

		frequencyRowRelativeSubcorpusIpmNumber(row) {
			if (!this.frequencyUsesSubcorpusIpm()) return null;
			const raw = row && (row.ipm ?? row.IPM ?? row.relfreq ?? row.relative ?? row.relative_ipm);
			if (raw === null || raw === undefined || raw === '') return null;
			const n = typeof raw === 'number' ? raw : Number(String(raw).replace(/,/g, ''));
			return Number.isFinite(n) && n >= 0 ? n : null;
		},

		frequencyRowPctNumber(row) {
			const raw = row && (row.pct ?? row.percent ?? row.percentage);
			const p = Number(raw);
			if (Number.isFinite(p) && p >= 0) return p;
			const base = this.frequencyRelativeBase();
			const c = Number(row && (row.count ?? row.freq ?? row.n));
			if (base !== null && base > 0 && Number.isFinite(c) && c >= 0) return (c / base) * 100;
			return null;
		},

		/** Numeric value driving chart slice size (not the table). */
		frequencyRowChartNumeric(row) {
			const scale = this.frequencyChartValueScale || 'relative';
			if (scale === 'absolute') {
				return this.frequencyRowCount(row);
			}
			const ipm = this.frequencyRowIpmNumber(row);
			if (ipm !== null) return ipm;
			const pct = this.frequencyRowPctNumber(row);
			if (pct !== null) return pct;
			return this.frequencyRowCount(row);
		},

		frequencyChartSumRowChartNumerics(rows) {
			const slice = Array.isArray(rows) ? rows : [];
			let s = 0;
			for (let i = 0; i < slice.length; i++) {
				const v = this.frequencyRowChartNumeric(slice[i]);
				if (Number.isFinite(v) && v >= 0) s += v;
			}
			return s;
		},

		/** Others bucket weight for charts (aligned with table Rest when possible). */
		frequencyChartOthersNumeric() {
			const scale = this.frequencyChartValueScale || 'relative';
			const rest = this.frequencyRestCount();
			if (rest <= 0) return 0;
			if (scale === 'absolute') return rest;
			const s = this.frequencyRestRowIpm();
			if (s !== '') {
				const n = Number(s);
				if (Number.isFinite(n) && n >= 0) return n;
			}
			const total = this.frequencyEffectiveTotal();
			if (total !== null && total > 0) return (rest / total) * 100;
			return rest;
		},

		frequencyChartYLabel() {
			const scale = this.frequencyChartValueScale || 'relative';
			if (scale === 'absolute') return 'Count';
			const sample = this.frequencyMetricSampleRows();
			const hasIpm = sample.some((row) => this.frequencyRowIpmNumber(row) != null);
			if (hasIpm) {
				return this.frequencyUsesSubcorpusIpm() ? 'Relative (Subcorpus IPM)' : 'Relative (IPM)';
			}
			return 'Relative (%)';
		},

		frequencySupportsErrorBars() {
			if (this.frequencyHasCompareQueries()) return false;
			return this.frequencyRows().some((row) => {
				const n = Number(row && row.subcorpus_size);
				const k = this.frequencyRowCount(row);
				return Number.isFinite(n) && n > 0 && Number.isFinite(k) && k >= 0 && k <= n;
			});
		},

		frequencyRowUncertaintyBounds(row) {
			const n = Number(row && row.subcorpus_size);
			const kRaw = this.frequencyRowCount(row);
			if (!Number.isFinite(n) || n <= 0 || !Number.isFinite(kRaw) || kRaw < 0) return null;
			const k = Math.min(kRaw, n);
			const p = k / n;
			const z = 1.959963984540054;
			const z2 = z * z;
			const denom = 1 + z2 / n;
			const center = (p + z2 / (2 * n)) / denom;
			const margin = (z * Math.sqrt((p * (1 - p) + z2 / (4 * n)) / n)) / denom;
			let lo = Math.max(0, center - margin);
			let hi = Math.min(1, center + margin);

			const scale = this.frequencyChartValueScale || 'relative';
			if (scale === 'absolute') {
				return { low: lo * n, high: hi * n };
			}
			// Match chart scale: IPM when available, otherwise percentage.
			const usesIpm = this.frequencyRowIpmNumber(row) !== null;
			const factor = usesIpm ? 1000000 : 100;
			lo *= factor;
			hi *= factor;
			return { low: lo, high: hi };
		},

		setFrequencyChartValueScale(scale) {
			const next = scale === 'absolute' ? 'absolute' : 'relative';
			this._frequencyScaleChosen = true;
			if (next === 'relative' && !this.frequencySupportsRelativeScale()) {
				this.frequencyChartValueScale = 'absolute';
			} else {
				this.frequencyChartValueScale = next;
			}
			if (this.frequencyVizMode && this.frequencyVizMode !== 'table') {
				this.scheduleFrequencyChartRender();
			}
		},

		setFrequencyChartOrder(mode) {
			const allowed = new Set(['size', 'value', 'time']);
			let next = allowed.has(mode) ? mode : 'size';
			if (next === 'time' && !this.frequencyCanUseTimeOrder()) next = 'size';
			this.frequencyChartOrder = next;
			if (this.frequencyVizMode && this.frequencyVizMode !== 'table' && this.frequencyVizMode !== 'pivot') {
				this.scheduleFrequencyChartRender();
			}
		},

		frequencySingleFieldKeyForOrdering() {
			const f = this.frequency && typeof this.frequency === 'object' ? this.frequency : {};
			const keys = [];
			if (Array.isArray(f.fields) && f.fields.length) {
				for (let i = 0; i < f.fields.length; i += 1) {
					const k = String(f.fields[i] || '').trim();
					if (k) keys.push(k);
				}
			} else if (Array.isArray(f.formFields) && f.formFields.length) {
				for (let i = 0; i < f.formFields.length; i += 1) {
					const k = String(f.formFields[i] || '').trim();
					if (k) keys.push(k);
				}
			} else {
				const k = String(f.field || '').trim();
				if (k) keys.push(k);
			}
			if (keys.length !== 1) return '';
			const only = keys[0];
			if (only.includes(',') || only.includes('\t')) return '';
			return only;
		},

		frequencyFieldLooksTemporal(fieldKey) {
			const k = String(fieldKey || '').trim().toLowerCase();
			if (!k) return false;
			if (/^decade\s*\(/i.test(k) || /^century\s*\(/i.test(k)) return true;
			return /(^|_)(date|datetime|timestamp|time|year|month|day|decade|century)(_|$)/.test(k);
		},

		frequencyCanUseTimeOrder() {
			const key = this.frequencySingleFieldKeyForOrdering();
			if (!key) return false;
			return this.frequencyFieldLooksTemporal(key);
		},

		frequencyChartRowsBase() {
			let rows = this.frequencyRows();
			const q = (this.frequencyTableSearch || '').trim().toLowerCase();
			if (q) {
				rows = rows.filter((r) => this.frequencyRowRawValue(r).toLowerCase().includes(q));
			}
			return rows;
		},

		frequencyChartRowCompareNumeric(row) {
			if (!row || !row.queries || typeof row.queries !== 'object') return 0;
			const scale = this.frequencyChartValueScale || 'relative';
			const qs = this.frequencyCompareQueries();
			let sum = 0;
			for (let i = 0; i < qs.length; i++) {
				const q = qs[i];
				const cell = row.queries[q] || {};
				let v = 0;
				if (scale === 'relative') v = this.frequencyRowQueryRelativeNumber(row, q);
				else v = Number(cell.count) || 0;
				if (Number.isFinite(v) && v >= 0) sum += v;
			}
			return sum;
		},

		frequencyChartParseTemporalOrder(rawValue) {
			const raw = String(rawValue == null ? '' : rawValue).trim();
			if (!raw) return null;
			const decade = raw.match(/(-?\d{3,4})\s*s$/i);
			if (decade) return Number(decade[1]);
			const century = raw.match(/(-?\d{1,2})(?:st|nd|rd|th)?\s*century$/i);
			if (century) return (Number(century[1]) - 1) * 100;
			const year = raw.match(/^-?\d{3,4}$/);
			if (year) return Number(year[0]);
			const anyYear = raw.match(/-?\d{3,4}/);
			if (anyYear) return Number(anyYear[0]);
			return null;
		},

		frequencyChartTemporalMeta(rawValue) {
			const rawAll = String(rawValue == null ? '' : rawValue).trim();
			if (!rawAll) return null;
			const parts = rawAll.split('\t').map((p) => String(p).trim()).filter(Boolean);
			const candidates = parts.length ? parts : [rawAll];
			const fieldHint = String(this.frequency && this.frequency.field ? this.frequency.field : '').toLowerCase();
			const hintDecade = fieldHint.includes('decade(');
			const hintCentury = fieldHint.includes('century(');
			for (let i = 0; i < candidates.length; i++) {
				const raw = candidates[i];
				const decade = raw.match(/(-?\d{3,4})\s*s$/i);
				if (decade) {
					const y = Number(decade[1]);
					const d = Math.floor(y / 10) * 10;
					return { key: d, step: 10, label: `${d}s` };
				}
				const century = raw.match(/(-?\d{1,2})(?:st|nd|rd|th)?\s*century$/i);
				if (century) {
					const c = Number(century[1]);
					return { key: (c - 1) * 100, step: 100, label: `${c}th century` };
				}
				const year = raw.match(/^-?\d{3,4}$/);
				if (year) {
					const y = Number(year[0]);
					if (hintDecade) {
						const d = Math.floor(y / 10) * 10;
						return { key: d, step: 10, label: String(d) };
					}
					if (hintCentury) {
						const c0 = Math.floor(y / 100) * 100;
						return { key: c0, step: 100, label: String(c0) };
					}
					return { key: y, step: 1, label: String(y) };
				}
				const anyYear = raw.match(/-?\d{3,4}/);
				if (anyYear) {
					const y = Number(anyYear[0]);
					if (hintDecade) {
						const d = Math.floor(y / 10) * 10;
						return { key: d, step: 10, label: String(d) };
					}
					if (hintCentury) {
						const c0 = Math.floor(y / 100) * 100;
						return { key: c0, step: 100, label: String(c0) };
					}
					return { key: y, step: 1, label: String(y) };
				}
			}
			return null;
		},

		frequencyChartZeroRowForTemporalLabel(label, multiple = false) {
			const row = { value: String(label), count: 0, freq: 0, n: 0 };
			if (multiple) {
				const queries = this.frequencyCompareQueries();
				row.queries = {};
				for (let i = 0; i < queries.length; i++) {
					const q = queries[i];
					row.queries[q] = { count: 0, pct: 0, ipm: 0 };
				}
			}
			return row;
		},

		frequencyChartFillTemporalGaps(rows, multiple = false) {
			const list = Array.isArray(rows) ? rows : [];
			if (!list.length) return list;
			const parsed = [];
			const passthrough = [];
			for (let i = 0; i < list.length; i++) {
				const row = list[i];
				const meta = this.frequencyChartTemporalMeta(this.frequencyRowRawValue(row));
				if (!meta) {
					passthrough.push(row);
					continue;
				}
				parsed.push({ row, key: meta.key, step: meta.step, label: meta.label, raw: this.frequencyRowRawValue(row) });
			}
			if (parsed.length < 2) return list;
			const stepCounts = {};
			parsed.forEach((p) => {
				stepCounts[p.step] = (stepCounts[p.step] || 0) + 1;
			});
			const bestStep = Number(
				Object.keys(stepCounts).sort((a, b) => Number(stepCounts[b]) - Number(stepCounts[a]))[0] || 0,
			);
			const step = Number.isFinite(bestStep) && bestStep > 0 ? bestStep : 1;
			const byKey = new Map();
			parsed.forEach((p) => byKey.set(p.key, p.row));
			const keys = parsed.map((p) => p.key).sort((a, b) => a - b);
			const min = keys[0];
			const max = keys[keys.length - 1];
			if (!Number.isFinite(min) || !Number.isFinite(max) || max <= min) return list;
			const useDecadeSuffix = step === 10 && parsed.some((p) => /\d{3,4}\s*s$/i.test(String(p.raw || '')));
			const filled = [];
			for (let k = min; k <= max; k += step) {
				if (byKey.has(k)) {
					filled.push(byKey.get(k));
					continue;
				}
				let label = String(k);
				if (step === 10) label = useDecadeSuffix ? `${k}s` : String(k);
				else if (step === 100) label = `${Math.floor(k / 100) + 1}th century`;
				filled.push(this.frequencyChartZeroRowForTemporalLabel(label, multiple));
			}
			return filled.concat(passthrough);
		},

		frequencyRomanNumeralToInt(label) {
			const raw = String(label || '').trim().toUpperCase();
			if (!raw) return null;
			// Accept pure Roman numerals or a leading Roman token (e.g. "XIX century").
			const m = raw.match(/^([MDCLXVI]+)(?:\b|$)/);
			if (!m) return null;
			const token = String(m[1] || '').trim();
			if (!token || !/^[MDCLXVI]+$/.test(token)) return null;
			const vals = { I: 1, V: 5, X: 10, L: 50, C: 100, D: 500, M: 1000 };
			let total = 0;
			for (let i = 0; i < token.length; i += 1) {
				const cur = vals[token[i]] || 0;
				const next = vals[token[i + 1]] || 0;
				if (cur < next) total -= cur;
				else total += cur;
			}
			if (!Number.isFinite(total) || total <= 0) return null;
			// Validate by canonical round-trip to avoid false positives on malformed numerals.
			const toRoman = (n) => {
				const parts = [
					[1000, 'M'], [900, 'CM'], [500, 'D'], [400, 'CD'],
					[100, 'C'], [90, 'XC'], [50, 'L'], [40, 'XL'],
					[10, 'X'], [9, 'IX'], [5, 'V'], [4, 'IV'], [1, 'I'],
				];
				let x = n;
				let out = '';
				for (let p = 0; p < parts.length; p += 1) {
					const v = parts[p][0];
					const s = parts[p][1];
					while (x >= v) {
						out += s;
						x -= v;
					}
				}
				return out;
			};
			return toRoman(total) === token ? total : null;
		},

		frequencyCompareValueLabels(aRaw, bRaw) {
			const a = String(aRaw == null ? '' : aRaw);
			const b = String(bRaw == null ? '' : bRaw);
			const ar = this.frequencyRomanNumeralToInt(a);
			const br = this.frequencyRomanNumeralToInt(b);
			if (ar !== null && br !== null) return ar - br;
			if (ar !== null) return -1;
			if (br !== null) return 1;
			return a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' });
		},

		frequencyChartOrderedRows(rows, multiple = false) {
			const list = Array.isArray(rows) ? [...rows] : [];
			const mode = this.frequencyChartOrder || 'size';
			const sizeValue = (row) => (multiple ? this.frequencyChartRowCompareNumeric(row) : this.frequencyRowChartNumeric(row));
			if (mode === 'value') {
				list.sort((a, b) => this.frequencyCompareValueLabels(this.frequencyRowRawValue(a), this.frequencyRowRawValue(b)));
				return list;
			}
			if (mode === 'time' && this.frequencyCanUseTimeOrder()) {
				list.sort((a, b) => {
					const ta = this.frequencyChartParseTemporalOrder(this.frequencyRowRawValue(a));
					const tb = this.frequencyChartParseTemporalOrder(this.frequencyRowRawValue(b));
					if (ta !== null && tb !== null) return ta - tb;
					if (ta !== null) return -1;
					if (tb !== null) return 1;
					return this.frequencyCompareValueLabels(this.frequencyRowRawValue(a), this.frequencyRowRawValue(b));
				});
				return this.frequencyChartFillTemporalGaps(list, multiple);
			}
			list.sort((a, b) => sizeValue(b) - sizeValue(a));
			return list;
		},

		frequencyChartIsTimeOrder() {
			return (this.frequencyChartOrder || 'size') === 'time' && this.frequencyCanUseTimeOrder();
		},

		/**
		 * Charts: first N rows (N = frequency limit) in table order, then one Others slice at the end
		 * (not reordered by size). Others uses the same scale as primary slices.
		 */
		frequencyChartDataset() {
			if (this.frequencyHasCompareQueries()) {
				const queries = this.frequencyCompareQueries();
				const labels = [];
				const rows = this.frequencyChartRowsBase();
				const orderedRows = this.frequencyChartOrderedRows(rows, true);
				const maxSlices = this.frequencyChartMaxSlices();
				const cap = this.frequencyChartIsTimeOrder()
					? orderedRows.length
					: Math.min(orderedRows.length, maxSlices);
				const slice = orderedRows.slice(0, cap);
				
				const datasets = queries.map((q, idx) => {
					const colors = [
						'rgba(54, 162, 235, 0.7)',
						'rgba(255, 99, 132, 0.7)',
						'rgba(75, 192, 192, 0.7)',
						'rgba(255, 206, 86, 0.7)',
						'rgba(153, 102, 255, 0.7)'
					];
					const borderColors = [
						'rgb(54, 162, 235)',
						'rgb(255, 99, 132)',
						'rgb(75, 192, 192)',
						'rgb(255, 206, 86)',
						'rgb(153, 102, 255)'
					];
					const color = colors[idx % colors.length];
					const borderColor = borderColors[idx % borderColors.length];
					return {
						label: q,
						data: [],
						backgroundColor: color,
						borderColor: borderColor,
						borderWidth: 1,
						fill: false
					};
				});

				for (let i = 0; i < slice.length; i++) {
					const row = slice[i];
					const rawLabel = this.frequencyRowValue(row);
					labels.push(this.truncateFrequencyChartLabel(rawLabel === '' ? '(empty)' : rawLabel));
					
					queries.forEach((q, qIdx) => {
						let val = 0;
						if (row.queries && row.queries[q]) {
							if (this.frequencyChartValueScale === 'relative') {
								val = this.frequencyRowQueryRelativeNumber(row, q);
							} else {
								val = Number(row.queries[q].count) || 0;
							}
						}
						datasets[qIdx].data.push(val);
					});
				}
				
				return { labels, data: [], datasets, multiple: true, rowRefs: [] };
			}

			const labels = [];
			const data = [];
			const rows = this.frequencyChartRowsBase();
			const orderedRows = this.frequencyChartOrderedRows(rows, false);
			const maxSlices = this.frequencyChartMaxSlices();
			const cap = this.frequencyChartIsTimeOrder()
				? orderedRows.length
				: Math.min(orderedRows.length, maxSlices);
			const slice = orderedRows.slice(0, cap);
			const rowRefs = [];
			let sumSlice = 0;
			for (let i = 0; i < slice.length; i++) {
				const row = slice[i];
				const v = this.frequencyRowChartNumeric(row);
				const num = Number(v);
				const use = Number.isFinite(num) && num >= 0 ? num : 0;
				sumSlice += use;
				const rawLabel = this.frequencyRowValue(row);
				const label = this.truncateFrequencyChartLabel(rawLabel === '' ? '(empty)' : rawLabel);
				// Keep zero points in chart arrays so timeline gaps remain visible in Time order.
				labels.push(label);
				data.push(use);
				rowRefs.push(row);
			}
			const totalEff = typeof this.frequencyEffectiveTotal === 'function' ? this.frequencyEffectiveTotal() : null;
			let others = 0;
			if (!this.frequencyChartIsTimeOrder()) {
				if (totalEff !== null && totalEff > 0) {
					others = this.frequencyChartOthersNumeric();
				} else {
					others = this.frequencyChartSumRowChartNumerics(orderedRows.slice(cap));
				}
			}
			if (others > 0) {
				labels.push('Others');
				data.push(others);
				rowRefs.push(null);
			}
			return { labels, data, rowRefs };
		},

		destroyFrequencyChart() {
			this._frequencyChartEpoch = (this._frequencyChartEpoch || 0) + 1;
			const ch = this.frequencyChartInstance;
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
			this.frequencyChartInstance = null;
			
			// Hard-reset the canvas DOM node to prevent Chart.js from throwing
			// 'Cannot read properties of null (reading "save")' if it tries to 
			// complete a lingering requestAnimationFrame on an orphaned context.
			const oldCanvas = document.getElementById('flexicorp-freq-chart-canvas');
			if (oldCanvas && oldCanvas.parentNode) {
				const newCanvas = document.createElement('canvas');
				newCanvas.id = 'flexicorp-freq-chart-canvas';
				newCanvas.setAttribute('aria-label', 'Frequency chart');
				oldCanvas.parentNode.replaceChild(newCanvas, oldCanvas);
			}
		},

		renderFrequencyChart() {
			const Chart = typeof window !== 'undefined' ? window.Chart : null;
			if (!Chart || !this.frequencyChartLibraryAvailable()) return;
			const mode = this.frequencyVizMode;
			if (mode === 'table') return;
			const canvas = document.getElementById('flexicorp-freq-chart-canvas');
			if (!canvas || !canvas.getContext || !canvas.isConnected) return;
			const { labels, data, datasets, multiple, rowRefs } = this.frequencyChartDataset();
			if (!labels.length) {
				this.destroyFrequencyChart();
				return;
			}
			this.destroyFrequencyChart();
			// destroyFrequencyChart replaces the canvas node; the prior `canvas` ref is detached.
			const canvasLive = document.getElementById('flexicorp-freq-chart-canvas');
			if (!canvasLive || !canvasLive.getContext || !canvasLive.isConnected) return;
			const ctx = canvasLive.getContext('2d');
			if (!ctx) return;
			const palette = this.frequencyChartPalette(labels.length);
			const scale = this.frequencyChartValueScale || 'relative';
			const yLabel = this.frequencyChartYLabel();
			const dsLabel = scale === 'absolute' ? 'Count' : yLabel;
			// Short animations (Chart default is ~1000ms). Lifecycle guards (stop/destroy, epoch, tab/backend teardown)
			// avoid the rare null-ctx crash when switching views; no need to disable motion entirely.
			const common = {
				responsive: true,
				maintainAspectRatio: false,
				animation: {
					duration: 750,
				},
			};
			/** Keep table order in the legend; always place Others last (not sorted by slice size). */
			const othersLastLegendSort = (a, b, data) => {
				const lab = data && data.labels ? data.labels : [];
				const aTxt = String((lab[a.index] != null ? lab[a.index] : a.text) || '').trim();
				const bTxt = String((lab[b.index] != null ? lab[b.index] : b.text) || '').trim();
				const aO = aTxt === 'Others';
				const bO = bTxt === 'Others';
				if (aO !== bO) return aO ? 1 : -1;
				return (a.index ?? 0) - (b.index ?? 0);
			};
			const pieLikeTooltip = {
				callbacks: {
					label: (ctx) => {
						let raw = ctx.raw;
						if (raw == null && ctx.parsed != null) {
							raw = typeof ctx.parsed === 'object' && ctx.parsed.r != null ? ctx.parsed.r : ctx.parsed;
						}
						const n = Number(raw);
						const tot = Array.isArray(data) ? data.reduce((a, b) => a + (Number(b) || 0), 0) : 0;
						const pct = tot > 0 && Number.isFinite(n) ? ((n / tot) * 100).toFixed(1) : '';
						if (scale === 'absolute') {
							return pct !== '' ? `count: ${n} (${pct}%)` : `count: ${n}`;
						}
						const main = `${ctx.dataset.label || yLabel}: ${this.frequencyFormatIpmDisplay(n)}`;
						return pct !== '' ? `${main} (${pct}% of chart)` : main;
					},
				},
			};
			const barTooltip = {
				callbacks: {
					label: (ctx) => {
						// Horizontal bar (indexAxis 'y'): value is on x; y is category index (often 0 for first row).
						// Vertical bar: value is on y.
						let v;
						if (mode === 'bar' || mode === 'stacked-bar') {
							v = ctx.parsed.x;
						} else if (mode === 'vbar' || mode === 'stacked-vbar') {
							v = ctx.parsed.y;
						} else if (mode === 'line') {
							v = ctx.parsed.y != null ? ctx.parsed.y : ctx.parsed.x;
						} else if (mode === 'radar') {
							v = ctx.parsed.r != null ? ctx.parsed.r : ctx.parsed.y != null ? ctx.parsed.y : ctx.parsed.x;
						} else {
							v = ctx.parsed.y != null ? ctx.parsed.y : ctx.parsed.x;
						}
						if (typeof v !== 'number' || !Number.isFinite(v)) return '';
						let baseLabel = '';
						if (scale === 'absolute') baseLabel = `count: ${v}`;
						else baseLabel = `${ctx.dataset.label || yLabel}: ${this.frequencyFormatIpmDisplay(v)}`;
						if (mode === 'vbar-error' && !multiple && ctx.dataset) {
							const lo = Array.isArray(ctx.dataset.errorLower) ? Number(ctx.dataset.errorLower[ctx.dataIndex]) : NaN;
							const hi = Array.isArray(ctx.dataset.errorUpper) ? Number(ctx.dataset.errorUpper[ctx.dataIndex]) : NaN;
							if (Number.isFinite(lo) && Number.isFinite(hi) && hi >= lo) {
								if (scale === 'absolute') return `${baseLabel} (95% CI: ${lo.toFixed(1)} - ${hi.toFixed(1)})`;
								return `${baseLabel} (95% CI: ${lo.toFixed(2)} - ${hi.toFixed(2)})`;
							}
						}
						return baseLabel;
					},
				},
			};

			const chartDatasets = multiple ? datasets : [
				{
					// Single-series charts use the external scale toggle; keep internal legend label empty.
					label: '',
					data,
					backgroundColor: palette,
					borderColor: mode === 'vbar' ? palette.map(() => 'rgba(0,0,0,0.08)') : 'rgba(0,0,0,0.08)',
					borderWidth: 1,
				}
			];
			const showSeriesLegend = multiple && Array.isArray(chartDatasets) && chartDatasets.length > 1;
			if (mode === 'line' && !multiple) {
				chartDatasets[0].borderColor = 'rgb(54, 162, 235)';
				chartDatasets[0].backgroundColor = 'rgba(54, 162, 235, 0.12)';
				chartDatasets[0].borderWidth = 2;
				chartDatasets[0].fill = true;
				chartDatasets[0].tension = 0.25;
				chartDatasets[0].pointRadius = 3;
				chartDatasets[0].pointHoverRadius = 5;
			} else if (mode === 'polar' || mode === 'pie' || mode === 'doughnut') {
				if (multiple) {
					// Fallback for pie/doughnut/polar when multiple queries: 
					// we just display the first query to avoid messing up the UI.
					chartDatasets.splice(1);
				}
				if (!multiple) chartDatasets[0].borderColor = '#fff';
			}

			const errorBarPlugin = {
				id: 'flexicorpErrorBars',
				afterDatasetsDraw: (chart) => {
					if (mode !== 'vbar-error' || multiple) return;
					const ds = chart && chart.data && chart.data.datasets ? chart.data.datasets[0] : null;
					if (!ds || !Array.isArray(ds.errorLower) || !Array.isArray(ds.errorUpper)) return;
					const meta = chart.getDatasetMeta(0);
					if (!meta || !Array.isArray(meta.data)) return;
					const yScaleRef = chart.scales && chart.scales.y;
					if (!yScaleRef) return;
					const c = chart.ctx;
					c.save();
					c.strokeStyle = 'rgba(20, 20, 20, 0.85)';
					c.lineWidth = 1.2;
					for (let i = 0; i < meta.data.length; i += 1) {
						const el = meta.data[i];
						const lo = Number(ds.errorLower[i]);
						const hi = Number(ds.errorUpper[i]);
						if (!el || !Number.isFinite(lo) || !Number.isFinite(hi)) continue;
						const yLo = yScaleRef.getPixelForValue(lo);
						const yHi = yScaleRef.getPixelForValue(hi);
						const x = el.x;
						if (!Number.isFinite(x) || !Number.isFinite(yLo) || !Number.isFinite(yHi)) continue;
						const halfCap = Math.max(4, Math.min(10, (el.width || 18) * 0.25));
						c.beginPath();
						c.moveTo(x, yLo);
						c.lineTo(x, yHi);
						c.moveTo(x - halfCap, yLo);
						c.lineTo(x + halfCap, yLo);
						c.moveTo(x - halfCap, yHi);
						c.lineTo(x + halfCap, yHi);
						c.stroke();
					}
					c.restore();
				},
			};

			try {
			if (mode === 'bar' || mode === 'stacked-bar') {
				const stacked = mode === 'stacked-bar';
				this.frequencyChartInstance = new Chart(ctx, {
					type: 'bar',
					data: {
						labels,
						datasets: chartDatasets,
					},
					options: {
						...common,
						indexAxis: 'y',
						plugins: {
							legend: { display: false },
							title: { display: false },
							tooltip: barTooltip,
						},
						scales: {
							x: {
								stacked: stacked,
								beginAtZero: true,
								ticks: { precision: scale === 'absolute' ? 0 : 2 },
							},
							y: {
								stacked: stacked,
							},
						},
					},
				});
				this._resizeFrequencyChartAfterLayout();
				return;
			}
			if (mode === 'vbar' || mode === 'stacked-vbar' || mode === 'vbar-error') {
				const stacked = mode === 'stacked-vbar';
				if (mode === 'vbar-error' && !multiple && Array.isArray(rowRefs)) {
					const lows = [];
					const highs = [];
					for (let i = 0; i < rowRefs.length; i += 1) {
						const b = rowRefs[i] ? this.frequencyRowUncertaintyBounds(rowRefs[i]) : null;
						lows.push(b ? b.low : null);
						highs.push(b ? b.high : null);
					}
					chartDatasets[0].errorLower = lows;
					chartDatasets[0].errorUpper = highs;
				}
				this.frequencyChartInstance = new Chart(ctx, {
					type: 'bar',
					data: {
						labels,
						datasets: chartDatasets,
					},
					plugins: mode === 'vbar-error' ? [errorBarPlugin] : [],
					options: {
						...common,
						indexAxis: 'x',
						plugins: {
							legend: { display: false },
							title: { display: false },
							tooltip: barTooltip,
						},
						scales: {
							x: {
								stacked: stacked,
								type: 'category',
								offset: true,
								ticks: {
									maxRotation: 55,
									minRotation: 35,
									autoSkip: true,
								},
							},
							y: {
								stacked: stacked,
								type: 'linear',
								beginAtZero: true,
								ticks: { precision: scale === 'absolute' ? 0 : 2 },
							},
						},
					},
				});
				this._resizeFrequencyChartAfterLayout();
				return;
			}
			if (mode === 'radar') {
				this.frequencyChartInstance = new Chart(ctx, {
					type: 'radar',
					data: {
						labels,
						datasets: chartDatasets,
					},
					options: {
						...common,
						plugins: {
							legend: { display: false },
							title: { display: false },
							tooltip: barTooltip,
						},
						scales: {
							r: {
								beginAtZero: true,
								ticks: { precision: scale === 'absolute' ? 0 : 2 },
							},
						},
					},
				});
				this._resizeFrequencyChartAfterLayout();
				return;
			}
			if (mode === 'line') {
				this.frequencyChartInstance = new Chart(ctx, {
					type: 'line',
					data: {
						labels,
						datasets: chartDatasets,
					},
					options: {
						...common,
						plugins: {
							legend: { display: false },
							title: { display: false },
							tooltip: barTooltip,
						},
						scales: {
							x: {
								ticks: {
									maxRotation: 55,
									minRotation: 35,
									autoSkip: true,
								},
							},
							y: {
								beginAtZero: true,
								ticks: { precision: scale === 'absolute' ? 0 : 2 },
							},
						},
					},
				});
				this._resizeFrequencyChartAfterLayout();
				return;
			}
			if (mode === 'polar') {
				this.frequencyChartInstance = new Chart(ctx, {
					type: 'polarArea',
					data: {
						labels,
						datasets: chartDatasets,
					},
					options: {
						...common,
						layout: { padding: 8 },
						plugins: {
							legend: { display: false },
							tooltip: pieLikeTooltip,
						},
						scales: {
							r: {
								beginAtZero: true,
								ticks: { precision: 0 },
							},
						},
					},
				});
				this._resizeFrequencyChartAfterLayout();
				return;
			}
			const ring = mode === 'doughnut';
			this.frequencyChartInstance = new Chart(ctx, {
				type: ring ? 'doughnut' : 'pie',
				data: {
					labels,
					datasets: chartDatasets,
				},
				options: {
					...common,
					layout: {
						padding: 8,
					},
					plugins: {
						legend: { display: false },
						tooltip: pieLikeTooltip,
					},
				},
			});
			this._resizeFrequencyChartAfterLayout();
			} catch (_) {
				this.destroyFrequencyChart();
			}
		},

		frequencyChartExportBasename() {
			const field = this.frequency && this.frequency.field ? String(this.frequency.field) : 'frequency';
			const safe = field.replace(/[^\w\-]+/g, '_').slice(0, 64) || 'frequency';
			const d = new Date();
			const y = d.getFullYear();
			const m = String(d.getMonth() + 1).padStart(2, '0');
			const day = String(d.getDate()).padStart(2, '0');
			return `flexicorp-freq-${safe}-${y}${m}${day}`;
		},

		triggerDownload(href, filename) {
			const a = document.createElement('a');
			a.href = href;
			a.download = filename;
			a.rel = 'noopener';
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
		},

		downloadFrequencyChartPNG() {
			const canvas = document.getElementById('flexicorp-freq-chart-canvas');
			if (!canvas || !canvas.getContext) return;
			const url = canvas.toDataURL('image/png');
			this.triggerDownload(url, `${this.frequencyChartExportBasename()}.png`);
		},

		downloadFrequencyChartJPEG() {
			const canvas = document.getElementById('flexicorp-freq-chart-canvas');
			if (!canvas || !canvas.getContext) return;
			const url = canvas.toDataURL('image/jpeg', 0.92);
			this.triggerDownload(url, `${this.frequencyChartExportBasename()}.jpg`);
		},

		downloadFrequencyTableCSV() {
			const rows = this.frequencyDisplayRows();
			if (!rows.length) return;
			const fields = this.frequencyFieldsArray();
			const hasPct = this.frequencyHasProvidedPct();
			const hasNormIpm = this.frequencyHasNormalizedIpm();
			const hasRelSubIpm = this.frequencyHasRelativeSubcorpusIpm();
			const hasSize = this.frequencyHasProvidedSubcorpusSize();
			
			const headerCols = [...fields, 'Count'];
			if (hasPct) headerCols.push('%');
			if (hasNormIpm) headerCols.push('Normalized (IPM)');
			if (hasSize) headerCols.push('Subcorpus size');
			if (hasRelSubIpm) headerCols.push('Relative (Subcorpus IPM)');
			
			const escapeCsv = (str) => {
				const s = String(str || '');
				if (s.includes('"') || s.includes(',') || s.includes('\n') || s.includes('\r')) {
					return '"' + s.replace(/"/g, '""') + '"';
				}
				return s;
			};
			
			let csvLines = [headerCols.map(escapeCsv).join(',')];
			
			rows.forEach(row => {
				const cols = [...this.frequencyRowValuesArray(row), row.count || ''];
				if (hasPct) cols.push(this.frequencyRowPercent(row) || '');
				if (hasNormIpm) cols.push(this.frequencyRowNormalizedIpm(row) || '');
				if (hasSize) cols.push(this.frequencyRowSubcorpusSize(row) || '');
				if (hasRelSubIpm) cols.push(this.frequencyRowRelativeSubcorpusIpm(row) || '');
				csvLines.push(cols.map(escapeCsv).join(','));
			});
			
			if (this.frequencyHasRestBucket()) {
				const restCols = [];
				for (let i=0; i<fields.length; i++) restCols.push(i === 0 ? 'Others' : '');
				restCols.push(this.frequencyRestCount());
				if (hasPct) restCols.push(this.frequencyRestRowPercent());
				if (hasNormIpm) restCols.push(this.frequencyRestRowIpm());
				if (hasSize) restCols.push('');
				if (hasRelSubIpm) restCols.push('');
				csvLines.push(restCols.map(escapeCsv).join(','));
			}
			
			const csvContent = csvLines.join('\r\n');
			const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
			const url = URL.createObjectURL(blob);
			this.triggerDownload(url, `${this.frequencyChartExportBasename()}.csv`);
		},

		downloadFrequencyChartPrintForPdf() {
			const canvas = document.getElementById('flexicorp-freq-chart-canvas');
			if (!canvas || !canvas.getContext) return;
			const imgData = canvas.toDataURL('image/png', 1.0);
			const w = window.open('');
			if (!w) return;
			const title = 'Frequency chart — Save as PDF from print dialog';
			w.document.write(
				`<!DOCTYPE html><html><head><meta charset="utf-8"/><title>${title}</title></head><body style="margin:0;padding:16px;text-align:center;font-family:system-ui,sans-serif;"><p style="margin:0 0 12px 0;">Use <strong>Print</strong> (Ctrl+P / Cmd+P) and choose <strong>Save as PDF</strong>.</p><img src="${imgData}" alt="chart" style="max-width:100%;height:auto;border:1px solid #ddd"/></body></html>`
			);
			w.document.close();
		},

		loadJsPDF() {
			return new Promise((resolve, reject) => {
				if (typeof window !== 'undefined' && window.jspdf && window.jspdf.jsPDF) {
					return resolve(window.jspdf.jsPDF);
				}
				const existing = document.querySelector('script[data-flexicorp-jspdf]');
				if (existing) {
					existing.addEventListener('load', () => {
						if (window.jspdf && window.jspdf.jsPDF) resolve(window.jspdf.jsPDF);
						else reject(new Error('jspdf load failed'));
					});
					existing.addEventListener('error', () => reject(new Error('jspdf script error')));
					return;
				}
				const s = document.createElement('script');
				s.src = 'https://cdn.jsdelivr.net/npm/jspdf@2.5.2/dist/jspdf.umd.min.js';
				s.crossOrigin = 'anonymous';
				s.defer = true;
				s.setAttribute('data-flexicorp-jspdf', '1');
				s.onload = () => {
					if (window.jspdf && window.jspdf.jsPDF) resolve(window.jspdf.jsPDF);
					else reject(new Error('jspdf missing'));
				};
				s.onerror = () => reject(new Error('jspdf script failed'));
				document.head.appendChild(s);
			});
		},

		async downloadFrequencyChartPDF() {
			const canvas = document.getElementById('flexicorp-freq-chart-canvas');
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
				pdf.save(`${this.frequencyChartExportBasename()}.pdf`);
			} catch (_) {
				this.downloadFrequencyChartPrintForPdf();
			}
		},

		_resizeFrequencyChartAfterLayout() {
			const ch = this.frequencyChartInstance;
			if (!ch || typeof ch.resize !== 'function') return;
			const epoch = this._frequencyChartEpoch;
			requestAnimationFrame(() => {
				if (epoch !== this._frequencyChartEpoch || this.frequencyChartInstance !== ch) return;
				try {
					ch.resize();
				} catch (_) {
					/* ignore */
				}
			});
		},

		scheduleFrequencyChartRender() {
			if (!this.frequencyChartLibraryAvailable()) return;
			if (this.statsSubTab && this.statsSubTab !== 'freq') {
				this.destroyFrequencyChart();
				return;
			}
			if (this.frequencyVizMode === 'table' || this.frequencyVizMode === 'pivot') {
				this.destroyFrequencyChart();
				return;
			}
			// Double rAF: canvas wrapper often has 0 size until after Alpine/layout paints (avoids Chart.js draw on bad layout).
			const epoch = this._frequencyChartEpoch;
			requestAnimationFrame(() => {
				requestAnimationFrame(() => {
					if (epoch !== this._frequencyChartEpoch) return;
					if (!this.frequency || !this.frequency.ran || !this.frequency.rows || !this.frequency.rows.length) {
						this.destroyFrequencyChart();
						return;
					}
					if (this.statsSubTab && this.statsSubTab !== 'freq') return;
					if (this.frequencyVizMode === 'table' || this.frequencyVizMode === 'pivot') return;
					if (this.activeTab && this.activeTab !== 'frequency') return;
					this.renderFrequencyChart();
				});
			});
		},

		setFrequencyVizMode(mode) {
			const allowed = new Set(['table', 'bar', 'vbar', 'vbar-error', 'stacked-bar', 'stacked-vbar', 'line', 'radar', 'polar', 'pie', 'doughnut', 'pivot']);
			let next = allowed.has(mode) ? mode : 'table';
			if (this.frequencyHasCompareQueries() && (next === 'polar' || next === 'pie' || next === 'doughnut')) {
				next = 'bar';
			}
			if (next === 'vbar-error' && !this.frequencySupportsErrorBars()) {
				next = 'vbar';
			}
			if (next !== 'table' && next !== 'pivot' && !this.frequencyChartLibraryAvailable()) return;
			this.frequencyVizMode = next;
			if (next === 'table' || next === 'pivot') {
				this.destroyFrequencyChart();
			} else {
				this.scheduleFrequencyChartRender();
			}
		},

		onFrequencyStateApplied() {
			if (this.frequencyHasCompareQueries() && (this.frequencyVizMode === 'polar' || this.frequencyVizMode === 'pie' || this.frequencyVizMode === 'doughnut')) {
				this.frequencyVizMode = 'bar';
			}
			if (this.frequencyVizMode === 'vbar-error' && !this.frequencySupportsErrorBars()) {
				this.frequencyVizMode = 'vbar';
			}
			if (this.frequencySupportsRelativeScale()) {
				// Default to relative whenever the backend provides relative/subcorpus metrics,
				// unless absolute was chosen (by the user, or by a share link).
				if (!this._frequencyScaleChosen) this.frequencyChartValueScale = 'relative';
			} else if (this.frequencyChartValueScale === 'relative') {
				this.frequencyChartValueScale = 'absolute';
			}
			this.normalizeFrequencyCompareMetricCols();
			if (this.frequencyVizMode && this.frequencyVizMode !== 'table') {
				this.scheduleFrequencyChartRender();
			}
		},

		frequencyRows() {
			return Array.isArray(this.frequency && this.frequency.rows) ? this.frequency.rows : [];
		},

		setFrequencyTableSort(col) {
			if (this.frequencyTableSort.col === col) {
				this.frequencyTableSort.asc = !this.frequencyTableSort.asc;
			} else {
				this.frequencyTableSort.col = col;
				this.frequencyTableSort.asc = (col === 'value');
			}
		},

		frequencyCompareMetricOptions() {
			return [
				{ key: 'count', label: 'Raw', available: true },
				{ key: 'pct', label: '%', available: this.frequencyHasProvidedPct() },
				{ key: 'relf', label: 'RelFreq %', available: this.frequencyHasDerivedRelfreq() },
				{ key: 'nipm', label: 'Normalized IPM', available: this.frequencyHasNormalizedIpm() },
				{ key: 'ripm', label: 'Relative Subcorpus IPM', available: this.frequencyHasRelativeSubcorpusIpm() },
			];
		},

		normalizeFrequencyCompareMetricCols() {
			const available = this.frequencyCompareMetricOptions().filter((o) => o.available).map((o) => o.key);
			if (!available.length) {
				this.frequencyCompareMetricCols = ['count'];
				return;
			}
			const cur = Array.isArray(this.frequencyCompareMetricCols) ? this.frequencyCompareMetricCols : [];
			let next = cur.filter((k) => available.includes(k));
			if (!next.length) {
				next = available.includes('pct') ? ['pct'] : (available.includes('relf') ? ['relf'] : [available[0]]);
			}
			this.frequencyCompareMetricCols = next;
		},

		isFrequencyCompareMetricSelected(key) {
			const k = String(key || '');
			return Array.isArray(this.frequencyCompareMetricCols) && this.frequencyCompareMetricCols.includes(k);
		},

		onFrequencyCompareMetricToggle(key, checked) {
			const k = String(key || '');
			if (!k) return;
			const cur = Array.isArray(this.frequencyCompareMetricCols) ? [...this.frequencyCompareMetricCols] : [];
			const has = cur.includes(k);
			if (checked && !has) cur.push(k);
			if (!checked && has) {
				if (cur.length <= 1) return;
				cur.splice(cur.indexOf(k), 1);
			}
			this.frequencyCompareMetricCols = cur;
		},

		frequencyCompareMetricVisible(key) {
			if (!this.frequencyHasCompareQueries()) return false;
			const k = String(key || '');
			const available = this.frequencyCompareMetricOptions().some((o) => o.key === k && o.available);
			return available && this.isFrequencyCompareMetricSelected(k);
		},

		frequencyHasCompareQueries() {
			const r = this.frequencyResult();
			return Array.isArray(r.compare_queries) && r.compare_queries.length > 0;
		},

		frequencyCompareQueries() {
			return this.frequencyHasCompareQueries() ? this.frequencyResult().compare_queries : [];
		},

		frequencyRowQueryCount(row, q) {
			if (!row || !row.queries || !row.queries[q]) return 0;
			return Number(row.queries[q].count) || 0;
		},

		frequencyRowQueryPct(row, q) {
			if (!row || !row.queries || !row.queries[q]) return '';
			const n = Number(row.queries[q].pct);
			return Number.isFinite(n) ? n.toFixed(2) : '';
		},

		frequencyRowQueryIpm(row, q) {
			if (!row || !row.queries || !row.queries[q]) return '';
			const cell = row.queries[q] || {};
			const n = this.frequencyRowQueryIpmNumber(row, q);
			const c = Number(cell.count);
			return this.frequencyFormatIpmDisplay(n, { count: Number.isFinite(c) && c >= 0 ? c : null });
		},

		frequencyRowQueryIpmNumber(row, q) {
			if (!row || !row.queries || !row.queries[q]) return null;
			const n = Number(row.queries[q].ipm);
			return Number.isFinite(n) ? n : null;
		},

		frequencyRowQueryNormalizedIpm(row, q) {
			if (!row || !row.queries || !row.queries[q]) return '';
			const ct = this.frequencyCorpusTokensForIpm();
			const c = Number(row.queries[q].count);
			const n = this.frequencyRowQueryNormalizedIpmNumber(row, q);
			if (Number.isFinite(n)) return this.frequencyFormatIpmDisplay(n, { count: Number.isFinite(c) && c >= 0 ? c : null, base: ct });
			return '';
		},

		frequencyRowQueryNormalizedIpmNumber(row, q) {
			if (!row || !row.queries || !row.queries[q]) return null;
			const ct = this.frequencyCorpusTokensForIpm();
			const c = Number(row.queries[q].count);
			if (ct !== null && ct > 0 && Number.isFinite(c) && c >= 0) return (c / ct) * 1000000;
			return null;
		},

		frequencyRowQueryRelativeSubcorpusIpm(row, q) {
			if (!row || !row.queries || !row.queries[q]) return '';
			const cell = row.queries[q] || {};
			const n = this.frequencyRowQueryRelativeSubcorpusIpmNumber(row, q);
			const c = Number(cell.count);
			const baseRaw = cell.subcorpus_size ?? cell.q_size ?? cell.query_size ?? null;
			const base = Number(baseRaw);
			return this.frequencyFormatIpmDisplay(n, {
				count: Number.isFinite(c) && c >= 0 ? c : null,
				base: Number.isFinite(base) && base > 0 ? base : null,
			});
		},

		frequencyRowQueryRelativeSubcorpusIpmNumber(row, q) {
			if (!row || !row.queries || !row.queries[q]) return null;
			const cell = row.queries[q] || {};
			const raw = cell.q_ipm ?? cell.subcorpus_ipm ?? cell.relative_subcorpus_ipm ?? cell.ipm;
			const n = Number(raw);
			return Number.isFinite(n) ? n : null;
		},

		frequencyCompareTotalsPerQuery() {
			const r = this.frequencyResult();
			if (!r || typeof r !== 'object') return {};
			const src = r.totals_per_query && typeof r.totals_per_query === 'object'
				? r.totals_per_query
				: (r.table && r.table.totals_per_query && typeof r.table.totals_per_query === 'object'
					? r.table.totals_per_query
					: {});
			const out = {};
			Object.keys(src).forEach((k) => {
				const n = Number(src[k]);
				if (Number.isFinite(n) && n > 0) out[String(k)] = n;
			});
			return out;
		},

		frequencyHasDerivedRelfreq() {
			if (!this.frequencyHasCompareQueries()) return false;
			const totals = this.frequencyCompareTotalsPerQuery();
			const qs = this.frequencyCompareQueries();
			if (!qs.some((q) => Number(totals[q]) > 0)) return false;
			return this.frequencyMetricSampleRows().some((row) => {
				if (!row || !row.queries || typeof row.queries !== 'object') return false;
				return qs.some((q) => {
					const c = row.queries[q] && row.queries[q].count;
					return c !== null && c !== undefined && c !== '' && Number.isFinite(Number(c));
				});
			});
		},

		frequencyRowQueryDerivedRelfreqNumber(row, q) {
			if (!row || !row.queries || !row.queries[q]) return null;
			const c = Number(row.queries[q].count);
			const totals = this.frequencyCompareTotalsPerQuery();
			const base = Number(totals[q]);
			if (!Number.isFinite(c) || c < 0 || !Number.isFinite(base) || base <= 0) return null;
			return (c / base) * 100;
		},

		frequencyRowQueryDerivedRelfreq(row, q) {
			const n = this.frequencyRowQueryDerivedRelfreqNumber(row, q);
			return n === null ? '' : n.toFixed(2);
		},

		frequencyRowQueryRelativeNumber(row, q) {
			const cell = row && row.queries && row.queries[q] ? row.queries[q] : null;
			if (!cell) return 0;
			let v = Number(cell.q_ipm ?? cell.subcorpus_ipm ?? cell.relative_subcorpus_ipm);
			if (Number.isFinite(v)) return v;
			v = Number(cell.ipm);
			if (Number.isFinite(v)) return v;
			v = Number(cell.pct);
			if (Number.isFinite(v)) return v;
			const relf = this.frequencyRowQueryDerivedRelfreqNumber(row, q);
			if (relf !== null) return relf;
			return 0;
		},

		frequencyDisplayRows() {
			let rows = this.frequencyRows();
			const q = (this.frequencyTableSearch || '').trim().toLowerCase();
			if (q) {
				rows = rows.filter(r => this.frequencyRowRawValue(r).toLowerCase().includes(q));
			}
			const sortCol = this.frequencyTableSort.col;
			if (sortCol) {
				const asc = this.frequencyTableSort.asc ? 1 : -1;
				rows = [...rows].sort((a, b) => {
					if (sortCol === 'value') {
						const cmp = this.frequencyCompareValueLabels(this.frequencyRowRawValue(a), this.frequencyRowRawValue(b));
						return cmp * asc;
					}
					let va = 0, vb = 0;
					if (sortCol === 'count') {
						va = this.frequencyRowCount(a);
						vb = this.frequencyRowCount(b);
					} else if (sortCol === 'pct') {
						va = this.frequencyRowPctNumber(a);
						vb = this.frequencyRowPctNumber(b);
					} else if (sortCol === 'ipm') {
						va = this.frequencyRowIpmNumber(a);
						vb = this.frequencyRowIpmNumber(b);
					} else if (sortCol === 'nipm') {
						va = this.frequencyRowNormalizedIpmNumber(a);
						vb = this.frequencyRowNormalizedIpmNumber(b);
					} else if (sortCol === 'ripm') {
						va = this.frequencyRowRelativeSubcorpusIpmNumber(a);
						vb = this.frequencyRowRelativeSubcorpusIpmNumber(b);
					} else if (sortCol.startsWith('count_')) {
						const qname = sortCol.substring(6);
						va = this.frequencyRowQueryCount(a, qname);
						vb = this.frequencyRowQueryCount(b, qname);
					} else if (sortCol.startsWith('pct_')) {
						const qname = sortCol.substring(4);
						va = Number(this.frequencyRowQueryPct(a, qname)) || 0;
						vb = Number(this.frequencyRowQueryPct(b, qname)) || 0;
					} else if (sortCol.startsWith('relf_')) {
						const qname = sortCol.substring(5);
						va = Number(this.frequencyRowQueryDerivedRelfreqNumber(a, qname)) || 0;
						vb = Number(this.frequencyRowQueryDerivedRelfreqNumber(b, qname)) || 0;
					} else if (sortCol.startsWith('ipm_')) {
						const qname = sortCol.substring(4);
						va = Number(this.frequencyRowQueryIpmNumber(a, qname)) || 0;
						vb = Number(this.frequencyRowQueryIpmNumber(b, qname)) || 0;
					} else if (sortCol.startsWith('nipm_')) {
						const qname = sortCol.substring(5);
						va = Number(this.frequencyRowQueryNormalizedIpmNumber(a, qname)) || 0;
						vb = Number(this.frequencyRowQueryNormalizedIpmNumber(b, qname)) || 0;
					} else if (sortCol.startsWith('ripm_')) {
						const qname = sortCol.substring(5);
						va = Number(this.frequencyRowQueryRelativeSubcorpusIpmNumber(a, qname)) || 0;
						vb = Number(this.frequencyRowQueryRelativeSubcorpusIpmNumber(b, qname)) || 0;
					}
					return (va - vb) * asc;
				});
			}
			return rows;
		},

		frequencyPivotData() {
			const rows = this.frequencyDisplayRows();
			const rowKeys = new Set();
			const colKeys = new Set();
			const cells = {};
			let maxVal = 0;

			if (this.frequencyHasCompareQueries()) {
				const queries = this.frequencyCompareQueries();
				queries.forEach(q => colKeys.add(q));
				rows.forEach(r => {
					const rowName = this.frequencyRowValue(r) || '(empty)';
					rowKeys.add(rowName);
					if (!cells[rowName]) cells[rowName] = {};
					queries.forEach(q => {
						let val = 0;
						if (r.queries && r.queries[q]) {
							if (this.frequencyChartValueScale === 'relative') {
								val = this.frequencyRowQueryRelativeNumber(r, q);
							} else {
								val = Number(r.queries[q].count) || 0;
							}
						}
						cells[rowName][q] = val;
						if (val > maxVal) maxVal = val;
					});
				});
			} else {
				rows.forEach(r => {
					const parts = this.frequencyRowValuesArray(r);
					const rowName = parts[0] || '(empty)';
					const colName = parts.slice(1).join(' · ') || '(empty)';
					rowKeys.add(rowName);
					colKeys.add(colName);
					
					const val = this.frequencyRowChartNumeric(r);
					if (!cells[rowName]) cells[rowName] = {};
					cells[rowName][colName] = val;
					if (val > maxVal) maxVal = val;
				});
			}
			
			return {
				rows: Array.from(rowKeys).sort(),
				cols: Array.from(colKeys).sort(),
				cells,
				maxVal
			};
		},

		frequencyPivotColumns() {
			return this.frequencyPivotData().cols;
		},

		frequencyPivotRows() {
			return this.frequencyPivotData().rows;
		},

		frequencyPivotCellValue(r, c) {
			const data = this.frequencyPivotData();
			const val = data.cells[r] && data.cells[r][c];
			if (val == null) return '';
			
			// Format depending on scale
			if (this.frequencyChartValueScale === 'absolute') return Math.round(val);
			// Relative scale can be IPM or percentage; prefer shared IPM precision formatter.
			if (this.frequencyUsesSubcorpusIpm() || this.frequencyHasBackendProvidedIpm()) {
				return this.frequencyFormatIpmDisplay(val);
			}
			return Number.isInteger(val) ? val : Number(val).toFixed(2);
		},

		frequencyPivotCellStyle(r, c) {
			const data = this.frequencyPivotData();
			const val = data.cells[r] && data.cells[r][c];
			if (!val || data.maxVal <= 0) return '';
			
			// Simple heatmap calculation (white to blue)
			const intensity = Math.min(1, Math.max(0, val / data.maxVal));
			// Use an RGBA blue background
			return `background-color: rgba(0, 123, 255, ${intensity * 0.7}); color: ${intensity > 0.5 ? '#fff' : 'inherit'};`;
		},

		frequencyMetricSampleRows() {
			const f = this.frequency;
			if (!f) return [];
			if (Array.isArray(f.rows) && f.rows.length) return f.rows;
			const rr = f.response && f.response.result;
			if (rr && rr.table && Array.isArray(rr.table.rows) && rr.table.rows.length) return rr.table.rows;
			if (rr && Array.isArray(rr.rows) && rr.rows.length) return rr.rows;
			if (rr && Array.isArray(rr.items) && rr.items.length) return rr.items;
			return [];
		},

		frequencyResult() {
			return this.frequency && this.frequency.response && this.frequency.response.result && typeof this.frequency.response.result === 'object'
				? this.frequency.response.result
				: {};
		},

		frequencyRowRawValue(row) {
			if (!row || typeof row !== 'object') return '';
			const pick = (v) => {
				if (v === null || v === undefined) return '';
				const s = String(v);
				return s.trim() === '' ? '' : s;
			};
			return pick(row.value) || pick(row.label) || pick(row.key) || '';
		},

		frequencyRowValue(row) {
			const val = this.frequencyRowRawValue(row);
			return val.replace(/\t/g, ' · ');
		},

		frequencyFieldGuessLabel(k) {
			const s = String(k);
			const expr = s.match(/^\s*([a-z_][a-z0-9_]*)\s*\((.+)\)\s*$/i);
			if (expr) {
				const fn = String(expr[1] || '').toLowerCase();
				if (fn === 'decade') return 'Decade';
				if (fn === 'century') return 'Century';
				return fn ? fn.charAt(0).toUpperCase() + fn.slice(1) : s;
			}
			if (/(_|^)century$/i.test(s)) return 'Century';
			if (/(_|^)year$/i.test(s)) return 'Year';
			if (/(_|^)decade$/i.test(s)) return 'Decade';
			if (s.indexOf('_') < 0) return s;
			const parts = s.split('_');
			return parts[0] + ' · ' + parts.slice(1).join(' · ');
		},

		frequencyFieldPrettyLabel(key, labels = null) {
			const map = labels && typeof labels === 'object' ? labels : {};
			const k = String(key || '').trim();
			if (!k) return '';
			const fromMap = map[k];
			if (fromMap != null && String(fromMap).trim() !== '') return String(fromMap).trim();
			// Canonicalize known derived / temporal field names first.
			const guessed = this.frequencyFieldGuessLabel(k);
			if (guessed && guessed !== k) return guessed;
			return k;
		},

		frequencyFieldsArray() {
			const f = this.frequency;
			const labels = (f && f.fieldLabels && typeof f.fieldLabels === 'object') ? f.fieldLabels : {};
			if (f && Array.isArray(f.fields) && f.fields.length > 0) {
				return f.fields.map((k) => this.frequencyFieldPrettyLabel(k, labels));
			}
			const single = f && f.field ? f.field : 'Value';
			return [single === 'Value' ? single : this.frequencyFieldPrettyLabel(single, labels)];
		},

		frequencyRowValuesArray(row) {
			const val = this.frequencyRowRawValue(row);
			const parts = val.split('\t');
			const len = this.frequencyFieldsArray().length;
			while (parts.length < len) parts.push('');
			return parts.slice(0, len);
		},

		frequencyRowCount(row) {
			if (!row || typeof row !== 'object') return 0;
			const raw = row.count ?? row.freq ?? row.n ?? 0;
			const n = Number(raw);
			return Number.isFinite(n) && n > 0 ? n : 0;
		},

		frequencyRowsTotal() {
			return this.frequencyRows().reduce((sum, row) => sum + this.frequencyRowCount(row), 0);
		},

		/** Grand total for the distribution (matches tt_flexicorp_freq_total + nested result fields). */
		frequencyEffectiveTotal() {
			const tryNum = (v) => {
				const n = Number(v);
				return Number.isFinite(n) && n > 0 ? n : null;
			};
			const f = this.frequency;
			let t = tryNum(f && f.total);
			if (t !== null) return t;
			const r = f && f.response && f.response.result;
			if (!r || typeof r !== 'object') return null;
			const keys = [
				'total',
				'total_matches',
				'total_count',
				'n_total',
				'match_total',
				'hits_total',
				'total_tokens',
				'token_total',
				'freq_total',
				'sum',
				'sum_count',
			];
			for (let i = 0; i < keys.length; i++) {
				t = tryNum(r[keys[i]]);
				if (t !== null) return t;
			}
			if (r.table && typeof r.table === 'object') {
				for (let i = 0; i < keys.length; i++) {
					t = tryNum(r.table[keys[i]]);
					if (t !== null) return t;
				}
			}
			return null;
		},

		frequencyRelativeBase() {
			const total = this.frequencyEffectiveTotal();
			if (total !== null && total > 0) return total;
			const rowsTotal = this.frequencyRowsTotal();
			if (rowsTotal > 0) return rowsTotal;
			return null;
		},

		/** Corpus token count for IPM when backend omits per-row ipm (aligned with flexicorp.js + flexicorp.php augment). */
		frequencyCorpusTokensForIpm() {
			const r = this.frequency && this.frequency.response && this.frequency.response.result;
			const tryNum = (obj, keys) => {
				if (!obj || typeof obj !== 'object') return null;
				for (let i = 0; i < keys.length; i++) {
					const k = keys[i];
					if (obj[k] == null || obj[k] === '') continue;
					const n = Number(obj[k]);
					if (Number.isFinite(n) && n > 0) return n;
				}
				return null;
			};
			let n = tryNum(r, ['corpus_tokens', 'tokens_count', 'corpus_size', 'size', 'tokens', 'n_tokens']);
			if (n !== null) return n;
			if (r && r.info && typeof r.info === 'object') {
				n = tryNum(r.info, ['corpus_tokens', 'tokens_count', 'corpus_size', 'size', 'tokens', 'n_tokens']);
				if (n !== null) return n;
			}
			const infoResult = this.info && this.info.result && typeof this.info.result === 'object' ? this.info.result : null;
			if (infoResult) {
				n = tryNum(infoResult, ['tokens_count', 'corpus_size', 'size', 'tokens', 'n_tokens', 'corpus_tokens']);
				if (n !== null) return n;
			}
			return null;
		},

		frequencyRestCount() {
			const total = this.frequencyEffectiveTotal();
			if (total === null || total <= 0) return 0;
			const shown = this.frequencyRowsTotal();
			if (!Number.isFinite(shown) || shown < 0) return 0;
			const rest = total - shown;
			if (rest <= 0) return 0;
			return Math.max(0, Math.round(rest));
		},

		frequencyHasRestBucket() {
			return this.frequencyRestCount() > 0;
		},

		frequencyRestRowPercent() {
			const rest = this.frequencyRestCount();
			if (rest <= 0) return '';
			const total = this.frequencyEffectiveTotal();
			if (total !== null && total > 0) {
				return ((rest / total) * 100).toFixed(2);
			}
			return '';
		},

		frequencyRestRowIpm() {
			const rest = this.frequencyRestCount();
			if (rest <= 0) return '';
			const ct = this.frequencyCorpusTokensForIpm();
			if (ct !== null && ct > 0) {
				return ((rest / ct) * 1000000).toFixed(2);
			}
			return '';
		},

		isSattributeFrequencyField() {
			const field = String((this.frequency && this.frequency.field) || '').trim();
			return field.includes('_');
		},

		frequencyHasProvidedPct() {
			if (this.frequencyHasCompareQueries()) {
				const queries = this.frequencyCompareQueries();
				return queries.some(q => {
					return this.frequencyMetricSampleRows().some(row => {
						const v = row && row.queries && row.queries[q] && (row.queries[q].pct ?? row.queries[q].percent ?? row.queries[q].percentage);
						return v !== null && v !== undefined && v !== '' && Number.isFinite(Number(v));
					});
				});
			}

			const rows = this.frequencyMetricSampleRows();
			if (!rows.length) return false;
			if (
				rows.some((row) => {
					const v = row && (row.pct ?? row.percent ?? row.percentage);
					if (v === null || v === undefined || v === '') return false;
					return Number.isFinite(Number(v));
				})
			) {
				return true;
			}
			const base = this.frequencyRelativeBase();
			if (base === null || base <= 0) return false;
			return rows.some((row) => {
				const c = row && (row.count ?? row.freq ?? row.n);
				if (c == null || c === '') return false;
				return Number.isFinite(Number(c)) && Number(c) >= 0;
			});
		},

		frequencyHasBackendProvidedPct() {
			if (this.frequencyHasCompareQueries()) {
				const queries = this.frequencyCompareQueries();
				return queries.some(q => this.frequencyMetricSampleRows().some(row => {
					const v = row && row.queries && row.queries[q] && (row.queries[q].pct ?? row.queries[q].percent ?? row.queries[q].percentage);
					return v !== null && v !== undefined && v !== '' && Number.isFinite(Number(v));
				}));
			}
			return this.frequencyMetricSampleRows().some((row) => {
				const v = row && (row.pct ?? row.percent ?? row.percentage);
				return v !== null && v !== undefined && v !== '' && Number.isFinite(Number(v));
			});
		},

		frequencyRowPercent(row) {
			const raw = row && (row.pct ?? row.percent ?? row.percentage);
			let n = Number(raw);
			if (Number.isFinite(n)) return n.toFixed(2);
			const base = this.frequencyRelativeBase();
			const count = row && (row.count ?? row.freq ?? row.n);
			const c = Number(count);
			if (base !== null && base > 0 && Number.isFinite(c) && c >= 0) {
				return ((c / base) * 100).toFixed(2);
			}
			return '';
		},

		frequencyHasProvidedIpm() {
			if (this.frequencyHasCompareQueries()) {
				const queries = this.frequencyCompareQueries();
				return queries.some(q => {
					return this.frequencyMetricSampleRows().some(row => {
						const v = row && row.queries && row.queries[q] && (row.queries[q].q_ipm ?? row.queries[q].subcorpus_ipm ?? row.queries[q].relative_subcorpus_ipm ?? row.queries[q].ipm ?? row.queries[q].IPM ?? row.queries[q].relfreq ?? row.queries[q].relative ?? row.queries[q].relative_ipm);
						if (v === null || v === undefined || v === '') return false;
						const n = typeof v === 'number' ? v : Number(String(v).replace(/,/g, ''));
						return Number.isFinite(n);
					});
				});
			}

			const rows = this.frequencyMetricSampleRows();
			if (!rows.length) return false;
			if (
				rows.some((row) => {
					const v = row && (row.ipm ?? row.IPM ?? row.relfreq ?? row.relative ?? row.relative_ipm);
					if (v === null || v === undefined || v === '') return false;
					const n = typeof v === 'number' ? v : Number(String(v).replace(/,/g, ''));
					return Number.isFinite(n);
				})
			) {
				return true;
			}
			const ct = this.frequencyCorpusTokensForIpm();
			if (ct === null || ct <= 0) return false;
			return rows.some((row) => {
				const c = row && (row.count ?? row.freq ?? row.n);
				if (c == null || c === '') return false;
				return Number.isFinite(Number(c)) && Number(c) >= 0;
			});
		},

		frequencyHasNormalizedIpm() {
			const ct = this.frequencyCorpusTokensForIpm();
			if (ct === null || ct <= 0) return false;
			if (this.frequencyHasCompareQueries()) {
				const queries = this.frequencyCompareQueries();
				return this.frequencyMetricSampleRows().some((row) => {
					if (!row || !row.queries || typeof row.queries !== 'object') return false;
					return queries.some((q) => {
						const c = row.queries[q] && row.queries[q].count;
						return c !== null && c !== undefined && c !== '' && Number.isFinite(Number(c));
					});
				});
			}
			return this.frequencyMetricSampleRows().some((row) => {
				const c = row && (row.count ?? row.freq ?? row.n);
				return c !== null && c !== undefined && c !== '' && Number.isFinite(Number(c));
			});
		},

		frequencyHasRelativeSubcorpusIpm() {
			if (this.frequencyHasCompareQueries()) {
				const queries = this.frequencyCompareQueries();
				return this.frequencyMetricSampleRows().some((row) => {
					if (!row || !row.queries || typeof row.queries !== 'object') return false;
					return queries.some((q) => {
						const cell = row.queries[q];
						if (!cell || typeof cell !== 'object') return false;
						const v = cell.q_ipm ?? cell.subcorpus_ipm ?? cell.relative_subcorpus_ipm;
						return v !== null && v !== undefined && v !== '' && Number.isFinite(Number(v));
					});
				});
			}
			if (!this.frequencyUsesSubcorpusIpm()) return false;
			if (!this.frequencyHasBackendProvidedIpm()) return false;
			return this.frequencyHasProvidedSubcorpusSize();
		},

		frequencyHasBackendProvidedIpm() {
			if (this.frequencyHasCompareQueries()) {
				const queries = this.frequencyCompareQueries();
				return queries.some(q => this.frequencyMetricSampleRows().some(row => {
					const v = row && row.queries && row.queries[q] && (row.queries[q].q_ipm ?? row.queries[q].subcorpus_ipm ?? row.queries[q].relative_subcorpus_ipm ?? row.queries[q].ipm ?? row.queries[q].IPM ?? row.queries[q].relfreq ?? row.queries[q].relative ?? row.queries[q].relative_ipm);
					if (v === null || v === undefined || v === '') return false;
					const n = typeof v === 'number' ? v : Number(String(v).replace(/,/g, ''));
					return Number.isFinite(n);
				}));
			}
			return this.frequencyMetricSampleRows().some((row) => {
				const v = row && (row.ipm ?? row.IPM ?? row.relfreq ?? row.relative ?? row.relative_ipm);
				if (v === null || v === undefined || v === '') return false;
				const n = typeof v === 'number' ? v : Number(String(v).replace(/,/g, ''));
				return Number.isFinite(n);
			});
		},

		frequencySupportsRelativeScale() {
			return this.frequencyHasBackendProvidedPct() || this.frequencyHasBackendProvidedIpm() || this.frequencyHasDerivedRelfreq();
		},

		frequencyRowIpm(row) {
			if (!row || typeof row !== 'object') return '';
			const raw = row.ipm ?? row.IPM ?? row.relfreq ?? row.relative ?? row.relative_ipm;
			const c = Number(row.count ?? row.freq ?? row.n);
			if (raw !== null && raw !== undefined && raw !== '') {
				const n = typeof raw === 'number' ? raw : Number(String(raw).replace(/,/g, ''));
				if (Number.isFinite(n)) {
					return this.frequencyFormatIpmDisplay(n, { count: Number.isFinite(c) && c >= 0 ? c : null });
				}
			}
			const ct = this.frequencyCorpusTokensForIpm();
			if (ct !== null && ct > 0 && Number.isFinite(c) && c >= 0) {
				return this.frequencyFormatIpmDisplay((c / ct) * 1000000, { count: c, base: ct });
			}
			return '';
		},

		frequencyFormatIpmDisplay(value, opts = null) {
			if (typeof this.formatIpmDisplay === 'function') {
				return this.formatIpmDisplay(value, opts);
			}
			const fns = typeof window !== 'undefined' && window.ttFlexicorpFns && typeof window.ttFlexicorpFns === 'object'
				? window.ttFlexicorpFns
				: {};
			if (typeof fns.formatIpmDisplay === 'function') {
				return fns.formatIpmDisplay(value, opts);
			}
			const n = Number(value);
			if (!Number.isFinite(n)) return '';
			if (Math.abs(n) >= 100) return String(Math.round(n));
			return String(Number(n.toFixed(Math.abs(n) >= 10 ? 1 : 2)));
		},

		frequencyRowNormalizedIpm(row) {
			const n = this.frequencyRowNormalizedIpmNumber(row);
			return n === null ? '' : this.frequencyFormatIpmDisplay(n);
		},

		frequencyRowRelativeSubcorpusIpm(row) {
			const n = this.frequencyRowRelativeSubcorpusIpmNumber(row);
			return n === null ? '' : this.frequencyFormatIpmDisplay(n);
		},

		frequencyUsesSubcorpusIpm() {
			const r = this.frequencyResult();
			return !!(r && r.per_subcorpus_ipm);
		},

		frequencyHasProvidedSubcorpusSize() {
			return this.frequencyMetricSampleRows().some((row) => {
				const v = row && row.subcorpus_size;
				if (v === null || v === undefined || v === '') return false;
				return Number.isFinite(Number(v));
			});
		},

		frequencyRowSubcorpusSize(row) {
			const n = Number(row && row.subcorpus_size);
			if (!Number.isFinite(n)) return '';
			return String(Math.round(n));
		},

		submitFrequency(event) {
			if (typeof this.statsSearchScopePersistToSearch === 'function') this.statsSearchScopePersistToSearch();
			const form = this.getEventForm(event);
			if (!form) return;
			const fd = this.buildCommonRequestData();
			const fe = new FormData(form);
			for (const pair of fe.entries()) {
				const k = pair[0];
				const v = pair[1];
				if (k === 'freq_field[]') {
					fd.append(k, v);
					continue;
				}
				fd.set(k, v);
			}
			fd.set('active_tab', 'frequency');
			fd.set('run', 'freq');
			const qo = typeof this.statsOutgoingQueryForFreqSubmit === 'function' ? this.statsOutgoingQueryForFreqSubmit() : '';
			if (qo) fd.set('query', qo);
			this.submitAjaxData(fd, 'frequency');
		},

		submitFrequencyFromButton(element) {
			if (typeof this.statsSearchScopePersistToSearch === 'function') this.statsSearchScopePersistToSearch();
			const formData = this.buildCommonRequestData();
			const qo = typeof this.statsOutgoingQueryForFreqSubmit === 'function' ? this.statsOutgoingQueryForFreqSubmit() : '';
			if (qo) formData.set('query', qo);
			formData.set('active_tab', 'frequency');
			formData.set('run', 'freq');
			this.submitAjaxData(formData, 'frequency');
		},

		canShowMoreFrequency() {
			if (!this.frequency || !this.frequency.ran || this.callHasErrors(this.frequency.response)) return false;
			const rows = Array.isArray(this.frequency.rows) ? this.frequency.rows : [];
			const total =
				typeof this.frequencyEffectiveTotal === 'function'
					? this.frequencyEffectiveTotal()
					: (Number.isFinite(this.frequency.total) ? Number(this.frequency.total) : null);
			if (Number.isFinite(total) && total !== null) return rows.length < Number(total);
			const returnedNum = Number(this.frequency.returned);
			if (Number.isFinite(returnedNum) && returnedNum >= 0 && rows.length >= returnedNum) return false;
			const limit = this.normalizePositiveInt(this.frequency.limit, 100);
			return rows.length >= limit;
		},

		showMoreFrequency() {
			const rows = Array.isArray(this.frequency && this.frequency.rows) ? this.frequency.rows : [];
			const step = this.normalizePositiveInt(this.frequency && this.frequency.limit, 100);
			this.frequency.limit = rows.length + step;
			if (typeof this.statsSearchScopePersistToSearch === 'function') this.statsSearchScopePersistToSearch();
			const formData = this.buildCommonRequestData();
			const qo = typeof this.statsOutgoingQueryForFreqSubmit === 'function' ? this.statsOutgoingQueryForFreqSubmit() : '';
			if (qo) formData.set('query', qo);
			formData.set('active_tab', 'frequency');
			formData.set('run', 'freq');
			this.submitAjaxData(formData, 'frequency');
		},

		/** Summary line under frequency-distribution results (field / returned / total). */
		frequencyDistributionMetaLine() {
			const f = this.frequency;
			if (!f) return '';
			const tot =
				f.total !== null && f.total !== undefined && f.total !== '' ? f.total : this.frequencyEffectiveTotal();
			
			const labels = f.fieldLabels && typeof f.fieldLabels === 'object' ? f.fieldLabels : {};
			const rawFields = Array.isArray(f.fields) && f.fields.length ? f.fields : (f.field ? String(f.field).split(/\s*,\s*/) : []);
			const fieldPretty = rawFields
				.map((k) => this.frequencyFieldPrettyLabel(k, labels))
				.filter(Boolean)
				.join(', ');
			let metaStr = 'field=' + (fieldPretty || '');
			if (f.returned !== null && f.returned !== undefined) {
				metaStr += ' · returned ' + f.returned;
			}
			
			if (tot !== null && tot !== undefined && tot !== '') {
				metaStr += ' · total ' + tot;
			} else if (this.frequencyHasCompareQueries && this.frequencyHasCompareQueries()) {
				const r = this.frequencyResult();
				if (r && r.totals_per_query) {
					const parts = [];
					for (const q of this.frequencyCompareQueries()) {
						if (r.totals_per_query[q] != null) {
							parts.push(`${q}: ${Number(r.totals_per_query[q]).toLocaleString()}`);
						}
					}
					if (parts.length) {
						metaStr += ' · totals: ' + parts.join(', ');
					}
				}
			}

			return metaStr;
		},
	};
};
