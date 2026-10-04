/**
 * Shared Search Scope model: named queries, compile/parse to a single Pando-style program string.
 * Loaded after flexicorp_functions.js — attaches to window.ttFlexicorpFns.searchScope.
 */
(function () {
	'use strict';

	window.ttFlexicorpFns = window.ttFlexicorpFns || {};

	/** @param {string} p */
	function isAggregationStatement(p) {
		const s = String(p || '').trim();
		if (!s) return false;
		if (/^(freq|group)(?:\s+[A-Za-z0-9_,\s]+)?\s+(?:by|match)\s+/i.test(s)) return true;
		if (/^coll\s+/i.test(s)) return true;
		if (/^tabulate\s+/i.test(s)) return true;
		if (/^sort\s+/i.test(s)) return true;
		if (/^count\b/i.test(s)) return true;
		if (/^dist\b/i.test(s)) return true;
		if (/^dcoll\b/i.test(s)) return true;
		if (/^keyness\b/i.test(s)) return true;
		if (/^show\s+/i.test(s)) return true;
		if (/^size\b/i.test(s)) return true;
		return false;
	}

	/**
	 * Strip aggregation / association tails (aligned with flexicorp_freqs.js stripAggregationClausesFromQuery + PHP sanitize).
	 * @param {string} query
	 * @returns {string}
	 */
	function stripAggregationClausesFromQuery(query) {
		const q = String(query || '').trim();
		if (!q) return '';
		const parts = q.split(/\s*;\s*/);
		const filtered = [];
		for (let i = 0; i < parts.length; i += 1) {
			const p = parts[i].trim();
			if (!p) continue;
			if (isAggregationStatement(p)) continue;
			filtered.push(p);
		}
		return filtered.join('; ');
	}

	/**
	 * @param {string} query
	 * @returns {string[]}
	 */
	function aggregationPartsFromQuery(query) {
		const q = String(query || '').trim();
		if (!q) return [];
		const parts = q.split(/\s*;\s*/).map((s) => s.trim()).filter(Boolean);
		const out = [];
		for (let i = 0; i < parts.length; i += 1) {
			if (isAggregationStatement(parts[i])) out.push(parts[i]);
		}
		return out;
	}

	/**
	 * @param {string} fullQuery
	 * @returns {{ scopeOnly: string, aggregationParts: string[] }}
	 */
	function splitScopeAndAggregation(fullQuery) {
		return {
			scopeOnly: stripAggregationClausesFromQuery(fullQuery),
			aggregationParts: aggregationPartsFromQuery(fullQuery),
		};
	}

	/**
	 * Names from the last `freq|count|group Name1, Name2 by …` (aligns with PHP tt_flexicorp_teitok_parse_freq_query_names_from_query).
	 * @param {string} fullQuery
	 * @returns {string[]}
	 */
	function parseFreqQueryNamesFromQuery(fullQuery) {
		const q = String(fullQuery || '').trim();
		if (!q) return [];
		const parts = q.split(/\s*;\s*/).map((s) => s.trim()).filter(Boolean);
		if (!parts.length) return [];
		const last = parts[parts.length - 1];
		const m = last.match(/^(?:freq|count|group)(?:\s+([A-Za-z0-9_,\s]+))?\s+(?:by|match)\s+/i);
		if (!m || !m[1]) return [];
		return m[1]
			.split(',')
			.map((s) => s.trim())
			.filter(Boolean);
	}

	/**
	 * @param {string} scopeOnly
	 * @returns {{ name: string, expr: string, full: string }[]}
	 */
	function pandoNamedAssignPartsFromScope(scopeOnly) {
		const q = String(scopeOnly || '').trim();
		if (!q) return [];
		const parts = q.split(/\s*;\s*/).map((s) => s.trim()).filter(Boolean);
		const out = [];
		for (let i = 0; i < parts.length; i += 1) {
			const p = parts[i];
			const m = p.match(/^([A-Za-z_][A-Za-z0-9_-]*)\s*=\s*(.+)$/s);
			if (m) out.push({ name: m[1], expr: String(m[2]).trim(), full: p });
		}
		return out;
	}

	/**
	 * Parse scope-only program into query rows for UI.
	 * @param {string} scopeOnly
	 * @param {{ defaultSource?: string }} [opts]
	 * @returns {Array<{ id: string, name: string, text: string, source: string, editable: boolean, active: boolean, named: boolean }>}
	 */
	function parseScopeOnlyToQueries(scopeOnly, opts) {
		const src = (opts && opts.defaultSource) || 'session';
		const q = String(scopeOnly || '').trim();
		if (!q) {
			return [];
		}
		const segments = q.split(/\s*;\s*/).map((s) => s.trim()).filter(Boolean);
		const out = [];
		const genId =
			(opts && typeof opts.genId === 'function' && opts.genId) ||
			(() => `sq-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`);
		for (let i = 0; i < segments.length; i += 1) {
			const seg = segments[i];
			const m = seg.match(/^([A-Za-z_][A-Za-z0-9_-]*)\s*=\s*(.+)$/s);
			if (m) {
				out.push({
					id: genId(),
					name: String(m[1]).trim(),
					text: String(m[2]).trim(),
					source: src,
					editable: true,
					active: true,
					named: true,
				});
			} else {
				const label = segments.length === 1 ? 'Last' : `Part ${i + 1}`;
				out.push({
					id: genId(),
					name: label,
					text: seg,
					source: src,
					editable: true,
					active: true,
					named: false,
				});
			}
		}
		return out;
	}

	/**
	 * Compile query rows to scope-only program (no aggregation).
	 * @param {Array<{ name?: string, text?: string, named?: boolean, active?: boolean }>} queries
	 * @param {{ activeOnly?: boolean }} [opts]
	 */
	function compileQueriesToScopeProgram(queries, opts) {
		const activeOnly = !!(opts && opts.activeOnly);
		const list = Array.isArray(queries) ? queries.slice() : [];
		const use = list.filter((q) => {
			if (!q || typeof q !== 'object') return false;
			if (activeOnly && q.active === false) return false;
			return String(q.text || '').trim() !== '';
		});
		if (!use.length) return '';
		const parts = [];
		for (let i = 0; i < use.length; i += 1) {
			const row = use[i];
			const text = String(row.text != null ? row.text : '').trim();
			const name = String(row.name != null ? row.name : '').trim();
			if (row.named === true) {
				const nm = name && /^[A-Za-z_][A-Za-z0-9_-]*$/.test(name) ? name : `Q${i + 1}`;
				parts.push(`${nm} = ${text}`);
			} else {
				parts.push(text);
			}
		}
		return parts.join('; ');
	}

	/**
	 * Active named identifiers in program order (for freq / keyness).
	 * @param {Array<{ name?: string, text?: string, named?: boolean, active?: boolean }>} queries
	 */
	function activeNamedAssignIds(queries) {
		const list = Array.isArray(queries) ? queries : [];
		const names = [];
		for (let i = 0; i < list.length; i += 1) {
			const row = list[i];
			if (!row || row.active === false) continue;
			if (row.named !== true) continue;
			const name = String(row.name || '').trim();
			const text = String(row.text || '').trim();
			if (!name || !text) continue;
			if (!/^[A-Za-z_][A-Za-z0-9_-]*$/.test(name)) continue;
			names.push(name);
		}
		return names;
	}

	/**
	 * @param {string} trailingClause trimmed clause without leading ';' (e.g. "freq A, B by lemma" or "")
	 * @returns {string}
	 */
	function compileScopeProgram(scopeOnly, trailingClause) {
		const a = String(scopeOnly || '').trim();
		const t = String(trailingClause || '').trim();
		if (!a && !t) return '';
		if (!a) return t;
		if (!t) return a;
		return `${a}; ${t}`;
	}

	/**
	 * Build `freq [names] by f1, f2` tail from field keys (Stats / Advanced).
	 * @param {string[]} names optional query names (comma-separated in output)
	 * @param {string|string[]} freqFields
	 */
	function buildFreqClause(names, freqFields) {
		let fields = [];
		if (Array.isArray(freqFields)) {
			fields = freqFields.map((x) => String(x).trim()).filter(Boolean);
		} else {
			fields = String(freqFields || '')
				.split(/\s*,\s*/)
				.map((s) => s.trim())
				.filter(Boolean);
		}
		if (!fields.length) return '';
		const nlist = Array.isArray(names) ? names.map((x) => String(x).trim()).filter(Boolean) : [];
		if (nlist.length >= 1) {
			return `freq ${nlist.join(', ')} by ${fields.join(', ')}`;
		}
		return `freq by ${fields.join(', ')}`;
	}

	/**
	 * @param {string} keynessNamesCsv two names "A, B"
	 * @param {string|string[]} freqFields target for keyness ranking
	 */
	function buildKeynessClause(keynessNamesCsv, freqFields) {
		let fields = [];
		if (Array.isArray(freqFields)) {
			fields = freqFields.map((x) => String(x).trim()).filter(Boolean);
		} else {
			fields = String(freqFields || '')
				.split(/\s*,\s*/)
				.map((s) => s.trim())
				.filter(Boolean);
		}
		const names = String(keynessNamesCsv || '')
			.split(',')
			.map((s) => s.trim())
			.filter(Boolean);
		if (names.length < 2 || !fields.length) return '';
		return `keyness ${names.join(', ')} by ${fields.join(', ')}`;
	}

	/**
	 * @param {string} programText full program
	 * @returns {{ queries: ReturnType<typeof parseScopeOnlyToQueries>, aggregationParts: string[] }}
	 */
	/**
	 * @param {string} programText
	 * @param {() => string} [genIdFn]
	 */
	function parseProgramToScope(programText, genIdFn) {
		const split = splitScopeAndAggregation(programText);
		const genId = typeof genIdFn === 'function' ? genIdFn : null;
		return {
			queries: parseScopeOnlyToQueries(split.scopeOnly, { defaultSource: 'session', genId }),
			aggregationParts: split.aggregationParts,
		};
	}

	/**
	 * @param {object} store
	 * @param {string} trailingClause
	 */
	function compileStoreProgram(store, trailingClause) {
		if (!store || typeof store !== 'object') return String(trailingClause || '').trim();
		const scope = compileQueriesToScopeProgram(store.queries, { activeOnly: false });
		return compileScopeProgram(scope, trailingClause);
	}

	/**
	 * Mutable store with CRUD helpers (Alpine-friendly: mutate store.queries in place).
	 * @param {string} initialProgramText
	 */
	function createSearchScopeStore(initialProgramText) {
		let idSeq = 0;
		const nextId = () => {
			idSeq += 1;
			return `sq-${idSeq}`;
		};
		const parsed = parseProgramToScope(initialProgramText || '', nextId);
		const store = {
			queries: parsed.queries.length ? parsed.queries : [],
			aggregationParts: parsed.aggregationParts || [],
			_nextId: idSeq,
			hydrateFromProgram(programText) {
				const p = parseProgramToScope(programText || '', nextId);
				this.queries = p.queries.length ? p.queries : [];
				this.aggregationParts = p.aggregationParts || [];
			},
			mergeFullProgram(scopeOnlyStr) {
				const agg = Array.isArray(this.aggregationParts) ? this.aggregationParts.filter(Boolean) : [];
				const s = String(scopeOnlyStr || '').trim();
				if (!s && !agg.length) return '';
				if (!agg.length) return s;
				if (!s) return agg.join('; ');
				return `${s}; ${agg.join('; ')}`;
			},
			addQuery(partial) {
				const p = partial && typeof partial === 'object' ? partial : {};
				const name0 = String(p.name || '').trim();
				let name = name0;
				if (!name || !/^[A-Za-z_][A-Za-z0-9_-]*$/.test(name)) {
					let n = this.queries.length + 1;
					name = `Q${n}`;
					const existing = new Set(this.queries.map((q) => String(q && q.name).trim()));
					while (existing.has(name)) {
						n += 1;
						name = `Q${n}`;
					}
				}
				this.queries.push({
					id: nextId(),
					name,
					text: String(p.text != null ? p.text : '').trim(),
					source: String(p.source || 'user').trim() || 'user',
					editable: p.editable !== false,
					active: p.active !== false,
					named: true,
				});
			},
			removeQuery(id) {
				const want = String(id || '').trim();
				this.queries = this.queries.filter((q) => q && String(q.id) !== want);
				if (!this.queries.length) {
					this.queries.push({
						id: nextId(),
						name: 'Last',
						text: '',
						source: 'user',
						editable: true,
						active: true,
						named: false,
					});
				}
			},
			renameQuery(id, nextName) {
				const want = String(id || '').trim();
				const row = this.queries.find((q) => q && String(q.id) === want);
				if (!row) return;
				const nm = String(nextName || '').trim();
				if (!nm) return;
				row.name = nm;
				if (/^[A-Za-z_][A-Za-z0-9_-]*$/.test(nm)) {
					row.named = true;
				}
			},
			setActive(id, active) {
				const want = String(id || '').trim();
				const row = this.queries.find((q) => q && String(q.id) === want);
				if (!row) return;
				row.active = !!active;
			},
			toggleActive(id) {
				const want = String(id || '').trim();
				const row = this.queries.find((q) => q && String(q.id) === want);
				if (!row) return;
				row.active = !row.active;
			},
		};
		// Ensure at least one row when empty
		if (!store.queries.length) {
			store.queries.push({
				id: nextId(),
				name: 'Last',
				text: '',
				source: 'session',
				editable: true,
				active: true,
				named: false,
			});
		}
		return store;
	}

	/**
	 * Scope-only string for Advanced maps/freq (strip AA tails from a full program).
	 * @param {string} programText
	 */
	function programToScopeOnly(programText) {
		return stripAggregationClausesFromQuery(programText);
	}

	/**
	 * First active query as a single statement for maps v1 (named → `Name = expr`, else raw text).
	 */
	function firstActiveQueryStatement(queries) {
		const list = Array.isArray(queries) ? queries : [];
		const row = list.find((q) => q && q.active !== false && String(q.text || '').trim());
		if (!row) return '';
		const text = String(row.text || '').trim();
		if (!text) return '';
		const name = String(row.name || '').trim();
		if (row.named !== false && name && /^[A-Za-z_][A-Za-z0-9_-]*$/.test(name) && name !== 'Last') {
			return `${name} = ${text}`;
		}
		return text;
	}

	window.ttFlexicorpFns.searchScope = {
		isAggregationStatement,
		stripAggregationClausesFromQuery,
		aggregationPartsFromQuery,
		splitScopeAndAggregation,
		parseFreqQueryNamesFromQuery,
		pandoNamedAssignPartsFromScope,
		parseScopeOnlyToQueries,
		parseProgramToScope,
		compileQueriesToScopeProgram,
		compileScopeProgram,
		compileStoreProgram,
		buildFreqClause,
		buildKeynessClause,
		createSearchScopeStore,
		programToScopeOnly,
		firstActiveQueryStatement,
		activeNamedAssignIds,
	};
})();
