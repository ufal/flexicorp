/*
 * Advanced dependency-collocation module for flexicorp Stats host.
 */
(function () {
	function installAdvancedDcoll(app, registerModule) {
		if (!app || typeof app !== 'object') return;
		if (typeof registerModule === 'function') {
			registerModule({
				id: 'advanced_dcoll',
				label: 'Dependencies',
				description: 'Dependency collocations (dcoll) for dependency-enabled Pando corpora.',
				ingestsOperations: ['dcoll'],
				isAvailable: (ctx) => {
					const b = String((ctx && ctx.backend) || '').trim().toLowerCase();
					if (!(b === 'pando' || b === 'flexicorp-pando')) return false;
					return !!(ctx && ctx.hasDeps);
				},
			});
		}

		if (!app.dcollAdv) {
			app.dcollAdv = {
				ran: false,
				anchorToken: '',
				relation: 'children',
				field: 'lemma',
				/** second `by` attribute tallied per collocate (`dcoll … by lemma, deprel`); 'none' = off */
				breakdown: 'deprel',
				minFreq: 1,
				maxItems: 50,
				stoplist: 0,
				measureKeys: ['logdice'],
				rows: [],
				response: null,
				raw: '',
			};
		}
		if (!app.dcollAdvVizMode) app.dcollAdvVizMode = 'table';
		if (!app.dcollAdvChartMetricKey) app.dcollAdvChartMetricKey = '';
		if (!app.dcollAdvChartInstance) app.dcollAdvChartInstance = null;
		if (!app._dcollAdvChartEpoch) app._dcollAdvChartEpoch = 0;
		if (!app.dcollAdvLoading) app.dcollAdvLoading = false;
		if (!app.dcollAdvError) app.dcollAdvError = '';

		Object.assign(app, {
			dcollAdvRelationOptions() {
				const base = [
					{ key: 'children', label: 'children (all direct children)' },
					{ key: 'head', label: 'head (governor)' },
					{ key: 'descendants', label: 'descendants (full subtree)' },
				];
				const ud = [
					'acl', 'advcl', 'advmod', 'amod', 'appos', 'aux', 'case', 'cc', 'ccomp',
					'clf', 'compound', 'conj', 'cop', 'csubj', 'dep', 'det', 'discourse',
					'dislocated', 'expl', 'fixed', 'flat', 'goeswith', 'iobj', 'list', 'mark',
					'nmod', 'nsubj', 'nummod', 'obj', 'obl', 'orphan', 'parataxis', 'punct',
					'reparandum', 'root', 'vocative', 'xcomp',
				];
				const out = base.slice();
				for (let i = 0; i < ud.length; i += 1) {
					const k = ud[i];
					out.push({ key: k, label: k });
				}
				const cur = String((this.dcollAdv && this.dcollAdv.relation) || '').trim();
				if (cur && !out.some((o) => o.key === cur)) out.push({ key: cur, label: cur });
				return out;
			},

			dcollAdvFieldOptions() {
				const out = [];
				const seen = new Set();
				const pushOpt = (key, label) => {
					const k = String(key || '').trim();
					if (!k) return;
					const low = k.toLowerCase();
					if (seen.has(low)) return;
					seen.add(low);
					out.push({ key: k, label: String(label || k) });
				};
				const fns = (typeof window !== 'undefined' && window.ttFlexicorpFns && typeof window.ttFlexicorpFns === 'object')
					? window.ttFlexicorpFns
					: {};
				const fl = fns && typeof fns.frequencySelectableFieldLabelMap === 'function'
					? fns.frequencySelectableFieldLabelMap(this)
					: (this.frequency && this.frequency.fieldLabels && typeof this.frequency.fieldLabels === 'object'
						? this.frequency.fieldLabels
						: null);
				if (fl) {
					const keys = Object.keys(fl);
					for (let i = 0; i < keys.length; i += 1) pushOpt(keys[i], fl[keys[i]]);
				}
				if (!out.length) pushOpt('lemma', 'Lemma');
				return out;
			},

			/** "Show also" choices: none, deprel and upos first, then the other fields. */
			dcollAdvBreakdownOptions() {
				const out = [{ key: 'none', label: '(nothing)' }];
				const seen = new Set(['none']);
				const fields = this.dcollAdvFieldOptions();
				const label = (k) => {
					const f = fields.find((o) => o.key === k);
					return f ? f.label : k;
				};
				for (const k of ['deprel', 'upos']) {
					if (fields.some((o) => o.key === k)) { out.push({ key: k, label: label(k) }); seen.add(k); }
				}
				for (const o of fields) if (!seen.has(o.key) && !/^text_|^s_/.test(o.key)) { out.push(o); seen.add(o.key); }
				const cur = String((this.dcollAdv && this.dcollAdv.breakdown) || '').trim();
				if (cur && !seen.has(cur)) out.push({ key: cur, label: cur });
				return out;
			},

			/** The breakdown attribute of the rows on screen ('' = none). */
			dcollAdvBreakdownShown() {
				const r = this.dcollAdv && this.dcollAdv.response;
				return r && r.breakdown_attribute ? String(r.breakdown_attribute) : '';
			},

			/** `case 360 · nmod 5` for one collocate: the values most frequent first, at most 4. */
			dcollAdvBreakdownText(row) {
				const b = row && row.breakdown && typeof row.breakdown === 'object' ? row.breakdown : null;
				if (!b) return '';
				const parts = Object.keys(b).map((k) => [k, Number(b[k]) || 0]).filter((p) => p[1] > 0);
				parts.sort((x, y) => y[1] - x[1] || (x[0] < y[0] ? -1 : 1));
				const shown = parts.slice(0, 4).map(([k, n]) => `${k} ${n}`);
				if (parts.length > 4) shown.push(`+${parts.length - 4}`);
				return shown.join(' · ');
			},

			dcollAdvMeasureOptions() {
				const order = typeof this.collocationMeasureOrder === 'function'
					? this.collocationMeasureOrder()
					: ['logdice', 'mi', 'mi3', 'tscore', 'll', 'dice'];
				return order.map((id) => ({ key: String(id), label: String(id) }));
			},

			dcollAdvNormalizeMeasureKeys(keys) {
				if (typeof this.normalizeCollocationMeasureKeys === 'function') {
					return this.normalizeCollocationMeasureKeys(keys);
				}
				const arr = Array.isArray(keys) ? keys : [];
				return arr.length ? arr : ['logdice'];
			},

			onDcollAdvMeasureSelectionChanged() {
				if (!this.dcollAdv || typeof this.dcollAdv !== 'object') return;
				this.dcollAdv.measureKeys = this.dcollAdvNormalizeMeasureKeys(this.dcollAdv.measureKeys);
				if (!this.dcollAdv.ran || !this.search || !this.search.ran) return;
				if (typeof this.statsSearchHasHits === 'function' && !this.statsSearchHasHits()) return;
				if (typeof this.isLoading === 'function' && this.isLoading('collocation')) return;
				if (this._dcollAdvMeasureRerunTimer) {
					clearTimeout(this._dcollAdvMeasureRerunTimer);
					this._dcollAdvMeasureRerunTimer = null;
				}
				this._dcollAdvMeasureRerunTimer = setTimeout(() => {
					this._dcollAdvMeasureRerunTimer = null;
					try { this.submitDcollAdvRun(); } catch (_) {}
				}, 250);
			},

			dcollAdvMeasureKeys() {
				const r = this.dcollAdvResult();
				if (r && Array.isArray(r.measures) && r.measures.length) {
					return r.measures.map((m) => String(m || '').trim()).filter(Boolean);
				}
				const row = this.dcollAdv && Array.isArray(this.dcollAdv.rows) && this.dcollAdv.rows.length
					? this.dcollAdv.rows[0]
					: null;
				if (!row || typeof row !== 'object') return [];
				const skip = new Set(['word', 'obs', 'freq', 'breakdown']);
				return Object.keys(row).filter((k) => !skip.has(k) && (row[k] === null || typeof row[k] !== 'object'));
			},

			dcollAdvResult() {
				const resp = this.dcollAdv && this.dcollAdv.response && typeof this.dcollAdv.response === 'object'
					? this.dcollAdv.response
					: null;
				const rr = resp && resp.result && typeof resp.result === 'object' ? resp.result : null;
				return rr || null;
			},

			dcollAdvUnwrapPayload(node) {
				let cur = node && typeof node === 'object' ? node : null;
				for (let i = 0; i < 6 && cur && typeof cur === 'object'; i += 1) {
					if (Array.isArray(cur.collocates) || Array.isArray(cur.rows)) return cur;
					if (cur.result && typeof cur.result === 'object') {
						cur = cur.result;
						continue;
					}
					if (cur.response && typeof cur.response === 'object') {
						cur = cur.response;
						continue;
					}
					break;
				}
				return cur && typeof cur === 'object' ? cur : null;
			},

			dcollAdvChartMetricOptions() {
				const out = [{ key: 'obs', label: 'Obs' }];
				const keys = this.dcollAdvMeasureKeys();
				for (let i = 0; i < keys.length; i += 1) {
					const k = String(keys[i] || '').trim();
					if (!k || k === 'obs') continue;
					out.push({ key: k, label: k });
				}
				return out;
			},

			dcollAdvEnsureChartMetricSelection() {
				const opts = this.dcollAdvChartMetricOptions();
				const valid = new Set(opts.map((o) => o.key));
				const cur = String(this.dcollAdvChartMetricKey || '').trim();
				if (cur && valid.has(cur)) return cur;
				const preferred = opts.find((o) => o.key !== 'obs');
				const next = preferred ? preferred.key : 'obs';
				this.dcollAdvChartMetricKey = next;
				return next;
			},

			dcollAdvChartValueKey() {
				return this.dcollAdvEnsureChartMetricSelection();
			},

			dcollAdvChartValueLabel() {
				return this.dcollAdvChartValueKey();
			},

			dcollAdvChartDataset() {
				const rows = this.dcollAdv && Array.isArray(this.dcollAdv.rows) ? this.dcollAdv.rows : [];
				const key = this.dcollAdvChartValueKey();
				const labels = [];
				const data = [];
				for (let i = 0; i < rows.length; i += 1) {
					const row = rows[i];
					const w = row && row.word != null ? String(row.word) : '';
					labels.push(typeof this.truncateFrequencyChartLabel === 'function' ? this.truncateFrequencyChartLabel(w || '(empty)') : (w || '(empty)'));
					const n = Number(row && row[key]);
					data.push(Number.isFinite(n) ? n : 0);
				}
				return { labels, data };
			},

			setDcollAdvVizMode(mode) {
				const m = String(mode || '').trim().toLowerCase();
				this.dcollAdvVizMode = ['table', 'bar', 'line', 'pie', 'polar', 'doughnut'].includes(m) ? m : 'table';
				this.scheduleDcollAdvChartRender();
			},

			onDcollAdvChartMetricChanged() {
				this.dcollAdvEnsureChartMetricSelection();
				this.scheduleDcollAdvChartRender();
			},

			destroyDcollAdvChart() {
				this._dcollAdvChartEpoch = (this._dcollAdvChartEpoch || 0) + 1;
				const ch = this.dcollAdvChartInstance;
				if (ch && typeof ch.destroy === 'function') {
					try { ch.destroy(); } catch (_) {}
				}
				this.dcollAdvChartInstance = null;
			},

			renderDcollAdvChart() {
				const Chart = typeof window !== 'undefined' ? window.Chart : null;
				if (!Chart || this.dcollAdvVizMode === 'table') return;
				const canvas = document.getElementById('flexicorp-dcoll-adv-chart-canvas');
				if (!canvas || !canvas.getContext || !canvas.isConnected) return;
				const { labels, data } = this.dcollAdvChartDataset();
				if (!labels.length) {
					this.destroyDcollAdvChart();
					return;
				}
				this.destroyDcollAdvChart();
				const ctx = canvas.getContext('2d');
				if (!ctx) return;
				const mode = this.dcollAdvVizMode;
				const valueLabel = this.dcollAdvChartValueLabel();
				const palette = typeof this.frequencyChartPalette === 'function' ? this.frequencyChartPalette(labels.length) : labels.map(() => '#4e79a7');
				const pieLike = mode === 'pie' || mode === 'polar' || mode === 'doughnut';
				const dataPlot = pieLike ? data.map((v) => Math.max(0, Number(v) || 0)) : data;
				if (pieLike && !dataPlot.some((x) => x > 0)) return;
				const common = { responsive: true, maintainAspectRatio: false, animation: { duration: 550 } };
				try {
					if (mode === 'bar') {
						this.dcollAdvChartInstance = new Chart(ctx, {
							type: 'bar',
							data: { labels, datasets: [{ label: valueLabel, data, backgroundColor: palette }] },
							options: { ...common, indexAxis: 'y', plugins: { legend: { display: false } } },
						});
					} else if (mode === 'line') {
						this.dcollAdvChartInstance = new Chart(ctx, {
							type: 'line',
							data: { labels, datasets: [{ label: valueLabel, data, borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,0.12)', fill: true, tension: 0.25 }] },
							options: { ...common, plugins: { legend: { display: false } } },
						});
					} else if (mode === 'polar') {
						this.dcollAdvChartInstance = new Chart(ctx, {
							type: 'polarArea',
							data: { labels, datasets: [{ data: dataPlot, backgroundColor: palette }] },
							options: common,
						});
					} else {
						this.dcollAdvChartInstance = new Chart(ctx, {
							type: mode === 'doughnut' ? 'doughnut' : 'pie',
							data: { labels, datasets: [{ data: dataPlot, backgroundColor: palette }] },
							options: common,
						});
					}
				} catch (_) {
					this.dcollAdvChartInstance = null;
				}
			},

			scheduleDcollAdvChartRender() {
				if (this.dcollAdvVizMode === 'table') {
					this.destroyDcollAdvChart();
					return;
				}
				const epoch = this._dcollAdvChartEpoch;
				requestAnimationFrame(() => {
					if (epoch !== this._dcollAdvChartEpoch) return;
					this.renderDcollAdvChart();
				});
			},

			dcollAdvProgramForRun() {
				const ss = (typeof window !== 'undefined' && window.ttFlexicorpFns && window.ttFlexicorpFns.searchScope)
					? window.ttFlexicorpFns.searchScope
					: null;
				const store = this.statsSearchScopeStore || null;
				let base = '';
				if (ss && store && Array.isArray(store.queries)) {
					const scope = ss.compileQueriesToScopeProgram(store.queries, { activeOnly: true });
					if (typeof store.mergeFullProgram === 'function') base = store.mergeFullProgram(scope);
					else base = scope;
				}
				if (!base && typeof this.statsSearchOnlyQueryText === 'function') base = this.statsSearchOnlyQueryText();
				base = String(base || '').trim().replace(/\s*;+\s*$/, '');
				if (typeof this.stripAggregationClausesFromQuery === 'function') {
					base = String(this.stripAggregationClausesFromQuery(base) || '').trim().replace(/\s*;+\s*$/, '');
				}
				const relation = String((this.dcollAdv && this.dcollAdv.relation) || 'children').trim() || 'children';
				const field = String((this.dcollAdv && this.dcollAdv.field) || 'lemma').trim() || 'lemma';
				const anchor = String((this.dcollAdv && this.dcollAdv.anchorToken) || '').trim();
				const bd = String((this.dcollAdv && this.dcollAdv.breakdown) || '').trim();
				const by = bd && bd !== 'none' && bd !== field ? `${field}, ${bd}` : field;
				if (!base) return '';
				// Pando-CQL ambiguity: `dcoll iobj by lemma` is parsed on some builds as
				// query_name=iobj (empty relations → all children). Builtins (head/children/
				// descendants) are fine before `by`. Deprel labels need either:
				//   dcoll a.iobj by lemma   (anchor.relation)
				//   dcoll iobj, by lemma    (trailing comma forces relation parse)
				const builtins = new Set(['head', 'children', 'descendants']);
				let dcollHead = 'dcoll ';
				if (anchor) {
					dcollHead += `${anchor}.${relation} by ${by}`;
				} else if (builtins.has(relation.toLowerCase())) {
					dcollHead += `${relation} by ${by}`;
				} else {
					dcollHead += `${relation}, by ${by}`;
				}
				return `${base}; ${dcollHead}`;
			},

			dcollAdvEnsureAnchorDefault() {
				if (!this.dcollAdv || typeof this.dcollAdv !== 'object') return;
				const opts = typeof this.collocationTokenAliasOptions === 'function' ? this.collocationTokenAliasOptions() : [];
				if (opts.length === 1) {
					this.dcollAdv.anchorToken = opts[0];
				}
			},

			async submitDcollAdvRun() {
				const prog = this.dcollAdvProgramForRun();
				if (!prog) {
					this.dcollAdvError = 'No base query available.';
					return;
				}
				this.dcollAdvLoading = true;
				this.dcollAdvError = '';
				try {
					if (typeof this.statsSearchScopePersistToSearch === 'function') this.statsSearchScopePersistToSearch();
					const fd = this.buildCommonRequestData();
					fd.set('active_tab', 'frequency');
					fd.set('run', 'query');
					fd.set('record', '0');   // a generated program, not a query the user ran
					fd.set('query', prog);
					fd.set('coll_min_freq', String(this.dcollAdv && this.dcollAdv.minFreq != null ? this.dcollAdv.minFreq : 1));
					fd.set('coll_max_items', String(this.dcollAdv && this.dcollAdv.maxItems != null ? this.dcollAdv.maxItems : 50));
					fd.set('coll_stoplist', String(this.dcollAdv && this.dcollAdv.stoplist != null ? this.dcollAdv.stoplist : 0));
					const mk = this.dcollAdvNormalizeMeasureKeys(this.dcollAdv && this.dcollAdv.measureKeys);
					fd.set('coll_measures', mk.join(','));
					await this.submitAjaxData(fd, 'collocation');
					if (typeof this.dcollAdvHydrateFromState === 'function') this.dcollAdvHydrateFromState(this);
				} catch (e) {
					this.dcollAdvError = e && e.message ? e.message : String(e);
				} finally {
					this.dcollAdvLoading = false;
				}
			},

			dcollAdvHydrateFromState(stateLike) {
				const s = stateLike && typeof stateLike === 'object' ? stateLike : {};
				const other = s.other && typeof s.other === 'object' ? s.other : null;
				const search = s.search && typeof s.search === 'object' ? s.search : null;
				const response = search && search.response && typeof search.response === 'object' ? search.response : null;
				const result = response && response.result && typeof response.result === 'object' ? response.result : null;
				const candidates = [];
				if (result && String(result.operation || '').trim().toLowerCase() === 'dcoll') candidates.push(result);
				if (other && String(other.operation || '').trim().toLowerCase() === 'dcoll') {
					candidates.push(other);
					if (other.response && typeof other.response === 'object') candidates.push(other.response);
				}
				let payload = null;
				let rows = [];
				for (let i = 0; i < candidates.length; i += 1) {
					const unwrapped = this.dcollAdvUnwrapPayload(candidates[i]);
					if (!unwrapped) continue;
					const candRows = Array.isArray(unwrapped.collocates)
						? unwrapped.collocates
						: (Array.isArray(unwrapped.rows)
							? unwrapped.rows
							: (unwrapped.table && Array.isArray(unwrapped.table.rows) ? unwrapped.table.rows : []));
					if (candRows.length) {
						payload = unwrapped;
						rows = candRows;
						break;
					}
					if (!payload) payload = unwrapped;
				}
				if (!payload) return false;
				this.dcollAdv.ran = true;
				this.dcollAdv.response = payload;
				this.dcollAdv.rows = Array.isArray(rows) ? rows : [];
				if (payload && payload.attribute != null) this.dcollAdv.field = String(payload.attribute || '').trim() || this.dcollAdv.field;
				if (payload && payload.breakdown_attribute) this.dcollAdv.breakdown = String(payload.breakdown_attribute);
				if (payload && Array.isArray(payload.relations) && payload.relations.length) {
					const rel = String(payload.relations[0] || '').trim();
					if (rel) this.dcollAdv.relation = rel;
				}
				if (payload && Array.isArray(payload.measures) && payload.measures.length) {
					this.dcollAdv.measureKeys = this.dcollAdvNormalizeMeasureKeys(payload.measures);
				}
				try {
					this.dcollAdv.raw = JSON.stringify(payload, null, 2);
				} catch (_) {
					this.dcollAdv.raw = '';
				}
				this.dcollAdvEnsureChartMetricSelection();
				this.scheduleDcollAdvChartRender();
				return true;
			},
		});
	}

	if (typeof window !== 'undefined') {
		window.ttFlexicorpModuleInstallers = window.ttFlexicorpModuleInstallers || [];
		window.ttFlexicorpModuleInstallers.push(function installDcollForFlexicorp(app, registerModule) {
			installAdvancedDcoll(app, registerModule);
		});
	}
})();

