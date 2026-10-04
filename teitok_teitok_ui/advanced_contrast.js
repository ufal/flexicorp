/*
 * Contrast (named-query keyness) module — installs onto flexicorp Stats and/or advanced_freqs host.
 */
(function () {
	function ttContrastSharedFns() {
		return typeof window !== 'undefined' && window.ttFlexicorpFns && typeof window.ttFlexicorpFns === 'object'
			? window.ttFlexicorpFns
			: {};
	}

	function installAdvancedContrast(app, registerModule) {
		if (!app || typeof app !== 'object') return;
		if (typeof registerModule === 'function') {
			registerModule({
				id: 'contrast',
				label: 'Contrast',
				description: 'Contrastive keyness over active named queries from the shared Search Scope.',
				// Used for operation-driven Stats tab routing.
				ingestsOperations: ['keyness'],
				isAvailable: (ctx) => {
					const b = String((ctx && ctx.backend) || '').trim().toLowerCase();
					if (!(b === 'pando' || b === 'flexicorp-pando')) return false;

					// If the backend already returned a keyness result, we must show Contrast
					// so the user can see the computed output, even if scope gating (active
					// named queries) is temporarily unavailable during routing.
					const op = String((ctx && ctx.operation) || '').trim().toLowerCase();
					if (op === 'keyness') return true;

					// Stats gating: hide Contrast unless we have enough named queries to run keyness.
					// AF host pages don't provide this field yet, so keep Contrast visible there.
					const nRaw = ctx && ctx.activeNamedQueryCount;
					const n = Number(nRaw);
					if (Number.isFinite(n) && n < 2) return false;

					return true;
				},
			});
		}

		if (app.afKeynessField === undefined) app.afKeynessField = 'lemma';
		if (!Array.isArray(app.afKeynessFields)) app.afKeynessFields = ['lemma'];
		if (app.afKeynessLoading === undefined) app.afKeynessLoading = false;
		if (app.afKeynessError === undefined) app.afKeynessError = '';
		if (app.afKeynessRaw === undefined) app.afKeynessRaw = '';
		if (app.afKeynessPayload === undefined) app.afKeynessPayload = null;
		if (!Array.isArray(app.afKeynessRows)) app.afKeynessRows = [];
		if (app.afKeynessScoreField === undefined) app.afKeynessScoreField = 'keyness';
		if (app.afContrastVizMode === undefined) app.afContrastVizMode = 'table';
		if (app.afContrastMetric === undefined) app.afContrastMetric = 'keyness';
		if (app.afContrastVolcanoPointLimit == null) app.afContrastVolcanoPointLimit = 200;
		if (!app.afContrastTooltip || typeof app.afContrastTooltip !== 'object') app.afContrastTooltip = { visible: false };

		Object.assign(app, {
			afContrastFieldOptions() {
				const fns = ttContrastSharedFns();
				const labels = fns && typeof fns.frequencySelectableFieldLabelMap === 'function'
					? fns.frequencySelectableFieldLabelMap(this)
					: (this.frequency && this.frequency.fieldLabels && typeof this.frequency.fieldLabels === 'object'
						? this.frequency.fieldLabels
						: {});
				const allowedKeys = (() => {
					try {
						const parsed = fns && typeof fns.frequencySelectableFieldKeys === 'function'
							? fns.frequencySelectableFieldKeys()
							: [];
						if (!Array.isArray(parsed) || !parsed.length) return null;
						return new Set(
							parsed
								.map((k) => String(k || '').trim())
								.filter(Boolean),
						);
					} catch (_) {
						return null;
					}
				})();
				const out = [];
				const seen = new Set();
				const push = (k, label) => {
					const key = String(k || '').trim();
					if (!key || seen.has(key)) return;
					// Keep Contrast field options aligned with Frequency's curated field list.
					if (allowedKeys && !allowedKeys.has(key)) return;
					// Defensive fallback when Frequency's allow-list is unavailable.
					if (!allowedKeys && key.startsWith('@')) return;
					if (!allowedKeys && /[`]/.test(key)) return;
					if (!allowedKeys && /\s/.test(key) && !/[()]/.test(key)) return;
					seen.add(key);
					out.push({ key, label: String(label || key).trim() || key });
				};
				const keys = Object.keys(labels);
				for (let i = 0; i < keys.length; i += 1) push(keys[i], labels[keys[i]]);
				if (!out.length) push('lemma', 'Lemma');
				return out;
			},

			afContrastNormalizeFieldsFromState() {
				const current = Array.isArray(this.afKeynessFields) ? this.afKeynessFields : [];
				const list = current
					.map((x) => String(x || '').trim())
					.filter(Boolean);
				if (list.length) {
					this.afKeynessFields = Array.from(new Set(list));
					this.afKeynessField = this.afKeynessFields.join(', ');
					return;
				}
				const fromText = String(this.afKeynessField || '')
					.split(/\s*,\s*/)
					.map((s) => s.trim())
					.filter(Boolean);
				this.afKeynessFields = fromText.length ? Array.from(new Set(fromText)) : ['lemma'];
				this.afKeynessField = this.afKeynessFields.join(', ');
			},

			afContrastIsFieldSelected(fieldKey) {
				this.afContrastNormalizeFieldsFromState();
				const key = String(fieldKey || '').trim();
				if (!key) return false;
				return this.afKeynessFields.includes(key);
			},

			afContrastOnFieldToggle(fieldKey, checked) {
				const key = String(fieldKey || '').trim();
				if (!key) return;
				this.afContrastNormalizeFieldsFromState();
				let next = this.afKeynessFields.slice();
				if (checked) {
					if (!next.includes(key)) next.push(key);
				} else {
					next = next.filter((k) => k !== key);
				}
				if (!next.length) next = ['lemma'];
				this.afKeynessFields = next;
				this.afKeynessField = next.join(', ');
			},

			_contrastSearchScopeFns() {
				return (typeof window !== 'undefined' && window.ttFlexicorpFns && window.ttFlexicorpFns.searchScope)
					? window.ttFlexicorpFns.searchScope
					: null;
			},
			_contrastScopeStore() {
				return this.statsSearchScopeStore || this.afSearchScopeStore || null;
			},
			_contrastFullProgramText() {
				const ss = this._contrastSearchScopeFns();
				const store = this._contrastScopeStore();
				if (!ss || !store) return '';
				const scope = ss.compileQueriesToScopeProgram(store.queries, { activeOnly: false });
				return typeof store.mergeFullProgram === 'function' ? store.mergeFullProgram(scope) : scope;
			},
			_contrastScopeOnlyQuery() {
				const ss = this._contrastSearchScopeFns();
				if (!ss) return '';
				return ss.stripAggregationClausesFromQuery(this._contrastFullProgramText());
			},
			contrastHasBaseQuery() {
				return String(this._contrastScopeOnlyQuery() || '').trim() !== '';
			},
			_contrastAjaxActionName() {
				const a = String(this.action || '').trim();
				if (a) return a;
				if (typeof this.afAjaxActionName === 'function') return this.afAjaxActionName();
				const root = typeof document !== 'undefined' ? document.getElementById('flexicorp-advanced-freqs-root') : null;
				const attr = root ? String(root.getAttribute('data-af-action') || '').trim() : '';
				return attr || 'flexicorp';
			},

			afKeynessProgramForRun() {
				const ss = this._contrastSearchScopeFns();
				const store = this._contrastScopeStore();
				if (!ss || !store) return '';
				const scopeOnly = ss.compileQueriesToScopeProgram(store.queries, { activeOnly: true });
				if (!String(scopeOnly || '').trim()) return '';
				this.afContrastNormalizeFieldsFromState();
				const fields = Array.isArray(this.afKeynessFields)
					? this.afKeynessFields.map((s) => String(s || '').trim()).filter(Boolean)
					: [];
				if (!fields.length) return '';
				const names = ss.activeNamedAssignIds(store.queries);
				if (names.length < 2) return '';
				const tail = ss.buildKeynessClause(names.join(', '), fields);
				if (!tail) return '';
				return ss.compileScopeProgram(scopeOnly, tail);
			},

			afActiveNamedQueryIds() {
				const ss = this._contrastSearchScopeFns();
				const store = this._contrastScopeStore();
				if (!ss || !store || !Array.isArray(store.queries)) return [];
				try {
					return ss.activeNamedAssignIds(store.queries);
				} catch (_) {
					return [];
				}
			},

			afKeynessRunDisabled() {
				return this.afKeynessLoading || !this.contrastHasBaseQuery() || !this.afKeynessProgramForRun();
			},

			afContrastRunDisabled() {
				return this.afKeynessLoading || !this.contrastHasBaseQuery() || !this.afKeynessProgramForRun();
			},

			setAfContrastVizMode(mode) {
				const m = String(mode || '').trim().toLowerCase();
				this.afContrastVizMode = m === 'hbar' || m === 'volcano' ? m : 'table';
			},

			afContrastHasRows() {
				return Array.isArray(this.afKeynessRows) && this.afKeynessRows.length > 0;
			},

			afContrastHydrateFromOtherState(otherState) {
				const other = otherState && typeof otherState === 'object' ? otherState : null;
				if (!other) return false;
				const op = String(
					other.operation
					|| (other.response && other.response.result && other.response.result.operation)
					|| (other.response && other.response.operation)
					|| ''
				).trim().toLowerCase();
				if (op !== 'keyness') return false;
				const payloadCandidates = [
					other.response && other.response.result && typeof other.response.result === 'object' ? other.response.result : null,
					other.response && typeof other.response === 'object' ? other.response : null,
					other && typeof other === 'object' ? other : null,
					Array.isArray(other.rows) ? { rows: other.rows, columns: other.columns || [] } : null,
				].filter(Boolean);
				let extracted = { rows: [], scoreField: 'keyness' };
				let usedPayload = null;
				for (let i = 0; i < payloadCandidates.length; i += 1) {
					const candidate = payloadCandidates[i];
					const x = this.afExtractKeynessRows(candidate);
					if (x && Array.isArray(x.rows) && x.rows.length) {
						extracted = x;
						usedPayload = candidate;
						break;
					}
				}
				if (!Array.isArray(extracted.rows) || !extracted.rows.length) return false;
				this.afKeynessError = '';
				this.afKeynessPayload = usedPayload || other;
				this.afKeynessRows = extracted.rows;
				this.afKeynessScoreField = extracted.scoreField || 'keyness';
				try {
					this.afKeynessRaw = JSON.stringify(this.afKeynessPayload, null, 2);
				} catch (_) {
					this.afKeynessRaw = String(this.afKeynessPayload || '');
				}
				return true;
			},

			afContrastHydrateFromState(stateLike) {
				const s = stateLike && typeof stateLike === 'object' ? stateLike : {};
				// First preference: legacy/keyness payload in state.other (if present).
				if (this.afContrastHydrateFromOtherState(s.other)) return true;
				// Canonical path: keyness result in search response payload.
				const search = s.search && typeof s.search === 'object' ? s.search : null;
				const response = search && search.response && typeof search.response === 'object' ? search.response : null;
				const result = response && response.result && typeof response.result === 'object' ? response.result : null;
				const op = String((result && result.operation) || (response && response.operation) || '').trim().toLowerCase();
				if (op !== 'keyness') return false;
				const extracted = this.afExtractKeynessRows(result || response);
				if (!extracted || !Array.isArray(extracted.rows) || !extracted.rows.length) return false;
				this.afKeynessError = '';
				this.afKeynessPayload = result || response;
				this.afKeynessRows = extracted.rows;
				this.afKeynessScoreField = extracted.scoreField || 'keyness';
				try {
					this.afKeynessRaw = JSON.stringify(this.afKeynessPayload, null, 2);
				} catch (_) {
					this.afKeynessRaw = String(this.afKeynessPayload || '');
				}
				return true;
			},

			afExtractKeynessRows(payload) {
				const p = payload && typeof payload === 'object' ? payload : {};
				const opName = String(
					p.operation
					|| (p.result && p.result.operation)
					|| (p.result && p.result.result && p.result.result.operation)
					|| (p.response && p.response.operation)
					|| (p.response && p.response.result && p.response.result.operation)
					|| '',
				).trim().toLowerCase();
				if (opName && opName !== 'keyness') return { rows: [], scoreField: 'keyness' };
				const firstFinite = (obj, keys) => {
					if (!obj || typeof obj !== 'object' || !Array.isArray(keys)) return null;
					for (let i = 0; i < keys.length; i += 1) {
						const n = Number(obj[keys[i]]);
						if (Number.isFinite(n) && n > 0) return n;
					}
					return null;
				};
				const nestedObjs = [p];
				if (p.result && typeof p.result === 'object') nestedObjs.push(p.result);
				if (p.result && p.result.result && typeof p.result.result === 'object') nestedObjs.push(p.result.result);
				if (p.response && typeof p.response === 'object') nestedObjs.push(p.response);
				if (p.response && p.response.result && typeof p.response.result === 'object') nestedObjs.push(p.response.result);
				const focusSize =
					(() => {
						for (let i = 0; i < nestedObjs.length; i += 1) {
							const n = firstFinite(nestedObjs[i], ['focus_size', 'focusSize']);
							if (n !== null) return n;
						}
						return null;
					})()
					|| null;
				const refSize =
					(() => {
						for (let i = 0; i < nestedObjs.length; i += 1) {
							const n = firstFinite(nestedObjs[i], ['ref_size', 'refSize']);
							if (n !== null) return n;
						}
						return null;
					})()
					|| null;
				const candidates = [
					p.rows,
					p.table && p.table.rows,
					p.result && p.result.rows,
					p.result && p.result.table && p.result.table.rows,
				];
				let rowsRaw = [];
				for (let i = 0; i < candidates.length; i += 1) {
					if (Array.isArray(candidates[i]) && candidates[i].length) {
						rowsRaw = candidates[i];
						break;
					}
				}
				if (!rowsRaw.length) return { rows: [], scoreField: 'keyness' };
				const focusSum = rowsRaw.reduce((s, row) => s + (Number(row && (row.focus_freq ?? row.focusFreq ?? 0)) || 0), 0);
				const refSum = rowsRaw.reduce((s, row) => s + (Number(row && (row.ref_freq ?? row.refFreq ?? 0)) || 0), 0);
				const focusBase = (focusSize && focusSize > 0) ? focusSize : (focusSum > 0 ? focusSum : null);
				const refBase = (refSize && refSize > 0) ? refSize : (refSum > 0 ? refSum : null);
				const scoreFields = ['keyness', 'll', 'log_likelihood', 'score', 'unexpectedness', 'logratio', 'log_ratio'];
				let usedScoreField = 'keyness';
				const out = rowsRaw.map((row, idx) => {
					const r = row && typeof row === 'object' ? row : {};
					const hasExplicitWord = Object.prototype.hasOwnProperty.call(r, 'word');
					let label = String(r.word ?? r.label ?? r.value ?? r.key ?? r.term ?? r.item ?? '').trim();
					if (!label) {
						const keys = Object.keys(r);
						for (let k = 0; k < keys.length; k += 1) {
							const key = keys[k];
							if (scoreFields.includes(String(key).toLowerCase())) continue;
							if (key === 'count' || key === 'freq' || key === 'n') continue;
							if (key === 'effect') continue;
							const v = r[key];
							if (typeof v === 'string' && String(v).trim()) {
								label = String(v).trim();
								break;
							}
						}
					}
					// In keyness rows, `word` can be an intentionally empty token bucket.
					if (!label && hasExplicitWord) label = '(empty)';
					let score = NaN;
					for (let s = 0; s < scoreFields.length; s += 1) {
						const key = scoreFields[s];
						const n = Number(r[key]);
						if (Number.isFinite(n)) {
							score = n;
							usedScoreField = key;
							break;
						}
					}
					const focusFreq = Number(r.focus_freq ?? r.focusFreq ?? 0);
					const refFreq = Number(r.ref_freq ?? r.refFreq ?? 0);
					const count = Number(r.count ?? r.freq ?? r.n ?? focusFreq ?? 0);
					const explicitLogRatio = Number(
						r.logratio ?? r.log_ratio ?? r.logRatio ?? r.effect_size ?? r.effectSize ?? NaN,
					);
					let logRatio = Number.isFinite(explicitLogRatio) ? explicitLogRatio : NaN;
					if (!Number.isFinite(logRatio) && Number.isFinite(focusFreq) && Number.isFinite(refFreq) && focusBase && refBase) {
						// Smoothed log2 ratio to avoid zero-division explosions.
						const fp = (focusFreq + 0.5) / (focusBase + 1);
						const rp = (refFreq + 0.5) / (refBase + 1);
						if (fp > 0 && rp > 0) logRatio = Math.log2(fp / rp);
					}
					const keynessLike = Number(
						r.keyness ?? r.ll ?? r.log_likelihood ?? r.score ?? NaN,
					);
					return {
						key: `k-${idx}-${label}`,
						label: label || `Item ${idx + 1}`,
						score: Number.isFinite(score) ? score : 0,
						count: Number.isFinite(count) ? count : 0,
						focusFreq: Number.isFinite(focusFreq) ? focusFreq : 0,
						refFreq: Number.isFinite(refFreq) ? refFreq : 0,
						effect: String(r.effect ?? '').trim(),
						logRatio: Number.isFinite(logRatio) ? logRatio : null,
						keynessLike: Number.isFinite(keynessLike) ? keynessLike : (Number.isFinite(score) ? score : 0),
						raw: r,
					};
				});
				out.sort((a, b) => Math.abs(Number(b.score || 0)) - Math.abs(Number(a.score || 0)));
				return { rows: out, scoreField: usedScoreField };
			},

			afContrastMetricOptions() {
				const out = [{ key: 'keyness', label: this.afContrastScoreLabel() }];
				const rows = Array.isArray(this.afKeynessRows) ? this.afKeynessRows : [];
				if (rows.some((r) => r && Number.isFinite(Number(r.logRatio)))) {
					out.push({ key: 'log_ratio', label: 'Log ratio' });
				}
				return out;
			},

			afContrastMetricLabel() {
				const m = String(this.afContrastMetric || 'keyness').trim().toLowerCase();
				if (m === 'log_ratio') return 'Log ratio';
				return this.afContrastScoreLabel();
			},

			afContrastMetricValue(row) {
				const r = row && typeof row === 'object' ? row : {};
				const m = String(this.afContrastMetric || 'keyness').trim().toLowerCase();
				if (m === 'log_ratio') {
					const n = Number(r.logRatio);
					return Number.isFinite(n) ? n : 0;
				}
				return Number(r.score || 0);
			},

			afContrastVolcanoPointOptions() {
				return [50, 100, 200, 400, 800];
			},

			afContrastRowsLimited(limit = 60) {
				const n = Math.max(1, Number(limit) || 60);
				const rows = Array.isArray(this.afKeynessRows) ? this.afKeynessRows.slice() : [];
				rows.sort((a, b) => Math.abs(this.afContrastMetricValue(b)) - Math.abs(this.afContrastMetricValue(a)));
				return rows.slice(0, n);
			},

			afContrastScoreLabel() {
				const key = String(this.afKeynessScoreField || 'keyness').toLowerCase();
				if (key === 'll' || key === 'log_likelihood') return 'Log-likelihood';
				if (key === 'logratio' || key === 'log_ratio') return 'Log ratio';
				if (key === 'unexpectedness') return 'Unexpectedness';
				if (key === 'score') return 'Score';
				return 'Keyness';
			},

			afContrastMaxAbsScore() {
				const rows = this.afContrastRowsLimited(80);
				let m = 0;
				for (let i = 0; i < rows.length; i += 1) {
					const v = Math.abs(Number(this.afContrastMetricValue(rows[i]) || 0));
					if (v > m) m = v;
				}
				return m > 0 ? m : 1;
			},

			afContrastHbarFillWidth(score) {
				const m = this.afContrastMaxAbsScore();
				const v = Math.abs(Number(score || 0));
				return `${Math.max(0, Math.min(100, (v / m) * 100))}%`;
			},

			afContrastLinePoints() {
				const rows = this.afContrastRowsLimited(40);
				if (!rows.length) return '';
				const w = 760;
				const h = 260;
				const padX = 24;
				const padY = 16;
				const spanX = Math.max(1, rows.length - 1);
				const maxAbs = this.afContrastMaxAbsScore();
				const toX = (i) => padX + ((w - 2 * padX) * (i / spanX));
				const toY = (v) => {
					const n = Number(v || 0);
					const rel = (n + maxAbs) / (2 * maxAbs);
					return h - padY - rel * (h - 2 * padY);
				};
				return rows.map((r, i) => `${toX(i).toFixed(1)},${toY(this.afContrastMetricValue(r)).toFixed(1)}`).join(' ');
			},

			afContrastVolcanoPoints() {
				const allRows = Array.isArray(this.afKeynessRows) ? this.afKeynessRows.slice() : [];
				if (!allRows.length) return [];
				const yForRow = (r) => {
					const v = Math.abs(Number(this.afContrastMetricValue(r)));
					if (Number.isFinite(v) && v > 0) return v;
					const ky = Math.abs(Number(r && r.keynessLike));
					return Number.isFinite(ky) ? ky : 0;
				};
				const keepable = allRows.filter((r) => {
					if (!r || typeof r !== 'object') return false;
					const label = String(r.label || '').trim().toLowerCase();
					if (label === '(empty)') return false;
					const x = Number(r.logRatio);
					return Number.isFinite(x);
				});
				if (!keepable.length) return [];
				const maxPts = Math.max(20, Number(this.afContrastVolcanoPointLimit) || 200);
				const takeSide = Math.max(40, Math.floor(maxPts / 2));
				const left = keepable.filter((r) => Number(r.logRatio) < 0);
				const right = keepable.filter((r) => Number(r.logRatio) >= 0);
				const sortByY = (a, b) => yForRow(b) - yForRow(a);
				left.sort(sortByY);
				right.sort(sortByY);
				let rows = left.slice(0, takeSide).concat(right.slice(0, takeSide));
				if (rows.length < maxPts) {
					const extraLeft = left.slice(takeSide);
					const extraRight = right.slice(takeSide);
					const rem = extraLeft.concat(extraRight).sort(sortByY);
					rows = rows.concat(rem.slice(0, Math.max(0, maxPts - rows.length)));
				}
				if (!rows.length) return [];
				let maxX = 0;
				let maxY = 0;
				for (let i = 0; i < rows.length; i += 1) {
					const x = Number(rows[i].logRatio);
					const y = yForRow(rows[i]);
					if (Number.isFinite(x) && Math.abs(x) > maxX) maxX = Math.abs(x);
					if (Number.isFinite(y) && y > maxY) maxY = y;
				}
				maxX = maxX > 0 ? maxX : 1;
				maxY = maxY > 0 ? maxY : 1;
				const w = 760; const h = 280;
				const padL = 42; const padR = 16; const padT = 16; const padB = 30;
				const spanW = w - padL - padR;
				const spanH = h - padT - padB;
				const out = [];
				for (let i = 0; i < rows.length; i += 1) {
					const r = rows[i];
					const xv = Number(r.logRatio);
					const yv = yForRow(r);
					if (!Number.isFinite(xv) || !Number.isFinite(yv)) continue;
					const x = padL + ((xv + maxX) / (2 * maxX)) * spanW;
					const y = padT + (1 - (yv / maxY)) * spanH;
					out.push({
						key: r.key || String(i),
						label: r.label || '',
						x,
						y,
						score: xv,
						sig: yv,
						color: xv >= 0 ? '#2563eb' : '#dc2626',
					});
				}
				return out;
			},

			afContrastVolcanoCirclesSvg() {
				const esc = (s) => String(s == null ? '' : s)
					.replace(/&/g, '&amp;')
					.replace(/</g, '&lt;')
					.replace(/>/g, '&gt;')
					.replace(/"/g, '&quot;')
					.replace(/'/g, '&#39;');
				const pts = this.afContrastVolcanoPoints();
				if (!Array.isArray(pts) || !pts.length) return '';
				let out = '';
				for (let i = 0; i < pts.length; i += 1) {
					const p = pts[i] || {};
					const x = Number(p.x);
					const y = Number(p.y);
					if (!Number.isFinite(x) || !Number.isFinite(y)) continue;
					const label = esc(
						`${p.label || ''} | log ratio=${Number(p.score || 0).toFixed(3)} | ${this.afContrastMetricLabel()}=${Number(p.sig || 0).toFixed(3)}`,
					);
					const fill = esc(String(p.color || '#2563eb'));
					out += `<circle cx="${x.toFixed(2)}" cy="${y.toFixed(2)}" r="3.5" fill="${fill}" data-tip="${label}"></circle>`;
				}
				return out;
			},

			afContrastHideTooltip() {
				this.afContrastTooltip = { visible: false };
			},

			afContrastOnVolcanoMouseMove(event) {
				const e = event && typeof event === 'object' ? event : null;
				const svg = e && e.currentTarget && typeof e.currentTarget.getBoundingClientRect === 'function'
					? e.currentTarget
					: null;
				if (!svg) {
					this.afContrastHideTooltip();
					return;
				}
				const pts = this.afContrastVolcanoPoints();
				if (!Array.isArray(pts) || !pts.length) {
					this.afContrastHideTooltip();
					return;
				}
				const rect = svg.getBoundingClientRect();
				const viewBox = svg.viewBox && svg.viewBox.baseVal ? svg.viewBox.baseVal : { x: 0, y: 0, width: 760, height: 280 };
				const sx = rect.width > 0 ? (viewBox.width / rect.width) : 1;
				const sy = rect.height > 0 ? (viewBox.height / rect.height) : 1;
				const mx = (Number(e.clientX) - rect.left) * sx + viewBox.x;
				const my = (Number(e.clientY) - rect.top) * sy + viewBox.y;
				let best = null;
				let bestD2 = Infinity;
				for (let i = 0; i < pts.length; i += 1) {
					const p = pts[i] || {};
					const px = Number(p.x);
					const py = Number(p.y);
					if (!Number.isFinite(px) || !Number.isFinite(py)) continue;
					const dx = px - mx;
					const dy = py - my;
					const d2 = dx * dx + dy * dy;
					if (d2 < bestD2) {
						bestD2 = d2;
						best = p;
					}
				}
				// ~11 px snap radius in viewBox coords.
				if (!best || bestD2 > 130) {
					this.afContrastHideTooltip();
					return;
				}
				const left = Math.max(8, Math.min(viewBox.width - 220, Number(best.x) + 10));
				const top = Math.max(8, Math.min(viewBox.height - 70, Number(best.y) - 40));
				this.afContrastTooltip = {
					visible: true,
					left,
					top,
					label: String(best.label || ''),
					logRatio: Number(best.score || 0),
					metric: Number(best.sig || 0),
					color: String(best.color || '#2563eb'),
				};
			},

			buildAfKeynessFormData(programText) {
				const fd = new FormData();
				fd.set('ajax', '1');
				fd.set('action', this._contrastAjaxActionName());
				fd.set('run', 'query');
				fd.set('active_tab', 'frequency');
				const bo = this.backendOverrides && typeof this.backendOverrides === 'object' ? this.backendOverrides : {};
				fd.set('backend', String((this.settings && this.settings.backend) || this.backend || '').trim() || 'cqp');
				const qe = String((this.settings && this.settings.queryEngine) || this.queryEngine || '').trim();
				if (qe) fd.set('query_engine', qe);
				const ql = String((this.settings && this.settings.queryLanguage) || this.queryLanguage || '').trim();
				if (ql) fd.set('query_language', ql);
				const cf = String((this.settings && this.settings.corpusFormat) || this.corpusFormat || '').trim();
				if (cf) fd.set('corpus_format', cf);
				if (bo.blacklab_url) fd.set('blacklab_url', String(bo.blacklab_url));
				if (bo.blacklab_corpus) fd.set('blacklab_corpus', String(bo.blacklab_corpus));
				if (bo.blacklab_user) fd.set('blacklab_user', String(bo.blacklab_user));
				if (bo.blacklab_password) fd.set('blacklab_password', String(bo.blacklab_password));
				if (bo.blacklab_field) fd.set('blacklab_field', String(bo.blacklab_field));
				if (this.settings && this.settings.docsLimit != null) fd.set('docs_limit', String(this.settings.docsLimit));
				if (this.settings && this.settings.kwicLimit != null) fd.set('kwic_limit', String(this.settings.kwicLimit));
				if (this.settings && this.settings.kwicWindow != null) fd.set('window', String(this.settings.kwicWindow));
				if (!fd.has('docs_limit')) fd.set('docs_limit', '20');
				if (!fd.has('docs_per_page')) fd.set('docs_per_page', '20');
				if (!fd.has('kwic_limit')) fd.set('kwic_limit', '25');
				fd.set('start', '0');
				fd.set('query', String(programText || '').trim());
				return fd;
			},

			async submitAfKeynessRun() {
				const prog = this.afKeynessProgramForRun();
				if (!prog) {
					this.afKeynessError = 'Need at least two active named queries in Search Scope (e.g. A and B), plus a field.';
					return;
				}
				this.afKeynessLoading = true;
				this.afKeynessError = '';
				this.afKeynessRaw = '';
				this.afKeynessPayload = null;
				this.afKeynessRows = [];
				try {
					const fd = this.buildAfKeynessFormData(prog);
					const rootEl = typeof document !== 'undefined' ? document.getElementById('flexicorp-advanced-freqs-root') : null;
					const fns = ttContrastSharedFns();
					const url = typeof fns.flexicorpAjaxPostUrl === 'function'
						? fns.flexicorpAjaxPostUrl(rootEl, this._contrastAjaxActionName())
						: (typeof this.afAjaxPostUrl === 'function' ? this.afAjaxPostUrl() : '');
					let state = null;
					let parseErr = null;
					if (typeof fns.runFlexicorpAjaxFetch === 'function') {
						const out = await fns.runFlexicorpAjaxFetch(fd, rootEl, url);
						state = out.state;
						parseErr = out.parseError;
					} else {
						const response = await fetch(url, {
							method: 'POST',
							body: fd,
							headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json, text/javascript, */*;q=0.01' },
							credentials: 'same-origin',
						});
						const raw = await response.text();
						try {
							state = raw ? JSON.parse(raw) : null;
						} catch (e) {
							parseErr = e instanceof Error ? e : new Error(String(e));
						}
					}
					if (!state || typeof state !== 'object') {
						this.afKeynessError = parseErr ? 'Invalid JSON from endpoint.' : 'Empty response.';
						return;
					}
					const other = state.other && typeof state.other === 'object' ? state.other : null;
					const payload = other && other.response && typeof other.response === 'object' ? other.response : other;
					const normalizedPayload = payload != null ? payload : state;
					const op = String(
						normalizedPayload && normalizedPayload.operation
							|| (normalizedPayload && normalizedPayload.result && normalizedPayload.result.operation)
							|| '',
					).trim().toLowerCase();
					if (op && op !== 'keyness') {
						this.afKeynessError = `Expected keyness result, got "${op}".`;
						return;
					}
					this.afKeynessPayload = normalizedPayload;
					const extracted = this.afExtractKeynessRows(normalizedPayload);
					this.afKeynessRows = extracted.rows;
					this.afKeynessScoreField = extracted.scoreField || 'keyness';
					try {
						this.afKeynessRaw = JSON.stringify(normalizedPayload, null, 2);
					} catch (_) {
						this.afKeynessRaw = String(normalizedPayload || '');
					}
				} catch (e) {
					this.afKeynessError = e && e.message ? e.message : String(e);
				} finally {
					this.afKeynessLoading = false;
				}
			},
		});
	}

	window.ttAdvancedContrastInstall = installAdvancedContrast;
	window.ttFlexicorpModuleInstallers = window.ttFlexicorpModuleInstallers || [];
	window.ttFlexicorpModuleInstallers.push(installAdvancedContrast);
	window.ttAdvancedFreqsModuleInstallers = window.ttAdvancedFreqsModuleInstallers || [];
	window.ttAdvancedFreqsModuleInstallers.push(installAdvancedContrast);
})();
