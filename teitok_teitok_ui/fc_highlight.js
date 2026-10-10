/**
 * flexicorp TEITOK UI: Query syntax highlighting, hit highlight groups, palette and legend.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpApp() (flexicorp.js)
 * through window.ttFlexicorpCoreParts; `this` is the component.
 */
window.ttFlexicorpCoreParts = window.ttFlexicorpCoreParts || {};
window.ttFlexicorpCoreParts.highlight = function () {
	return {
		/** Whether the current query language has syntax highlighting support. */
		queryHighlightSupported() {
			if (!this.settings) return false;
			const queryLanguage = String(this.settings.queryLanguage || '').toLowerCase();
			return [
				'cwb-cql',
				'cwb',
				'cql',
				'manatee-cql',
				'manatee',
				'pando-cql',
				'pando',
				'clickcql',
				'clickql',
				'bcql',
				'corpusql',
				'pmltq',
				'clickpmltq',
			].includes(queryLanguage);
		},

		/** Build URL for highlight-only AJAX request (returns raw flexicorp highlight JSON). */
		getHighlightRequestUrl(snippet) {
			const url = new URL(window.location.href);
			url.searchParams.set('action', this.action || 'flexicorp');
			url.searchParams.set('ajax', '1');
			url.searchParams.set('highlight_snippet', typeof snippet === 'string' ? snippet : (this.search && this.search.query) || '');
			url.searchParams.set('backend', this.settings && this.settings.backend ? this.settings.backend : 'cqp');
			if (this.settings && this.settings.queryLanguage) {
				url.searchParams.set('query_language', this.settings.queryLanguage);
			}
			if (this.settings && this.settings.corpusFormat) {
				url.searchParams.set('corpus_format', this.settings.corpusFormat);
			}
			if (this.backendOverrides && this.backendOverrides.blacklab_url) url.searchParams.set('blacklab_url', this.backendOverrides.blacklab_url);
			if (this.backendOverrides && this.backendOverrides.blacklab_corpus) url.searchParams.set('blacklab_corpus', this.backendOverrides.blacklab_corpus);
			if (this.backendOverrides && this.backendOverrides.blacklab_user) url.searchParams.set('blacklab_user', this.backendOverrides.blacklab_user);
			if (this.backendOverrides && this.backendOverrides.blacklab_password) url.searchParams.set('blacklab_password', this.backendOverrides.blacklab_password);
			if (this.backendOverrides && this.backendOverrides.blacklab_field) url.searchParams.set('blacklab_field', this.backendOverrides.blacklab_field);
			url.searchParams.set('highlight_format', 'html');
			if (this.settings.queryEngine) {
				url.searchParams.set('query_engine', this.settings.queryEngine);
			}
			if (url.searchParams.get('debug') === '1') url.searchParams.set('debug', '1');
			return url.toString();
		},

		/** Debounce delay (ms) before running highlight – only after a short pause in typing, like clickcql. */
		queryHighlightDebounceMs: 500,

		scheduleQueryHighlight() {
			if (!this.queryHighlightSupported() || !this.search) return;
			const q = this.search.query || '';
			if (!q) return;
			/* Overlay needs the checkbox; results summary can still use highlight after a search (search.ran). */
			if (!this.search.queryHighlightEnabled && !this.search.ran) return;
			/* Show current query as plain black text until the debounced fetch returns */
			this.search.queryHighlightHtml = this.escapeHtmlForPre(q);
			this.search.queryHighlightError = '';
			if (this.search.queryHighlightDebounceId) clearTimeout(this.search.queryHighlightDebounceId);
			const delay = typeof this.queryHighlightDebounceMs === 'number' && this.queryHighlightDebounceMs > 0 ? this.queryHighlightDebounceMs : 500;
			this.search.queryHighlightDebounceId = setTimeout(() => {
				this.search.queryHighlightDebounceId = null;
				this.fetchQueryHighlight();
			}, delay);
		},

		async fetchQueryHighlight() {
			if (!this.queryHighlightSupported() || !this.search) return;
			if (!this.search.queryHighlightEnabled && !this.search.ran) {
				this.search.queryHighlightHtml = '';
				this.search.queryHighlightError = '';
				return;
			}
			const snippet = this.search.query || '';
			const url = this.getHighlightRequestUrl(snippet);
			const applyHighlightHtml = (html) => {
				this.search.queryHighlightHtml = html;
			};
			try {
				const response = await fetch(url, { method: 'GET', headers: { 'X-Requested-With': 'XMLHttpRequest' } });
				const data = await response.json().catch(() => null);
				if (!data) {
					applyHighlightHtml(this.escapeHtmlForPre(snippet));
					this.search.queryHighlightError = '';
					return;
				}
				let result = (data.done && data.done.result) ? data.done.result : (data.result != null ? data.result : null);
				if ((result == null || typeof result !== 'object') && typeof data === 'object' && (data.html != null || data.spans != null || data.markup != null)) {
					result = data;
				}
				const resultObj = result && typeof result === 'object' ? result : null;
				this.search.queryHighlightError = resultObj ? this.getHighlightParseError(resultObj) : '';
				if (resultObj) {
					const errorRange = this.getHighlightErrorRange(resultObj, snippet);
					const tokens = Array.isArray(resultObj.tokens) ? resultObj.tokens : null;
					const spans = Array.isArray(resultObj.spans) ? resultObj.spans : null;
					if (errorRange && tokens && tokens.length && typeof tokens[0].text === 'string' && tokens[0].kind != null) {
						applyHighlightHtml(this.buildHighlightHtmlFromTokens(tokens, errorRange, snippet));
						return;
					}
					if (errorRange && spans && spans.length) {
						applyHighlightHtml(this.buildHighlightHtmlFromSpans(snippet, spans, errorRange));
						return;
					}
					if (errorRange) {
						applyHighlightHtml(this.buildPlainHighlightHtml(snippet, errorRange));
						return;
					}
					const html = typeof resultObj.html === 'string' && resultObj.html.length > 0 ? resultObj.html
						: (typeof resultObj.markup === 'string' && resultObj.markup.length > 0 ? resultObj.markup
							: (typeof resultObj.output === 'string' && resultObj.output.length > 0 ? resultObj.output : null));
					if (html) {
						if (this.highlightHtmlMatchesSource(snippet, html)) {
							applyHighlightHtml(html);
							return;
						}
						// Backend returned highlight HTML but it does not match the source query.
						// Try token/span fallback only when backend provided complete coverage of
						// the original query; otherwise keep plain text to avoid half-highlights.
						if (
							tokens &&
							tokens.length &&
							typeof tokens[0].text === 'string' &&
							tokens[0].kind != null &&
							this.highlightTokensMatchSource(snippet, tokens)
						) {
							applyHighlightHtml(this.buildHighlightHtmlFromTokens(tokens, null, snippet));
							return;
						}
						if (spans && spans.length && this.highlightSpansMatchSource(snippet, spans)) {
							applyHighlightHtml(this.buildHighlightHtmlFromSpans(snippet, spans));
							return;
						}
						applyHighlightHtml(this.escapeHtmlForPre(snippet));
						return;
					}
					if (tokens && tokens.length && typeof tokens[0].text === 'string' && tokens[0].kind != null) {
						applyHighlightHtml(this.buildHighlightHtmlFromTokens(tokens, null, snippet));
						return;
					}
					if (spans && spans.length) {
						applyHighlightHtml(this.buildHighlightHtmlFromSpans(snippet, spans));
						return;
					}
				}
				if (typeof data.done === 'string' && data.done.length > 0) {
					applyHighlightHtml(data.done);
					this.search.queryHighlightError = '';
					return;
				}
				applyHighlightHtml(this.escapeHtmlForPre(snippet));
				this.search.queryHighlightError = '';
			} catch (err) {
				applyHighlightHtml(this.escapeHtmlForPre(snippet));
				this.search.queryHighlightError = '';
			}
		},

		highlightHtmlMatchesSource(sourceQuery, highlightHtml) {
			const normalize = (text) => String(text || '').replace(/\r\n/g, '\n').trim();
			const source = normalize(sourceQuery);
			if (!source) return true;
			const probe = document.createElement('div');
			probe.innerHTML = typeof highlightHtml === 'string' ? highlightHtml : '';
			const highlighted = normalize(probe.textContent || '');
			return highlighted === source;
		},

		highlightTokensMatchSource(sourceQuery, tokens) {
			if (typeof sourceQuery !== 'string') return false;
			if (!Array.isArray(tokens) || !tokens.length) return false;
			const joined = tokens.map((t) => (t && typeof t.text === 'string' ? t.text : String(t && t.text != null ? t.text : ''))).join('');
			return joined === sourceQuery;
		},

		highlightSpansMatchSource(sourceQuery, spans) {
			if (typeof sourceQuery !== 'string') return false;
			if (!Array.isArray(spans) || !spans.length) return false;
			let last = 0;
			for (const span of spans) {
				const start = Number(span && span.start);
				const end = Number(span && span.end);
				if (!Number.isFinite(start) || !Number.isFinite(end)) return false;
				if (start < 0 || end < start || end > sourceQuery.length) return false;
				if (start < last) return false;
				last = end;
			}
			return true;
		},

		getHighlightParseError(resultObj) {
			if (!resultObj || typeof resultObj !== 'object') return '';
			const raw = typeof resultObj.parse_error === 'string' ? resultObj.parse_error.trim() : '';
			if (!raw) return '';
			const errorType = typeof resultObj.parse_error_type === 'string' ? resultObj.parse_error_type.trim().toLowerCase() : '';
			const line = Number.isFinite(resultObj.parse_error_line) ? Number(resultObj.parse_error_line) : null;
			const column = Number.isFinite(resultObj.parse_error_column) ? Number(resultObj.parse_error_column) : null;
			const where = (line !== null && column !== null)
				? ` at line ${line}, column ${column}`
				: (line !== null ? ` at line ${line}` : '');
			if (errorType === 'syntax') return `Syntax error${where}: ${raw}`;
			return `Query error${where}: ${raw}`;
		},

		escapeHtmlForPre(text) {
			if (typeof text !== 'string') return '';
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		},

		escapeHtmlForAttr(text) {
			// Attribute-safe escaping for strings we embed into HTML snippets.
			// We use the same escaping strategy as for <pre> since it escapes quotes as entities too.
			return this.escapeHtmlForPre(typeof text === 'string' ? text : '');
		},

		getHighlightErrorRange(resultObj, text) {
			if (!resultObj || typeof resultObj !== 'object') return null;
			const start = Number.isFinite(resultObj.parse_error_offset) ? Number(resultObj.parse_error_offset) : null;
			if (start === null || start < 0) return null;
			const textLength = typeof text === 'string' ? text.length : 0;
			const explicitEnd = Number.isFinite(resultObj.parse_error_end_offset) ? Number(resultObj.parse_error_end_offset) : null;
			let end = explicitEnd;
			if (end === null || end < start) {
				end = start < textLength ? start + 1 : start;
			}
			return {
				start,
				end,
			};
		},

		renderHighlightedSegment(text, className, segmentStart, errorRange) {
			const safeText = this.escapeHtmlForPre(text);
			if (!errorRange) {
				return `<span class="${className}">${safeText}</span>`;
			}
			const segmentEnd = segmentStart + text.length;
			const start = Number(errorRange.start);
			const end = Number(errorRange.end);
			if (!Number.isFinite(start) || !Number.isFinite(end)) {
				return `<span class="${className}">${safeText}</span>`;
			}
			if (end > start && start <= segmentStart && end >= segmentEnd && text.length > 0) {
				return `<span class="${className} pegerror">${safeText}</span>`;
			}
			if (start === end) {
				if (start < segmentStart || start > segmentEnd) {
					return `<span class="${className}">${safeText}</span>`;
				}
				const local = Math.max(0, Math.min(text.length, start - segmentStart));
				const before = this.escapeHtmlForPre(text.slice(0, local));
				const after = this.escapeHtmlForPre(text.slice(local));
				return `<span class="${className}">${before}<span class="flexicorp-hl-error-marker" aria-hidden="true"></span>${after}</span>`;
			}
			if (end <= segmentStart || start >= segmentEnd) {
				return `<span class="${className}">${safeText}</span>`;
			}
			const localStart = Math.max(0, start - segmentStart);
			const localEnd = Math.min(text.length, end - segmentStart);
			const before = this.escapeHtmlForPre(text.slice(0, localStart));
			const marked = this.escapeHtmlForPre(text.slice(localStart, localEnd));
			const after = this.escapeHtmlForPre(text.slice(localEnd));
			return `<span class="${className}">${before}<span class="flexicorp-hl-error">${marked}</span>${after}</span>`;
		},

		buildPlainHighlightHtml(text, errorRange, segmentStart = 0) {
			return this.renderHighlightedSegment(typeof text === 'string' ? text : '', 'flexicorp-hl-token', segmentStart, errorRange);
		},

		/** Build highlight HTML from flexicorp highlight API tokens: [{ text, kind }, ...]. */
		buildHighlightHtmlFromTokens(tokens, errorRange = null, originalSnippet = '') {
			if (!Array.isArray(tokens) || !tokens.length) return '';
			const parts = [];
			let offset = 0;
			const nextNonSpaceIndex = (idx) => {
				for (let i = idx + 1; i < tokens.length; i += 1) {
					const nt = tokens[i];
					const txt = typeof nt.text === 'string' ? nt.text : String(nt && nt.text != null ? nt.text : '');
					if (!/^\s*$/.test(txt)) return i;
				}
				return -1;
			};
			const keywordSet = new Set(['keyness', 'freq', 'count', 'dist', 'coll', 'dcoll', 'by', 'match', 'group', 'sort', 'tabulate', 'show', 'size']);
			for (let idx = 0; idx < tokens.length; idx += 1) {
				const t = tokens[idx];
				const text = typeof t.text === 'string' ? t.text : String(t.text ?? '');
				const kind = (t.kind != null && t.kind !== '') ? String(t.kind) : 'token';
				let safeClass = kind.replace(/[^a-z0-9_-]/gi, '') || 'token';
				const trimmed = text.trim();
				const low = trimmed.toLowerCase();
				if (trimmed) {
					if (keywordSet.has(low)) {
						safeClass = 'keyword';
					} else if (/^[A-Z][A-Z0-9_]*$/.test(trimmed)) {
						// Named-query variables (A, B, FOCUS, ...)
						safeClass = 'variable';
					} else if (/^[A-Za-z_][A-Za-z0-9_]*$/.test(trimmed)) {
						const ni = nextNonSpaceIndex(idx);
						if (ni >= 0) {
							const ntext = typeof tokens[ni].text === 'string' ? tokens[ni].text : String(tokens[ni].text ?? '');
							if (ntext === '(') safeClass = 'function';
						}
					}
				}
				parts.push(this.renderHighlightedSegment(text, 'flexicorp-hl-' + safeClass, offset, errorRange));
				offset += text.length;
			}
			if (errorRange && errorRange.start === errorRange.end && errorRange.start === offset) {
				parts.push('<span class="flexicorp-hl-error-marker" aria-hidden="true"></span>');
			}
			if (typeof originalSnippet === 'string' && offset < originalSnippet.length) {
				const remainder = originalSnippet.slice(offset);
				parts.push(this.renderHighlightedSegment(remainder, 'flexicorp-hl-token', offset, errorRange));
			}
			return parts.join('');
		},

		buildHighlightHtmlFromSpans(text, spans, errorRange = null) {
			if (typeof text !== 'string' || !Array.isArray(spans) || !spans.length) return this.escapeHtmlForPre(text);
			const parts = [];
			let last = 0;
			for (const span of spans) {
				const start = Number(span.start);
				const end = Number(span.end);
				if (start > last) parts.push(this.buildPlainHighlightHtml(text.slice(last, start), errorRange, last));
				const cls = span.class || span.type || 'token';
				const segment = text.slice(start, end);
				parts.push(this.renderHighlightedSegment(segment, 'flexicorp-hl-' + String(cls).replace(/[^a-z0-9_-]/gi, ''), start, errorRange));
				last = end;
			}
			if (last < text.length) parts.push(this.renderHighlightedSegment(text.slice(last), 'flexicorp-hl-token', last, errorRange));
			if (errorRange && errorRange.start === errorRange.end && errorRange.start === text.length) {
				parts.push('<span class="flexicorp-hl-error-marker" aria-hidden="true"></span>');
			}
			return parts.join('');
		},

		/** HTML to show in the query highlight overlay (highlighted or plain escaped). Only used when highlight is enabled. */
		queryHighlightDisplayHtml() {
			if (!this.search || !this.search.queryHighlightEnabled) return '';
			if (this.search.queryHighlightHtml) return this.search.queryHighlightHtml;
			return this.escapeHtmlForPre(this.search.query ? this.search.query : '');
		},

		/** HTML for the collapsed query line: highlighted if available, otherwise escaped plain query. Always returns something when there is a query. */
		collapsedQueryDisplayHtml() {
			if (!this.search) return '';
			if (this.search.queryHighlightHtml) return this.search.queryHighlightHtml;
			return this.escapeHtmlForPre(this.search.query ? this.search.query : '');
		},

		/** HTML for the query in the results summary: same highlight as the query box when available. */
		searchSummaryQueryPlainHtml() {
			if (!this.search) return '';
			const q = this.search.query ? this.search.query : '';
			if (!q) return '';
			if (this.queryHighlightSupported() && this.search.queryHighlightHtml) {
				const probe = document.createElement('div');
				probe.innerHTML = String(this.search.queryHighlightHtml);
				const normalizeChars = (text) => String(text || '')
					.replace(/\r\n/g, '\n')
					.replace(/\s+/g, '');
				const highlightedText = normalizeChars(probe.textContent || '');
				const sourceText = normalizeChars(q);
				// Keep colorized summary unless highlight output is clearly truncated.
				const minLen = Math.floor(sourceText.length * 0.8);
				if (highlightedText === sourceText || highlightedText.length >= minLen) {
					return this.search.queryHighlightHtml;
				}
			}
			return this.escapeHtmlForPre(q);
		},

		syncQueryHighlightScroll(evtOrEl) {
			const textareaEl = evtOrEl && evtOrEl.target ? evtOrEl.target : evtOrEl;
			if (!textareaEl || textareaEl.tagName !== 'TEXTAREA') return;
			const wrap = textareaEl.closest('.flexicorp-query-wrap');
			const pre = wrap && wrap.querySelector('.flexicorp-query-highlight');
			if (pre) pre.scrollTop = textareaEl.scrollTop;
		},

		getHitMatchIds(hit) {
			if (!hit || typeof hit !== 'object') return [];
			if (hit.highlight_map && Array.isArray(hit.highlight_map.match) && hit.highlight_map.match.length) {
				return hit.highlight_map.match.filter(Boolean);
			}
			if (hit.highlight_map && hit.highlight_map.default && Array.isArray(hit.highlight_map.default.tok_ids)) {
				return hit.highlight_map.default.tok_ids.filter(Boolean);
			}
			if (Array.isArray(hit.toks) && hit.toks.length) return hit.toks.filter(Boolean);
			return [];
		},

		getResultHighlightPaletteByGroup() {
			const out = {};
			const result = this.search && this.search.response ? this.search.response.result : null;
			const entries = result && Array.isArray(result.legend) && result.legend.length
				? this.sortHighlightEntriesByGroupId(result.legend)
				: (result && Array.isArray(result.groups) && result.groups.length ? this.sortHighlightEntriesByGroupId(result.groups) : []);
			const ids = new Set(
				entries
					.map((e) => String((e && e.id) || '').trim())
					.filter(Boolean),
			);
			entries.forEach((entry, idx) => {
				const palette = this.getGroupPalette(idx);
				const id = String((entry && entry.id) || '').trim();
				const name = String((entry && entry.name) || '').trim();
				const key = String((entry && entry.key) || '').trim();
				const label = String((entry && entry.label) || '').trim();
				// Positional id always wins (t1 → palette 0, t2 → palette 1, …).
				if (id) out[id] = palette.className;
				// User aliases / labels (a, noun, …). Never let a stale auto-name like "t1" on
				// group t2 overwrite t1's palette — that turns every match token the same colour.
				[name, key, label].forEach((k) => {
					if (!k || k === id) return;
					if (/^t\d+$/i.test(k) && ids.has(k) && k !== id) return;
					out[k] = palette.className;
				});
			});
			// Fallback: keep alias colors stable from query order (a,b,...) even if backend legend ids
			// use different keys (e.g. t1/t2 or mixed naming across source/target sides).
			const aliasOrder = this.getAlignedAliasOrderFromQuery(this.getExecutedSearchQueryText());
			aliasOrder.forEach((alias, idx) => {
				const a = String(alias || '').trim();
				if (!a) return;
				if (!out[a]) out[a] = this.getGroupPalette(idx).className;
			});
			return out;
		},

		getAlignedHitSide(hit, sideHint) {
			const hinted = String(sideHint || '').trim().toLowerCase();
			if (hinted === 'source' || hinted === 'target') return hinted;
			if (!hit || typeof hit !== 'object') return '';
			const sideA = hit.aligned_to && typeof hit.aligned_to === 'object' ? String(hit.aligned_to.side || '').trim().toLowerCase() : '';
			if (sideA === 'source' || sideA === 'target') return sideA;
			const sideB = String(hit.aligned_side || hit.side || '').trim().toLowerCase();
			if (sideB === 'source' || sideB === 'target') return sideB;
			return '';
		},

		resolveGroupPaletteClass(groupName, fallbackIndex, hit, paletteByGroup, nonMatchGroupCount, sideHint) {
			const name = String(groupName || '').trim();
			const side = this.getAlignedHitSide(hit, sideHint);
			const aliasOrder = this.getAlignedAliasOrderFromQuery(this.getExecutedSearchQueryText());
			// Critical: aligned side payloads may reuse local names like t1 on both sides.
			// In that case, global paletteByGroup(t1)=A is ambiguous; map by side first.
			if (this.searchHasAlignedHits() && Number(nonMatchGroupCount) === 1 && aliasOrder.length >= 2 && /^t1$/i.test(name)) {
				if (side === 'source') {
					return this.getGroupPalette(0).className;
				}
				if (side === 'target') {
					return this.getGroupPalette(1).className;
				}
			}
			if (name && paletteByGroup && paletteByGroup[name]) {
				return paletteByGroup[name];
			}
			const tMatch = name.match(/^t(\d+)$/i);
			if (tMatch) {
				return this.getGroupPalette(Math.max(0, Number(tMatch[1]) - 1)).className;
			}
			if (name && aliasOrder.length) {
				const aliasIdx = aliasOrder.findIndex((a) => String(a || '').trim() === name);
				if (aliasIdx >= 0) {
					return this.getGroupPalette(aliasIdx).className;
				}
			}
			// Aligned side rows often carry one logical group only; map by side so target stays "B".
			if (this.searchHasAlignedHits() && Number(nonMatchGroupCount) === 1 && aliasOrder.length >= 2 && name !== 'match') {
				if (side === 'source') {
					return this.getGroupPalette(0).className;
				}
				if (side === 'target') {
					return this.getGroupPalette(1).className;
				}
			}
			return this.getGroupPalette(fallbackIndex).className;
		},

		/** Return list of { name, ids } from hit.highlight_map (match + default.tok_ids + any named groups). */
		getHighlightMapGroups(hit, sideHint) {
			if (!hit || typeof hit !== 'object' || !hit.highlight_map || typeof hit.highlight_map !== 'object') return [];
			const out = [];
			const hm = hit.highlight_map;
			const paletteByGroup = this.getResultHighlightPaletteByGroup();
			const countNonMatch = () => {
				if (Array.isArray(hm.groups) && hm.groups.length) {
					return hm.groups.filter((g) => g && typeof g === 'object' && String(g.name || g.id || '').trim().toLowerCase() !== 'match').length;
				}
				return Object.keys(hm).filter((k) => k !== 'match' && k !== 'default' && k !== 'groups').length;
			};
			const nonMatchGroupCount = countNonMatch();
			if (Array.isArray(hm.groups) && hm.groups.length) {
				hm.groups.forEach((group, idx) => {
					if (!group || typeof group !== 'object') return;
					const ids = Array.isArray(group.tok_ids) ? group.tok_ids.filter(Boolean) : [];
					if (!ids.length) return;
					const palette = this.getGroupPalette(idx);
					// Keep palette stable by query-group name (e.g. a/b/t2), not by per-result-group id.
					const groupKey = String(group.name || group.id || `g${idx + 1}`).trim();
					const paletteClass = this.resolveGroupPaletteClass(groupKey, idx, hit, paletteByGroup, nonMatchGroupCount, sideHint);
					out.push({
						id: group.id || group.name || `g${idx + 1}`,
						name: group.name || group.id || `g${idx + 1}`,
						ids,
						querySpan: typeof group.query_span === 'string' ? group.query_span : '',
						resultGroup: Number.isFinite(Number(group.result_group)) ? Number(group.result_group) : null,
						color: group.color || null,
						paletteClass,
						paletteColor: palette.chipColor,
						paletteTextColor: palette.chipTextColor,
						order: idx,
					});
				});
				if (out.length) {
					const sorted = this.sortHighlightEntriesByGroupId(out);
					sorted.forEach((g, idx) => {
						const palette = this.getGroupPalette(idx);
						if (!g.paletteClass) g.paletteClass = palette.className;
						g.paletteColor = palette.chipColor;
						g.paletteTextColor = palette.chipTextColor;
						g.order = idx;
					});
					return sorted;
				}
			}
			if (Array.isArray(hm.match) && hm.match.length) out.push({ name: 'match', ids: hm.match, paletteClass: 'match', paletteColor: '#b08900', paletteTextColor: '#ffffff', order: 0 });
			else if (hm.default && Array.isArray(hm.default.tok_ids) && hm.default.tok_ids.length) out.push({ name: 'match', ids: hm.default.tok_ids, paletteClass: 'match', paletteColor: '#b08900', paletteTextColor: '#ffffff', order: 0 });
			const keys = Object.keys(hm).filter(k => k !== 'match' && k !== 'default' && k !== 'groups');
			keys.sort((a, b) => this.compareGroupIds(a, b));
			keys.forEach((key, idx) => {
				const val = hm[key];
				const palette = this.getGroupPalette(idx);
				const paletteClass = this.resolveGroupPaletteClass(String(key).trim(), idx, hit, paletteByGroup, nonMatchGroupCount, sideHint);
				if (Array.isArray(val) && val.length) out.push({ name: key, ids: val, paletteClass, paletteColor: palette.chipColor, paletteTextColor: palette.chipTextColor, order: idx });
				else if (val && typeof val === 'object' && Array.isArray(val.tok_ids)) out.push({ name: key, ids: val.tok_ids, querySpan: typeof val.query_span === 'string' ? val.query_span : '', color: val.color || null, paletteClass, paletteColor: palette.chipColor, paletteTextColor: palette.chipTextColor, order: idx });
			});
			return out;
		},

		compareGroupIds(a, b) {
			const ax = String(a || '').match(/^t(\d+)$/i);
			const bx = String(b || '').match(/^t(\d+)$/i);
			if (ax && bx) return Number(ax[1]) - Number(bx[1]);
			return String(a || '').localeCompare(String(b || ''));
		},

		/**
		 * Keep legend / highlight groups in t1, t2, … order. Named aliases (e.g. noun:t2) must not
		 * reorder entries relative to token position or palette indices no longer match the XML hits.
		 */
		sortHighlightEntriesByGroupId(entries) {
			if (!Array.isArray(entries) || entries.length < 2) return entries ? entries.slice() : [];
			const copy = entries.slice();
			if (!copy.every((e) => e && /^t\d+$/i.test(String(e.id || '')))) return copy;
			copy.sort((a, b) => this.compareGroupIds(String(a.id || ''), String(b.id || '')));
			return copy;
		},

		getGroupPalette(index) {
			const palette = [
				{ className: 'A', chipColor: '#ff6b6b', chipTextColor: '#ffffff' },
				{ className: 'B', chipColor: '#4c6ef5', chipTextColor: '#ffffff' },
				{ className: 'C', chipColor: '#2f9e44', chipTextColor: '#ffffff' },
				{ className: 'D', chipColor: '#7950f2', chipTextColor: '#ffffff' },
				{ className: 'E', chipColor: '#e67700', chipTextColor: '#ffffff' },
			];
			const safeIndex = Number.isFinite(index) && index >= 0 ? index : 0;
			return palette[safeIndex % palette.length];
		},

		extractQueryTokenClauses(query, queryLang) {
			if (typeof query !== 'string' || !query) return [];
			const lang = String(queryLang || '').toLowerCase();
			if (lang === 'pmltq' || lang === 'clickpmltq') {
				const spans = [];
				const stack = [];
				let start = -1;
				let quote = null;
				for (let i = 0; i < query.length; i += 1) {
					const ch = query[i];
					if (quote) {
						if (ch === '\\') {
							i += 1;
							continue;
						}
						if (ch === quote) quote = null;
						continue;
					}
					if (ch === '"' || ch === "'") {
						quote = ch;
						continue;
					}
					if (ch === '[') {
						stack.push(i);
						continue;
					}
					if (ch === ']' && stack.length) {
						const clauseStart = stack.pop();
						if (Number.isInteger(clauseStart) && clauseStart >= 0) {
							spans.push({
								start: clauseStart,
								end: i + 1,
								depth: stack.length + 1,
							});
						}
					}
				}
				return spans
					.sort((a, b) => (a.start - b.start) || (a.depth - b.depth))
					.map((span) => {
						let clause = query.slice(span.start, span.end).trim();
						if (clause.startsWith('[') && clause.endsWith(']')) {
							clause = clause.slice(1, -1).trim();
						}
						return clause;
					})
					.filter(Boolean);
			}
			const clauses = [];
			let depth = 0;
			let start = -1;
			let quote = null;
			for (let i = 0; i < query.length; i += 1) {
				const ch = query[i];
				if (quote) {
					if (ch === '\\') {
						i += 1;
						continue;
					}
					if (ch === quote) quote = null;
					continue;
				}
				if (ch === '"' || ch === "'") {
					quote = ch;
					continue;
				}
				if (ch === '[') {
					if (depth === 0) start = i + 1;
					depth += 1;
					continue;
				}
				if (ch === ']' && depth > 0) {
					depth -= 1;
					if (depth === 0 && start >= 0) {
						const clause = query.slice(start, i).trim();
						if (clause) clauses.push(clause);
						start = -1;
					}
				}
			}
			return clauses;
		},

		getLegendLabel(entry, tokenClauses) {
			const rawName = entry && (entry.name || entry.id || entry.key) ? String(entry.name || entry.id || entry.key) : '';
			const match = rawName.match(/^t(\d+)$/i);
			if (match) {
				const idx = Number(match[1]) - 1;
				if (tokenClauses[idx]) return tokenClauses[idx];
			}
			if (entry && typeof entry.label === 'string' && entry.label.trim()) return entry.label.trim();
			if (entry && typeof entry.querySpan === 'string' && entry.querySpan.trim()) return entry.querySpan.trim();
			if (rawName === 'match' && tokenClauses.length === 1) return tokenClauses[0];
			return rawName === 'match' ? 'Match' : rawName;
		},

		/** Sanitize group name for use in CSS class (alphanumeric, underscore, hyphen). */
		sanitizeGroupClass(name) {
			return String(name || '').replace(/[^a-z0-9_-]/gi, '') || 'g';
		},

		/** Legend entries for highlight groups: from result.legend / result.groups or derived from first hit. */
		searchHighlightLegend() {
			const result = this.search && this.search.response ? this.search.response.result : null;
			const executedQuery = this.search && typeof this.search.query === 'string' && this.search.query
				? this.search.query
				: (result && typeof result.query === 'string' ? result.query : '');
			const queryLang = result && typeof result.query_lang === 'string' ? result.query_lang : '';
			const tokenClauses = this.extractQueryTokenClauses(executedQuery, queryLang);
			const querySpanForName = (name) => {
				const n = String(name || '').trim();
				if (!n) return '';
				const fromAlias = this.getAliasClauseBody(executedQuery, n);
				if (fromAlias) return fromAlias.trim();
				return '';
			};
			const addSafe = (entry) => ({
				...entry,
				safeClass: this.sanitizeGroupClass(entry.name || entry.key || entry.id),
			});
			if (result && Array.isArray(result.legend) && result.legend.length) {
				const legendOrdered = this.sortHighlightEntriesByGroupId(result.legend);
				return legendOrdered.map((entry, idx) => {
					const palette = this.getGroupPalette(idx);
					return addSafe({
						name: entry.name || entry.key || entry.id,
						label: this.getLegendLabel(entry, tokenClauses),
						querySpan: (typeof entry.querySpan === 'string' ? entry.querySpan : (typeof entry.query_span === 'string' ? entry.query_span : '')) || querySpanForName(entry.name || entry.key || entry.id),
						color: entry.color || palette.chipColor,
						textColor: entry.textColor || palette.chipTextColor,
						paletteClass: palette.className,
					});
				});
			}
			if (result && Array.isArray(result.groups) && result.groups.length) {
				const groupsOrdered = this.sortHighlightEntriesByGroupId(result.groups);
				return groupsOrdered.map((entry, idx) => {
					const palette = this.getGroupPalette(idx);
					return addSafe({
						name: entry.name || entry.id,
						label: this.getLegendLabel(entry, tokenClauses),
						querySpan: (typeof entry.querySpan === 'string' ? entry.querySpan : (typeof entry.query_span === 'string' ? entry.query_span : '')) || querySpanForName(entry.name || entry.id),
						color: entry.color || palette.chipColor,
						textColor: entry.textColor || palette.chipTextColor,
						paletteClass: palette.className,
					});
				});
			}
			const hits = this.search && Array.isArray(this.search.hits) ? this.search.hits : [];
			const first = hits.find(h => this.getHighlightMapGroups(h).length > 0);
			if (!first) return [];
			return this.getHighlightMapGroups(first).map((group, idx) => addSafe({
				name: group.name || group.id,
				label: this.getLegendLabel(group, tokenClauses),
				querySpan: (typeof group.querySpan === 'string' ? group.querySpan : '') || querySpanForName(group.name || group.id),
				color: group.color || group.paletteColor || this.getGroupPalette(idx).chipColor,
				textColor: group.paletteTextColor || this.getGroupPalette(idx).chipTextColor,
				paletteClass: group.paletteClass || this.getGroupPalette(idx).className,
			}));
		},

		legendEntryTooltip(entry) {
			if (!entry || typeof entry !== 'object') return '';
			const name = String(entry.name || '').trim();
			const label = String(entry.label || '').trim();
			const querySpan = String(entry.querySpan || '').trim();
			if (querySpan) {
				if (name && label && name !== label) return `Query group ${name}: ${label} [${querySpan}]`;
				if (label) return `Query group ${label} [${querySpan}]`;
				if (name) return `Query group ${name} [${querySpan}]`;
				return `Query group [${querySpan}]`;
			}
			if (name && label && name !== label) return `Query group ${name}: ${label}`;
			if (label) return `Query group ${label}`;
			if (name) return `Query group ${name}`;
			return 'Query group';
		},

		_showLegendTooltip(entry, ev) {
			const text = this.legendEntryTooltip(entry);
			if (!text) {
				this._hideLegendTooltip();
				return;
			}
			const x = ev && Number.isFinite(ev.clientX) ? ev.clientX : 0;
			const y = ev && Number.isFinite(ev.clientY) ? ev.clientY : 0;
			const offset = 12;
			this._legendTooltip = {
				visible: true,
				text,
				left: Math.max(8, x + offset),
				top: Math.max(8, y + offset),
			};
		},

		_moveLegendTooltip(ev) {
			if (!this._legendTooltip || !this._legendTooltip.visible) return;
			const x = ev && Number.isFinite(ev.clientX) ? ev.clientX : this._legendTooltip.left || 0;
			const y = ev && Number.isFinite(ev.clientY) ? ev.clientY : this._legendTooltip.top || 0;
			const offset = 12;
			this._legendTooltip.left = Math.max(8, x + offset);
			this._legendTooltip.top = Math.max(8, y + offset);
		},

		_hideLegendTooltip() {
			this._legendTooltip = { visible: false, text: '', left: 0, top: 0 };
		},

		legendTooltipVisible() {
			return !!(this._legendTooltip && this._legendTooltip.visible && this._legendTooltip.text);
		},

		legendTooltipText() {
			return this._legendTooltip && this._legendTooltip.text ? this._legendTooltip.text : '';
		},

		legendTooltipStyle() {
			const tip = this._legendTooltip || {};
			const left = Number.isFinite(tip.left) ? tip.left : 0;
			const top = Number.isFinite(tip.top) ? tip.top : 0;
			return `left:${left}px;top:${top}px;`;
		},

		/** Map highlight id -> classes, plus common alias keys so CQP ids match TEI xml:id / prefixed ids. */
		_expandHighlightIdLookup(idToClasses) {
			const out = Object.assign({}, idToClasses);
			for (const key of Object.keys(idToClasses)) {
				if (!key) continue;
				const s = String(key).trim();
				const cls = idToClasses[key];
				const noPrefix = s.replace(/^[wt](?=\d)/i, '');
				if (noPrefix && noPrefix !== s && !out[noPrefix]) out[noPrefix] = cls;
				if (/^\d+$/.test(s)) {
					if (!out[`w${s}`]) out[`w${s}`] = cls;
					if (!out[`t${s}`]) out[`t${s}`] = cls;
				}
			}
			return out;
		},

		_expandMatchIdSet(matchSet) {
			const out = new Set(matchSet);
			for (const id of [...out]) {
				const s = String(id).trim();
				if (!s) continue;
				const noPrefix = s.replace(/^[wt](?=\d)/i, '');
				if (noPrefix && noPrefix !== s) out.add(noPrefix);
				if (/^\d+$/.test(s)) {
					out.add(`w${s}`);
					out.add(`t${s}`);
				}
				// Some corpora expose decomposed sub-token ids (e.g. d-255-1) while
				// context fragments only carry the parent token id (e.g. w-255).
				const dtokParent = s.match(/^d-([^-]+)-\d+$/i);
				if (dtokParent && dtokParent[1]) {
					const base = dtokParent[1];
					out.add(base);
					out.add(`w-${base}`);
					out.add(`t-${base}`);
					out.add(`w${base}`);
					out.add(`t${base}`);
				}
			}
			return out;
		},

		/** Same class rules as getRenderedXmlContext — used for full context and XML KWIC columns. */
		applyHighlightTokenClassesToContainer(container, hit, sideHint) {
			if (!container || !container.querySelectorAll) return;
			if (this.hitIsSentenceAligned(hit, sideHint)) {
				// For sentence-aligned target rows (<s>), the full sentence is already the result.
				// Keep it unhighlighted to avoid misleading token/sentence emphasis.
				return;
			}
			const groups = this.getHighlightMapGroups(hit, sideHint);
			const idToClasses = {};
			const resultGroupValues = new Set();
			groups.forEach(({ resultGroup }) => {
				const n = Number(resultGroup);
				if (Number.isFinite(n) && n >= 0) resultGroupValues.add(n);
			});
			const hasMultipleResultGroups = resultGroupValues.size > 1;
			groups.forEach(({ name, ids, paletteClass, resultGroup }) => {
				const cls = name === 'match'
					? 'flexicorp-hit-token--match'
					: (paletteClass ? 'flexicorp-hit-token--group-' + paletteClass : 'flexicorp-hit-token--group-' + String(name).replace(/[^a-z0-9_-]/gi, ''));
				const n = Number(resultGroup);
				const hasResultGroup = Number.isFinite(n) && n >= 0;
				const resultGroupClass = hasResultGroup ? 'flexicorp-hit-token--result-group-' + String(n) : '';
				const resultGroupSlotClass = hasResultGroup ? 'flexicorp-hit-token--result-group-slot-' + String(Math.abs(n) % 6) : '';
				const resultGroupedClass = hasResultGroup && hasMultipleResultGroups ? 'flexicorp-hit-token--result-grouped' : '';
				(Array.isArray(ids) ? ids : []).forEach(id => {
					if (!id) return;
					if (!idToClasses[id]) idToClasses[id] = [];
					idToClasses[id].push(cls);
					if (resultGroupClass) idToClasses[id].push(resultGroupClass);
					if (resultGroupSlotClass) idToClasses[id].push(resultGroupSlotClass);
					if (resultGroupedClass) idToClasses[id].push(resultGroupedClass);
				});
			});
			const lookup = this._expandHighlightIdLookup(idToClasses);
			Array.from(container.querySelectorAll('tok, dtok, mtok')).forEach(node => {
				const aliases = this.xmlElementStableIds(node);
				const classSet = new Set();
				aliases.forEach((a) => {
					const classes = lookup[a];
					if (classes) classes.forEach((c) => classSet.add(c));
				});
				// Only fall back to tuid when no stable XML id matched. This avoids source/target
				// palette collisions when both sides happen to share numeric tuids.
				if (!classSet.size) {
					const tuid = this.xmlElementTuid(node);
					if (tuid) {
						const classes = lookup[tuid];
						if (classes) classes.forEach((c) => classSet.add(c));
					}
				}
				classSet.forEach((c) => node.classList.add(c));
			});
		},
	};
};
