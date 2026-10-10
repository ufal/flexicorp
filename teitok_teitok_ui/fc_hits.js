/**
 * flexicorp TEITOK UI: Result rendering: aligned hits, summary, tables, XML context, KWIC parts, merged hits.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpApp() (flexicorp.js)
 * through window.ttFlexicorpCoreParts; `this` is the component.
 */
window.ttFlexicorpCoreParts = window.ttFlexicorpCoreParts || {};
window.ttFlexicorpCoreParts.hits = function () {
	/**
	 * Memo for values derived from one search result, kept outside the Alpine component so that
	 * filling it does not trigger re-renders. Keys are the raw (unproxied) hits array or hit object;
	 * a new search, or a further page, replaces the hits array, so entries expire with it.
	 * Without it the results table rebuilt the merged hit list (re-parsing every XML fragment)
	 * for each x-show/x-bind that asked for it: about 50 times for 25 aligned rows.
	 */
	const _fcRaw = (o) => (o && typeof window !== 'undefined' && window.Alpine && typeof window.Alpine.raw === 'function') ? window.Alpine.raw(o) : o;
	const _fcMergedHitsMemo = new WeakMap();
	return {
		isXmlContext() {
			return !!(this.search && this.search.contextFormat === 'xml' && this.currentBackendSupportsXmlContext());
		},

		wantsStructuredContext() {
			return !!(this.search && this.search.contextFormat && this.search.contextFormat !== 'none');
		},

		hitHasStructuredContext(hit) {
			return !!this.getHitContext(hit);
		},

		/** Manatee query() sets hit.raw to internal TSV (doc, ids, positions, tokens) — never show that as primary UI. */
		hitLooksLikeManateeEngine(hit) {
			const eng = String((hit && hit.engine) || '').toLowerCase();
			if (eng.includes('manatee')) return true;
			const r = this.search && this.search.response && this.search.response.result;
			const re = r && typeof r.engine === 'string' ? r.engine.toLowerCase() : '';
			if (re.includes('manatee')) return true;
			const cf = r && typeof r.corpus_format === 'string' ? r.corpus_format.toLowerCase() : '';
			return cf === 'manatee';
		},

		/** Plain match words from Manatee hit (toks = surface strings from lexicon). */
		manateeHitPlainLine(hit) {
			if (!hit || typeof hit !== 'object') return '';
			if (Array.isArray(hit.toks) && hit.toks.length) {
				const s = hit.toks
					.filter((t) => t != null && String(t).trim() !== '')
					.map((t) => String(t))
					.join(' ')
					.trim();
				if (s) return s;
			}
			if (Array.isArray(hit.tokens) && hit.tokens.length) {
				const s = this.hitTokensToPlainLine(hit.tokens);
				if (s) return s;
			}
			return '';
		},

		/**
		 * Short label for the Context column link (filename lives in title / showdocinfo, not inline).
		 */
		getHitContextLinkLabel(_hit) {
			return 'Context';
		},

		alignedContextLabel(hit) {
			const ctx = String(this.getHitContextLinkLabel(hit) || 'Context').trim() || 'Context';
			const langRaw = this.getAlignedDistinguishingValue(hit);
			const lang = this.alignedLanguageDisplayName(langRaw);
			return lang ? `Aligned ${ctx} : ${lang}` : `Aligned ${ctx}`;
		},

		alignedContextLanguageLabel(hit) {
			const langRaw = this.getAlignedDistinguishingValue(hit);
			return this.alignedLanguageDisplayName(langRaw);
		},

		alignedLanguageDisplayName(raw) {
			const v = String(raw || '').trim().replace(/\s+context\s*$/i, '').trim();
			if (!v) return '';
			// Already descriptive (e.g. "Indonesian") — keep as-is.
			if (/[A-Z]/.test(v) || v.length > 3 || /[\s_-]/.test(v)) return v;
			const lower = v.toLowerCase();
			const iso3ToIso1 = {
				eng: 'en',
				deu: 'de',
				ger: 'de',
				nld: 'nl',
				dut: 'nl',
				fra: 'fr',
				fre: 'fr',
				spa: 'es',
				por: 'pt',
				ita: 'it',
				rus: 'ru',
				ind: 'id',
				ara: 'ar',
				jpn: 'ja',
				kor: 'ko',
				zho: 'zh',
				chi: 'zh',
				hin: 'hi',
				khm: 'km',
			};
			const code = iso3ToIso1[lower] || lower;
			try {
				if (typeof Intl !== 'undefined' && typeof Intl.DisplayNames === 'function') {
					const dn = new Intl.DisplayNames(['en'], { type: 'language' });
					const label = dn.of(code);
					if (typeof label === 'string' && label.trim()) return label;
				}
			} catch (_) {}
			const fallbackNames = {
				ind: 'Indonesian',
				id: 'Indonesian',
				nld: 'Dutch',
				nl: 'Dutch',
				deu: 'German',
				de: 'German',
				eng: 'English',
				en: 'English',
			};
			return fallbackNames[lower] || v;
		},

		getAlignedDistinguishingValue(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const docId = String(hit.doc_id || hit.text_id || this.getHitDocLabel(hit) || '').trim();
			const docMeta = this.getDocument(docId);
			if (docMeta && typeof docMeta === 'object') {
				const keys = ['text_lang', 'lang', 'language'];
				for (let i = 0; i < keys.length; i += 1) {
					const v = docMeta[keys[i]];
					if (typeof v === 'string' && v.trim()) return v.trim();
				}
			}
			const m = docId.match(/-([A-Za-z][A-Za-z0-9_-]*)\.xml$/i);
			if (m && m[1]) return m[1];
			return '';
		},

		getAlignedCompanionHit(hit) {
			if (!hit || typeof hit !== 'object') return null;
			const side = hit.aligned_counterpart;
			if (!(side && typeof side === 'object')) return null;
			return this.isSelfAlignedCompanion(hit, side) ? null : side;
		},

		getGroupedAlignedCompanions(hit) {
			if (!hit || typeof hit !== 'object') return [];
			if (Array.isArray(hit.__flexicorpAlignedCompanions) && hit.__flexicorpAlignedCompanions.length) {
				return this.dedupeGroupedAlignedCompanions(
					hit,
					hit.__flexicorpAlignedCompanions.filter((c) => !this.isSelfAlignedCompanion(hit, c))
				);
			}
			const single = this.getAlignedCompanionHit(hit);
			return this.dedupeGroupedAlignedCompanions(hit, single ? [single] : []);
		},

		dedupeGroupedAlignedCompanions(sourceHit, companions) {
			if (!Array.isArray(companions) || !companions.length) return [];
			const out = [];
			const seen = new Set();
			for (let i = 0; i < companions.length; i += 1) {
				const c = companions[i];
				if (!c || typeof c !== 'object') continue;
				const key = this.groupedAlignedCompanionDisplayKey(sourceHit, c);
				if (seen.has(key)) continue;
				seen.add(key);
				out.push(c);
			}
			return out;
		},

		groupedAlignedCompanionDisplayKey(_sourceHit, companionHit) {
			if (!companionHit || typeof companionHit !== 'object') return '';
			const docId = String(this.getHitDocLabel(companionHit) || companionHit.doc_id || companionHit.text_id || '').trim();
			const sid = String(this.getHitSentenceIdForNavigation(companionHit) || '').trim();
			const match = this.getSentenceAlignedMatchText(companionHit)
				|| ((companionHit.context && typeof companionHit.context === 'object' && typeof companionHit.context.match === 'string')
					? companionHit.context.match
					: '');
			const ms = Number.isFinite(Number(companionHit.match_start)) ? String(companionHit.match_start) : '';
			const me = Number.isFinite(Number(companionHit.match_end)) ? String(companionHit.match_end) : '';
			// Group by displayed target result first (doc + sentence/result text), with span as fallback.
			return `${docId}::${sid}::${String(match || '').trim()}::${ms}::${me}`;
		},

		isSelfAlignedCompanion(sourceHit, companionHit) {
			if (!sourceHit || !companionHit || typeof sourceHit !== 'object' || typeof companionHit !== 'object') return false;
			const srcDoc = String(this.getHitDocLabel(sourceHit) || sourceHit.doc_id || sourceHit.text_id || '').trim();
			const tgtDoc = String(this.getHitDocLabel(companionHit) || companionHit.doc_id || companionHit.text_id || '').trim();
			if (!srcDoc || !tgtDoc || srcDoc !== tgtDoc) return false;
			const srcStart = Number(sourceHit.match_start);
			const srcEnd = Number(sourceHit.match_end);
			const tgtStart = Number(companionHit.match_start);
			const tgtEnd = Number(companionHit.match_end);
			const sameSpan =
				Number.isFinite(srcStart) &&
				Number.isFinite(srcEnd) &&
				Number.isFinite(tgtStart) &&
				Number.isFinite(tgtEnd) &&
				srcStart === tgtStart &&
				srcEnd === tgtEnd;
			if (sameSpan) return true;
			const srcTok = String(this.getHitTokenIds(sourceHit) || '').trim();
			const tgtTok = String(this.getHitTokenIds(companionHit) || '').trim();
			if (srcTok && tgtTok && srcTok === tgtTok) return true;
			return false;
		},

		getStructuralAlignedSourceGroupKey(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const docId = this.getHitDocLabel(hit) || hit.doc_id || '';
			const sentenceId = String(this.getHitSentenceIdForNavigation(hit) || '').trim();
			const ms = Number.isFinite(Number(hit.match_start)) ? String(hit.match_start) : '';
			const me = Number.isFinite(Number(hit.match_end)) ? String(hit.match_end) : '';
			const tok = this.getHitTokenIds(hit) || '';
			const ctx = this.getSentenceAlignedMatchText(hit) || '';
			return `src-align::${docId}::${sentenceId}::${ms}::${me}::${tok}::${ctx}`;
		},

		getStructuralAlignedCompanionKey(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const docId = this.getHitDocLabel(hit) || hit.doc_id || '';
			const sentenceId = String(this.getHitSentenceIdForNavigation(hit) || '').trim();
			const ms = Number.isFinite(Number(hit.match_start)) ? String(hit.match_start) : '';
			const me = Number.isFinite(Number(hit.match_end)) ? String(hit.match_end) : '';
			const tok = this.getHitTokenIds(hit) || '';
			const ctx = this.getSentenceAlignedMatchText(hit) || '';
			return `tgt-align::${docId}::${sentenceId}::${ms}::${me}::${tok}::${ctx}`;
		},

		queryUsesSentenceAlignedTarget() {
			const queryText = this.getExecutedSearchQueryText();
			if (!queryText) return false;
			return this.queryUsesStructuralAlignedTarget();
		},

		queryUsesStructuralAlignedTarget() {
			const queryText = this.getExecutedSearchQueryText();
			if (!queryText) return false;
			const m = queryText.match(/\bwith\b[\s\S]*?\b([A-Za-z_][A-Za-z0-9_]*)\s*:\s*<([A-Za-z_][A-Za-z0-9_-]*)(?:\s|>)/i);
			if (!m) return false;
			const tag = String(m[2] || '').trim().toLowerCase();
			return !!tag;
		},

		hitLooksSentenceAlignedByIds(hit) {
			const ids = this.getHitMatchIds(hit);
			if (!Array.isArray(ids) || !ids.length) return false;
			return ids.some((id) => {
				const s = String(id || '').trim().toLowerCase();
				if (!s) return false;
				return /^s[-_.]/.test(s) || /(^|[._-])s\d+$/.test(s) || s.includes('.s');
			});
		},

		getHitOwnAlignedSide(hit, sideHint) {
			const hinted = String(sideHint || '').trim().toLowerCase();
			if (hinted === 'source' || hinted === 'target') return hinted;
			if (!hit || typeof hit !== 'object') return '';
			const own = String(hit.aligned_side || hit.side || '').trim().toLowerCase();
			if (own === 'source' || own === 'target') return own;
			const alignedToSide = hit.aligned_to && typeof hit.aligned_to === 'object'
				? String(hit.aligned_to.side || '').trim().toLowerCase()
				: '';
			if (alignedToSide === 'target') return 'source';
			if (alignedToSide === 'source') return 'target';
			return '';
		},

		hitIsSentenceAligned(hit, sideHint) {
			if (!hit || typeof hit !== 'object') return false;
			const hasAlignedMeta = !!this.getAlignedCompanionHit(hit)
				|| !!(hit.aligned_to && typeof hit.aligned_to === 'object')
				|| !!String(hit.aligned_side || hit.side || sideHint || '').trim();
			if (!hasAlignedMeta) return false;
			const ownSide = this.getHitOwnAlignedSide(hit, sideHint);
			if (ownSide !== 'target') return false;
			if (this.queryUsesSentenceAlignedTarget()) return true;
			if (this.hitLooksSentenceAlignedByIds(hit)) return true;
			const sentenceId = this.getHitSentenceIdForNavigation(hit);
			if (sentenceId && !this.getHitMatchIds(hit).length) return true;
			return false;
		},

		getSentenceAlignedMatchText(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const ctx = hit.context && typeof hit.context === 'object' ? hit.context : null;
			const pick = (obj, keys) => {
				if (!obj || typeof obj !== 'object') return '';
				for (const k of keys) {
					const v = obj[k];
					if (typeof v === 'string' && v.trim() !== '') return v;
				}
				return '';
			};
			const fromCtx = pick(ctx, ['match', 'mid', 'center', 'token']);
			if (fromCtx) return fromCtx;
			const fromHit = pick(hit, ['match', 'mid', 'center', 'token', 'word', 'form']);
			if (fromHit) return fromHit;
			if (Array.isArray(hit.tokens) && hit.tokens.length) {
				return this.hitTokensToPlainLine(hit.tokens);
			}
			return '';
		},

		getSentenceAlignedTokenIds(hit) {
			if (!hit || typeof hit !== 'object' || !Array.isArray(hit.tokens) || !hit.tokens.length) return [];
			const ids = [];
			const push = (v) => {
				const s = String(v == null ? '' : v).trim();
				if (!s || s === '_') return;
				if (!ids.includes(s)) ids.push(s);
			};
			for (let i = 0; i < hit.tokens.length; i += 1) {
				const tok = hit.tokens[i];
				if (!tok || typeof tok !== 'object') continue;
				push(tok.id);
				push(tok.tuid);
			}
			return ids;
		},

		clearAlignedHoverHighlights() {
			document.querySelectorAll('.flexicorp-hit-token--aligned-hover').forEach((el) => {
				el.classList.remove('flexicorp-hit-token--aligned-hover');
			});
		},

		highlightAlignedTokenByHover(event) {
			const ev = event || window.event;
			if (!ev || !ev.target) return;
			const tok = ev.target.closest('tok, dtok, mtok');
			if (!tok) return;
			const tuid = String(tok.getAttribute('tuid') || '').trim();
			if (!tuid) {
				this.clearAlignedHoverHighlights();
				return;
			}
			this.clearAlignedHoverHighlights();
			const esc = (typeof CSS !== 'undefined' && CSS.escape) ? CSS.escape(tuid) : tuid.replace(/["\\]/g, '\\$&');
			document.querySelectorAll(`.flexicorp-hit-xml tok[tuid="${esc}"], .flexicorp-hit-xml dtok[tuid="${esc}"], .flexicorp-hit-xml mtok[tuid="${esc}"]`).forEach((el) => {
				el.classList.add('flexicorp-hit-token--aligned-hover');
			});
		},

		getExecutedSearchQueryText() {
			const result = this.search && this.search.response ? this.search.response.result : null;
			if (this.search && typeof this.search.query === 'string' && this.search.query.trim()) {
				return this.search.query;
			}
			if (result && typeof result.query === 'string' && result.query.trim()) {
				return result.query;
			}
			if (result && result.query && typeof result.query === 'object' && typeof result.query.text === 'string') {
				return result.query.text;
			}
			return '';
		},

		getAlignedAliasOrderFromQuery(queryText) {
			const q = String(queryText || '');
			if (!q) return [];
			const out = [];
			const re = /\b([A-Za-z_][A-Za-z0-9_]*)\s*:\s*(?:\[|<)/g;
			let m;
			while ((m = re.exec(q)) !== null) {
				const alias = String(m[1] || '').trim();
				if (!alias) continue;
				if (!out.includes(alias)) out.push(alias);
			}
			return out;
		},

		getAliasClauseBody(queryText, alias) {
			const q = String(queryText || '');
			const a = String(alias || '').trim();
			if (!q || !a) return '';
			const esc = a.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
			const bracketRe = new RegExp(`\\b${esc}\\s*:\\s*\\[([\\s\\S]*?)\\]`, 'i');
			const bracketMatch = q.match(bracketRe);
			if (bracketMatch && typeof bracketMatch[1] === 'string') return bracketMatch[1];
			const regionRe = new RegExp(`\\b${esc}\\s*:\\s*(<[^>]+>)`, 'i');
			const regionMatch = q.match(regionRe);
			if (regionMatch && typeof regionMatch[1] === 'string') return regionMatch[1];
			return '';
		},

		getAliasLanguageFromQuery(queryText, alias) {
			const body = this.getAliasClauseBody(queryText, alias);
			if (!body) return '';
			const m = body.match(/\btext_lang\s*=\s*(?:"([^"]+)"|'([^']+)')/i);
			if (!m) return '';
			return String(m[1] || m[2] || '').trim();
		},

		alignedSideHeaderTitle(side) {
			const sideKey = String(side || '').toLowerCase();
			if (sideKey === 'source' || sideKey === 'src') return 'Source';
			if (!(sideKey === 'target' || sideKey === 'tgt')) return '';
			const queryText = this.getExecutedSearchQueryText();
			const aliases = this.getAlignedAliasOrderFromQuery(queryText);
			const targetAlias = aliases.length >= 2 ? aliases[1] : '';
			const lang = targetAlias ? this.getAliasLanguageFromQuery(queryText, targetAlias) : '';
			if (lang) return `Target (Language = ${lang})`;
			return 'Target';
		},

		searchHasAlignedHits() {
			const hits = this.search && Array.isArray(this.search.hits) ? this.search.hits : [];
			for (let i = 0; i < hits.length; i += 1) {
				if (this.getAlignedCompanionHit(hits[i])) return true;
			}
			return false;
		},

		formatAlignedRawFallback(hit) {
			if (!hit || typeof hit !== 'object') return '';
			if (typeof hit.raw === 'string' && hit.raw.trim()) return hit.raw;
			try {
				return JSON.stringify(hit, null, 2);
			} catch (_) {
				return String(hit);
			}
		},

		shouldShowRawHit(hit) {
			if (!hit || typeof hit !== 'object' || !hit.raw) return false;
			if (!this.wantsStructuredContext()) {
				if (this.hitLooksLikeManateeEngine(hit)) return false;
				if (Array.isArray(hit.toks) && hit.toks.length) return false;
				if (Array.isArray(hit.tokens) && hit.tokens.length) return false;
			}
			return !this.wantsStructuredContext();
		},

		searchContextUnavailable() {
			if (!this.wantsStructuredContext()) return false;
			if (!this.search || !Array.isArray(this.search.hits) || !this.search.hits.length) return false;
			return !this.search.hits.some(hit => this.hitHasStructuredContext(hit));
		},

		/**
		 * Show the one-line “Search result” summary (query + stats, or either alone).
		 */
		searchResultHeadlineVisible() {
			if (!this.search || !this.search.ran) return false;
			if (this.callHasIssues(this.search.response)) return false;
			if (this.searchResultPrimaryLine()) return true;
			if (this.search && typeof this.search.query === 'string' && this.search.query.trim() && !this.search.showQueryForm) {
				return true;
			}
			return false;
		},

		/** Single stats line under “Search result”: counts · ipm · time (no duplicate QL/engine; those stay in the toolbar). */
		searchResultPrimaryLine() {
			const result = this.search && this.search.response ? this.search.response.result : null;
			if (!result || typeof result !== 'object') return '';
			const response = this.search && this.search.response ? this.search.response : null;
			const selectedEngine =
				(result.engine && String(result.engine).trim())
				|| (this.settings && this.settings.queryEngine && String(this.settings.queryEngine).trim())
				|| (this.settings && this.settings.corpusFormat && String(this.settings.corpusFormat).trim())
				|| '';
			const totalNum = typeof result.total === 'number' ? result.total : Number.NaN;
			const totalLooksCapped = Number.isFinite(totalNum)
				? this.searchTotalLooksCapped(result, totalNum, selectedEngine)
				: false;
			const hitsCount = this.search && Array.isArray(this.search.hits) ? this.search.hits.length : 0;
			const rowsCount = this.getSearchTableRows().length;
			const isTable = this.searchHasTableResults();
			const returnedNum = result && (typeof result.returned === 'number' || (typeof result.returned === 'string' && result.returned !== ''))
				? Number(result.returned)
				: NaN;
			const returned = Number.isFinite(returnedNum) ? returnedNum : (isTable ? rowsCount : hitsCount);
			const totalFromResult = (result && (typeof result.total === 'number' || (typeof result.total === 'string' && result.total !== '')))
				? Number(result.total)
				: NaN;
			const totalFromResponse = (response && (typeof response.total === 'number' || (typeof response.total === 'string' && response.total !== '')))
				? Number(response.total)
				: NaN;
			const total = Number.isFinite(totalFromResult) ? totalFromResult : (Number.isFinite(totalFromResponse) ? totalFromResponse : null);
			const totalDisplay = total !== null
				? this.formatSearchTotalDisplay(result || response || {}, total, selectedEngine)
				: '';
			const countLabel = isTable
				? `${returned} of ${totalDisplay} ${returned === 1 ? 'row' : 'rows'}`
				: `${returned} of ${totalDisplay} ${returned === 1 ? 'hit' : 'hits'}`;
			const parts = [];
			if (total !== null && String(totalDisplay).length) {
				parts.push(countLabel);
			} else {
				parts.push(isTable ? `${returned} ${returned === 1 ? 'row' : 'rows'}` : `${returned} ${returned === 1 ? 'hit' : 'hits'}`);
			}
			let ipm = typeof result.ipm === 'number' && Number.isFinite(result.ipm) ? result.ipm : null;
			let corpusTokens =
				typeof result.corpus_tokens === 'number' && Number.isFinite(result.corpus_tokens)
					? result.corpus_tokens
					: null;
			if (corpusTokens === null && result.corpus_tokens != null && result.corpus_tokens !== '') {
				const n = Number(result.corpus_tokens);
				if (Number.isFinite(n) && n > 0) corpusTokens = n;
			}
			if (corpusTokens === null || corpusTokens <= 0) {
				const infoResult = this.info && this.info.result && typeof this.info.result === 'object' ? this.info.result : null;
				if (infoResult) {
					const n = Number(
						infoResult.tokens_count ?? infoResult.corpus_size ?? infoResult.size ?? infoResult.tokens ?? 0,
					);
					if (Number.isFinite(n) && n > 0) corpusTokens = n;
				}
			}
			if (!totalLooksCapped && ipm === null && typeof result.total === 'number' && result.total >= 0 && corpusTokens !== null && corpusTokens > 0) {
				ipm = (result.total / corpusTokens) * 1000000;
			}
			if (typeof ipm === 'number' && Number.isFinite(ipm) && this.searchIpmIsTrusted(result, totalLooksCapped)) {
				const ipmText = this.formatIpmDisplay(ipm, {
					count: total !== null && Number.isFinite(Number(total)) ? Number(total) : null,
				});
				if (ipmText) parts.push(`${ipmText} ipm`);
			}
			if (typeof result.time_ms === 'number') {
				parts.push(`${result.time_ms.toFixed(0)} ms`);
			}
			return parts.join(' · ');
		},

		/** @deprecated — prefer searchResultPrimaryLine(); kept for any template that still x-for’s meta. */
		searchMetaParts() {
			return [];
		},

		searchMeta() {
			return this.searchMetaParts().map(part => part && part.text ? String(part.text) : '');
		},

		searchTotalLooksCapped(result, total, engineHint) {
			if (!result || typeof result !== 'object') return false;
			if (!Number.isFinite(total) || total < 0) return false;
			if (result.total_capped === true || result.total_is_cap === true || result.max_total_reached === true || result.capped === true) {
				return true;
			}
			const maxTotalNum = Number(
				result.max_total ?? result.maxTotal ?? result.total_limit ?? result.limit_total ?? result.cap_total ?? result.cap ?? NaN,
			);
			if (Number.isFinite(maxTotalNum) && maxTotalNum > 0 && total >= maxTotalNum) {
				return true;
			}
			const engine = String(
				engineHint
				|| result.engine
				|| (this.settings && this.settings.queryEngine)
				|| (this.settings && this.settings.corpusFormat)
				|| '',
			).toLowerCase();
			// flexicorp-pando defaults to max_total=10000; treat that boundary as a lower bound display.
			if (engine.includes('pando') && total === 10000) return true;
			return false;
		},

		searchIpmIsTrusted(result, totalLooksCapped) {
			if (!totalLooksCapped) return true;
			if (!result || typeof result !== 'object') return false;
			if (result.ipm_exact === true || result.ipm_trusted === true || result.total_exact === true) return true;
			return false;
		},

		formatSearchTotalDisplay(result, total, engineHint) {
			if (!Number.isFinite(total)) return String(total);
			return this.searchTotalLooksCapped(result, total, engineHint) ? `${total}+` : String(total);
		},

		/** @deprecated — query + stats are combined under “Search result”; keep for any legacy template. */
		searchSummaryText() {
			return '';
		},

		/** @deprecated */
		searchSummarySuffix() {
			return '';
		},

		searchXmlPresenceText() {
			const hits = this.search && Array.isArray(this.search.hits) ? this.search.hits : [];
			if (!hits.length) return '';
			const parts = [];
			const maxHits = Math.min(120, hits.length);
			for (let i = 0; i < maxHits; i += 1) {
				const h = hits[i];
				const ctx = this.getHitContext(h);
				if (typeof ctx === 'string' && ctx.includes('<')) parts.push(ctx);
			}
			return parts.join('\n');
		},

		searchTextFormOptions() {
			const fd = (typeof window !== 'undefined' && window.formdef && typeof window.formdef === 'object') ? window.formdef : null;
			if (!fd || !this.isXmlContext()) return [];
			const xml = this.searchXmlPresenceText();
			if (!xml) return [];
			const canSeeAdmin = !!(typeof window !== 'undefined' && window.username);
			const opts = [];
			Object.keys(fd).forEach((key) => {
				const item = fd[key];
				if (!item || typeof item !== 'object') return;
				if (item.admin && !canSeeAdmin) return;
				const inheritKey = typeof item.inherit === 'string' ? item.inherit : '';
				const hasOwn = new RegExp(`\\s${String(key).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}=`).test(xml);
				const hasInherited = inheritKey
					? new RegExp(`\\s${String(inheritKey).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}=`).test(xml)
					: false;
				const relevant = key === 'pform' || !!item.transliterate || hasOwn || (!!item.subtract && hasInherited);
				if (!relevant) return;
				opts.push({
					key,
					label: item.display || key,
					color: item.color || '',
					admin: !!item.admin,
				});
			});
			return opts;
		},

		searchTagOptions() {
			const td = (typeof window !== 'undefined' && window.tagdef && typeof window.tagdef === 'object') ? window.tagdef : null;
			if (!td || !this.isXmlContext()) return [];
			const xml = this.searchXmlPresenceText();
			if (!xml) return [];
			const canSeeAdmin = !!(typeof window !== 'undefined' && window.username);
			const opts = [];
			Object.keys(td).forEach((key) => {
				const item = td[key];
				if (!item || typeof item !== 'object') return;
				if (item.admin && !canSeeAdmin) return;
				const hasKey = new RegExp(`\\s${String(key).replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}=`).test(xml);
				if (!hasKey) return;
				opts.push({
					key,
					label: item.display || key,
					color: item.color || '',
					admin: !!item.admin,
				});
			});
			return opts;
		},

		searchTextOptionsVisible() {
			return this.searchTextFormOptions().length > 1;
		},

		/** Tags UI disabled in template (TEITOK tagdef + heavy page CSS breaks KWIC); keep false until revisited. */
		searchTagOptionsVisible() {
			return false;
		},

		applySearchTextForm(key) {
			if (!key) return;
			if (typeof window !== 'undefined' && typeof window.setbut === 'function') {
				window.setbut(`but-${key}`);
			}
			if (typeof window !== 'undefined' && typeof window.setForm === 'function') {
				window.setForm(String(key));
			}
		},

		toggleSearchTag(key) {
			if (!key) return;
			if (typeof window !== 'undefined' && typeof window.toggletag === 'function') {
				window.toggletag(String(key));
			}
		},

		isSearchTextFormActive(key) {
			if (!key || typeof window === 'undefined') return false;
			const current = typeof window.showform === 'string' ? window.showform : '';
			return current === String(key);
		},

		isSearchTagActive(key) {
			if (!key || typeof window === 'undefined') return false;
			const labels = Array.isArray(window.labels) ? window.labels : [];
			return labels.includes(String(key));
		},

		searchHasTableResults() {
			const result = this.search && this.search.response ? this.search.response.result : null;
			const resultType = (result && typeof result.result_type === 'string' ? result.result_type : (this.search && this.search.resultType) || '').toLowerCase();
			// Frequency/statistical result tables belong to Stats, not Search.
			if (result && typeof result === 'object') {
				const hasCompareQueries = Array.isArray(result.compare_queries) && result.compare_queries.length > 0;
				const hasTotalsPerQuery = !!(result.totals_per_query && typeof result.totals_per_query === 'object');
				const hasRelativeStats = !!result.per_subcorpus_ipm;
				const rowLooksLikeFreq =
					Array.isArray(result.rows) &&
					result.rows.some((r) => r && typeof r === 'object' && r.queries && typeof r.queries === 'object');
				if (hasCompareQueries || hasTotalsPerQuery || hasRelativeStats || rowLooksLikeFreq) return false;
				// dcoll/coll payloads use collocates[], not concordance hits.
				if (Array.isArray(result.collocates) && result.collocates.length > 0) return true;
				const op = String(result.operation || '').trim().toLowerCase();
				if ((op === 'dcoll' || op === 'coll') && (Array.isArray(result.collocates) || Number(result.matches) > 0)) return true;
			}
			if (resultType === 'table') return true;
			if (result && typeof result === 'object') {
				const table = result.table && typeof result.table === 'object' ? result.table : null;
				if (table && (Array.isArray(table.rows) || Array.isArray(table.columns))) return true;
				if (Array.isArray(result.rows) || Array.isArray(result.columns) || Array.isArray(result.items)) return true;
			}
			return false;
		},

		getSearchTableColumns() {
			const result = this.search && this.search.response ? this.search.response.result : null;
			const table = result && result.table && typeof result.table === 'object' ? result.table : null;
			const direct = table && Array.isArray(table.columns)
				? table.columns
				: (result && Array.isArray(result.columns) ? result.columns : (this.search && Array.isArray(this.search.tableColumns) ? this.search.tableColumns : []));
			if (direct.length) return direct;
			const rows = this.getSearchTableRows();
			return rows.length && rows[0] && typeof rows[0] === 'object' ? Object.keys(rows[0]) : [];
		},

		getSearchTableRows() {
			const result = this.search && this.search.response ? this.search.response.result : null;
			const table = result && result.table && typeof result.table === 'object' ? result.table : null;
			if (table && Array.isArray(table.rows)) return table.rows;
			if (result && Array.isArray(result.collocates)) return result.collocates;
			if (result && Array.isArray(result.rows)) return result.rows;
			if (result && Array.isArray(result.items)) return result.items;
			return this.search && Array.isArray(this.search.tableRows) ? this.search.tableRows : [];
		},

		/**
		 * True when the last search completed without call errors and returned at least one
		 * hit/row (query-scoped frequency, collocations, dcoll, etc. need a non-empty match set).
		 */
		statsSearchHasHits() {
			if (!this.search || !this.search.ran) return false;
			// errors only: a warning (e.g. a capped total) still leaves the hits to work with
			if (this.callHasErrors(this.search.response)) return false;
			const result = this.search.response && this.search.response.result && typeof this.search.response.result === 'object'
				? this.search.response.result
				: null;
			// dcoll/coll: match set size is `matches`; table rows are `collocates`.
			if (result && typeof result === 'object') {
				const matches = Number(result.matches);
				if (Number.isFinite(matches) && matches > 0) return true;
				if (Array.isArray(result.collocates) && result.collocates.length > 0) return true;
			}
			if (this.searchHasTableResults()) {
				if (this.getSearchTableRows().length > 0) return true;
				if (result && Array.isArray(result.collocates) && result.collocates.length > 0) return true;
			}
			const response = this.search.response;
			const totalFromResult =
				result && (typeof result.total === 'number' || (typeof result.total === 'string' && result.total !== ''))
					? Number(result.total)
					: NaN;
			const totalFromResponse =
				response && (typeof response.total === 'number' || (typeof response.total === 'string' && response.total !== ''))
					? Number(response.total)
					: NaN;
			const total = Number.isFinite(totalFromResult)
				? totalFromResult
				: Number.isFinite(totalFromResponse)
					? totalFromResponse
					: null;
			if (total !== null && total === 0) return false;
			if (total !== null && total > 0) return true;
			const hits = Array.isArray(this.search.hits) ? this.search.hits : [];
			if (hits.length > 0) return true;
			const returnedNum =
				result && (typeof result.returned === 'number' || (typeof result.returned === 'string' && result.returned !== ''))
					? Number(result.returned)
					: NaN;
			if (Number.isFinite(returnedNum)) return returnedNum > 0;
			return false;
		},

		formatSearchTableCell(value) {
			if (value == null) return '';
			if (typeof value === 'string') return value;
			if (typeof value === 'number' || typeof value === 'boolean') return String(value);
			try {
				return JSON.stringify(value);
			} catch (_) {
				return String(value);
			}
		},

		isSearchViewMode(mode) {
			return !!(this.search && this.search.viewMode === mode);
		},

		/** Next node in document order (depth-first). */
		_nextNodeInDocumentOrder(node) {
			if (!node) return null;
			if (node.firstChild) return node.firstChild;
			let n = node;
			while (n) {
				if (n.nextSibling) return n.nextSibling;
				n = n.parentNode;
			}
			return null;
		},

		/**
		 * Normalize spacing in XML fragment for display so it matches TEITOK spacing rules:
		 * - When join="right" or join="left": remove space between those token elements.
		 * - When xml:space="remove" is on an ancestor: remove all whitespace-only nodes between token elements (nospace=1).
		 * Only affects XML fragment display; raw/engine output is unchanged.
		 */
		normalizeXmlFragmentSpacing(container) {
			if (!container || !container.querySelectorAll) return;
			const tokens = this.xmlContextTokenElements(container);
			const hasXmlSpaceRemove = container.querySelector && container.querySelector('[xml\\:space="remove"], [space="remove"]');
			for (let i = 0; i < tokens.length - 1; i++) {
				const prev = tokens[i];
				const next = tokens[i + 1];
				const prevJoin = (prev.getAttribute && prev.getAttribute('join')) || '';
				const nextJoin = (next.getAttribute && next.getAttribute('join')) || '';
				const removeSpace = hasXmlSpaceRemove || prevJoin === 'right' || nextJoin === 'left';
				if (!removeSpace) continue;
				let node = this._nextNodeInDocumentOrder(prev);
				while (node && node !== next) {
					const after = this._nextNodeInDocumentOrder(node);
					if (node.nodeType === Node.TEXT_NODE && /^\s*$/.test(node.textContent))
						node.remove();
					node = after;
				}
			}
		},

		getHitDomTokenPrefix(hit) {
			if (!hit || typeof hit !== 'object') return 'r0_';
			if (typeof hit.__flexicorpDomTokenPrefix === 'string' && hit.__flexicorpDomTokenPrefix)
				return hit.__flexicorpDomTokenPrefix;
			this.__flexicorpDomTokenPrefixSeq = Number(this.__flexicorpDomTokenPrefixSeq || 0) + 1;
			const prefix = `r${this.__flexicorpDomTokenPrefixSeq}_`;
			hit.__flexicorpDomTokenPrefix = prefix;
			return prefix;
		},

		rewriteTokenRefList(value, idMap) {
			if (typeof value !== 'string' || !value.trim() || !(idMap instanceof Map) || idMap.size === 0) return value;
			const tokens = value.split(/\s+/);
			let changed = false;
			const rewritten = tokens.map((part) => {
				if (!part) return part;
				const hashed = part.startsWith('#');
				const key = hashed ? part.slice(1) : part;
				const mapped = idMap.get(key);
				if (!mapped) return part;
				changed = true;
				return hashed ? `#${mapped}` : mapped;
			});
			return changed ? rewritten.join(' ') : value;
		},

		ensureUniqueTokenIdsInContainer(container, hit) {
			if (!container || !container.querySelectorAll) return;
			const tokenNodes = this.xmlContextTokenElements(container);
			if (!tokenNodes.length) return;
			const prefix = this.getHitDomTokenPrefix(hit);
			const idMap = new Map();
			tokenNodes.forEach((node, idx) => {
				const originalId = this.xmlElementId(node);
				const baseId = originalId || `anon${idx + 1}`;
				const uniqueId = `${prefix}${baseId}`;
				// Token-level hooks keep tokview tooltip positioning anchored to the actual token
				// instead of relying on document-level mouseover heuristics in table/KWIC snippets.
				node.setAttribute(
					'onmouseover',
					"var __ev = (typeof event !== 'undefined') ? event : (typeof window !== 'undefined' ? window.event : null); if (__ev && __ev.stopPropagation) __ev.stopPropagation(); if (typeof showtokinfo === 'function') showtokinfo(__ev, this, this); return false;"
				);
				node.setAttribute(
					'onmouseout',
					"var __ev = (typeof event !== 'undefined') ? event : (typeof window !== 'undefined' ? window.event : null); if (__ev && __ev.stopPropagation) __ev.stopPropagation(); if (typeof hidetokinfo === 'function') hidetokinfo(); return false;"
				);
				if (originalId) {
					idMap.set(originalId, uniqueId);
					node.setAttribute('data-flexicorp-orig-id', originalId);
				}
				node.setAttribute('id', uniqueId);
				// Afford click-to-tokedit when logged in (handler in initTeitokTokEditClicks).
				if (this.teitokUsername()) {
					node.setAttribute('title', 'Edit token');
					node.classList.add('flexicorp-tok-editable');
				}
			});
			if (!idMap.size) return;
			const refAttrs = ['head', 'ohead', 'sameAs', 'corresp', 'ana', 'target'];
			Array.from(container.querySelectorAll('*')).forEach((node) => {
				refAttrs.forEach((attr) => {
					if (!node.hasAttribute(attr)) return;
					const current = node.getAttribute(attr);
					const rewritten = this.rewriteTokenRefList(current, idMap);
					if (rewritten !== current) node.setAttribute(attr, rewritten);
				});
			});
		},

		/**
		 * XML string actually shown for token-window KWIC (left + match + right): Range serialization
		 * pulls in enclosing &lt;s tuid&gt; that the raw server fragment may omit.
		 */
		_assembledKwicXmlContextForHit(hit) {
			if (!this.isXmlContext() || !this.wantsStructuredContext() || !this.kwicScopeUsesTokenWindow()) {
				this._logAlignmentTuidDebug(hit, 'assembledKwic:skip', {
					reason: 'gates',
					isXmlContext: this.isXmlContext(),
					wantsStructuredContext: this.wantsStructuredContext(),
					kwicScopeUsesTokenWindow: this.kwicScopeUsesTokenWindow(),
				});
				return '';
			}
			if (!hit || typeof hit !== 'object') {
				this._logAlignmentTuidDebug(hit, 'assembledKwic:skip', { reason: '!hit' });
				return '';
			}
			const mids = this.getHitMatchIds(hit);
			if (!Array.isArray(mids) || !mids.length) {
				this._logAlignmentTuidDebug(hit, 'assembledKwic:skip', { reason: '!matchIds', mids });
				return '';
			}
			const parts = this.getXmlKwicPartsForIds(hit, mids, { wrap: false });
			if (!parts || !parts.isXml) {
				this._logAlignmentTuidDebug(hit, 'assembledKwic:skip', {
					reason: '!partsOrNotXml',
					hasParts: !!parts,
					isXml: parts && parts.isXml,
					partsKeys: parts && typeof parts === 'object' ? Object.keys(parts) : [],
				});
				return '';
			}
			const stripKwicShell = (h) => {
				if (typeof h !== 'string' || !h.trim()) return '';
				return h
					.replace(/<\s*div[^>]*class\s*=\s*["'][^"']*flexicorp-hit-xml[^"']*["'][^>]*>/gi, '')
					.replace(/<\s*\/\s*div\s*>/gi, '')
					.trim();
			};
			const left = stripKwicShell(parts.left || '');
			const match = stripKwicShell(parts.match || '');
			const right = stripKwicShell(parts.right || '');
			const joined = [left, match, right].filter((s) => s).join('');
			this._logAlignmentTuidDebug(hit, 'assembledKwic:built', {
				leftLen: left.length,
				matchLen: match.length,
				rightLen: right.length,
				joinedLen: joined.length,
				joinedHead: this._truncXmlDbg(joined, 200),
			});
			return joined;
		},

		buildContextContainer(hit, contextOverride) {
			let context =
				contextOverride !== undefined && contextOverride !== null ? String(contextOverride) : this.getHitContext(hit);
			// When context is ridx-style (path\tnum\tnum\tid), show as plain text; do not parse as XML
			if (this.isXmlContext() && context && this.isRidxStyleContext(context)) {
				const container = document.createElement('div');
				container.innerHTML = '<pre class="flexicorp-hit-raw flexicorp-hit-ridx-fallback">' + this.escapeHtmlForPre(context) + '</pre>';
				return container;
			}
			// When displaying as XML, decode HTML entities so innerHTML parses real tags (fixes empty/no output)
			if (this.isXmlContext() && context && /&(?:lt|gt|amp|quot|#\d+;|#x[\da-f]+;)/i.test(context)) {
				context = this.decodeHtmlEntities(context);
			}
			if (this.isXmlContext() && context) {
				context = this.normalizeXmlContextFragmentBoundary(context);
			}
			const container = document.createElement('div');
			if (this.isXmlContext() && context) {
				try {
					const parser = new DOMParser();
					const xmlDoc = parser.parseFromString(`<flexicorp-root>${context}</flexicorp-root>`, 'application/xml');
					if (!xmlDoc.querySelector('parsererror') && xmlDoc.documentElement) {
						Array.from(xmlDoc.documentElement.childNodes).forEach((node) => {
							container.appendChild(document.importNode(node, true));
						});
						this.ensureUniqueTokenIdsInContainer(container, hit);
						this.normalizeXmlFragmentSpacing(container);
						return container;
					}
				} catch (_) {
					// Fall back to HTML parsing below.
				}
			}
			container.innerHTML = context || '';
			if (this.isXmlContext() && context) this.ensureUniqueTokenIdsInContainer(container, hit);
			return container;
		},

		/**
		 * Token-like elements in a parsed XML fragment. Namespaced TEI (e.g. {http://www.tei-c.org/ns/1.0}w)
		 * is invisible to querySelector("w"), so walk * and match localName.
		 */
		xmlContextTokenElements(container) {
			if (!container || !container.querySelectorAll) return [];
			const tags = new Set(['tok', 'dtok', 'mtok', 'w']);
			return Array.from(container.querySelectorAll('*')).filter((n) => {
				if (!n || n.nodeType !== Node.ELEMENT_NODE) return false;
				return tags.has(String(n.localName || '').toLowerCase());
			});
		},

		/**
		 * Guard against stale-offset XML fragments where payload starts mid-tag/header text.
		 * If we detect plain leaked preamble before the first plausible TEI/token element,
		 * trim the fragment to that first element so rendering stays token-centric.
		 */
		normalizeXmlContextFragmentBoundary(fragment) {
			if (typeof fragment !== 'string') return '';
			const src = fragment;
			if (!src.trim()) return src;
			const firstCandidate = src.search(/<(?:s|seg|tok|dtok|mtok|w|p|div|u|l|lb)\b/i);
			if (firstCandidate <= 0) return src;
			const prefix = src.slice(0, firstCandidate);
			// Keep intact when prefix already looks like valid XML/comment/prolog.
			if (/<[!?/]/.test(prefix)) return src;
			// Prefix has no tag opener and likely leaked textual debris -> trim.
			return src.slice(firstCandidate);
		},

		/** Heuristic: fragment offset drift leaked metadata/header text into context payload. */
		isClearlyCorruptedXmlContext(context) {
			if (typeof context !== 'string') return false;
			const s = context.trim();
			if (!s) return false;
			// Typical leakage seen with stale offsets in edited XML.
			if (/Tagged via .*tasks=.*[">]/i.test(s)) return true;
			if (/atize,parse,tag\">/i.test(s)) return true;
			// Plain metadata text without any token/sentence tags is unusable as XML.
			const hasXmlTag = /<(?:s|seg|tok|dtok|mtok|w|p|div|u|l|lb)\b/i.test(s);
			if (!hasXmlTag && /Tagged via|tasks=|lemmatize|parse|tag/i.test(s)) return true;
			return false;
		},

		getCorruptedXmlFallbackText(hit) {
			const plain = this.getPlainContextPartsFromHit(hit);
			if (plain) {
				return [plain.left, plain.match, plain.right]
					.map((x) => (typeof x === 'string' ? x.trim() : ''))
					.filter((x) => x)
					.join(' ');
			}
			const matchIds = this.getHitMatchIds(hit);
			const fromTokens = this.getKwicPartsFromJsonTokens(hit, matchIds);
			if (fromTokens) {
				return [fromTokens.left, fromTokens.match, fromTokens.right]
					.map((x) => (typeof x === 'string' ? x.trim() : ''))
					.filter((x) => x)
					.join(' ');
			}
			return this.getHitContextSyntheticFromTokens(hit) || '';
		},

		getRenderedXmlContext(hit, sideHint) {
			if (!this.isXmlContext()) return this.getHitContext(hit);
			if (this.hitIsSentenceAligned(hit, sideHint)) {
				const sentenceIds = this.getSentenceAlignedTokenIds(hit);
				if (this.hitHasTeitokXmlContext(hit) && sentenceIds.length) {
					const xmlParts = this.getXmlKwicPartsForIds(hit, sentenceIds, { wrap: false });
					if (xmlParts && xmlParts.isXml && typeof xmlParts.match === 'string' && xmlParts.match.trim()) {
						return `<span class="flexicorp-hit-xml">${xmlParts.match}</span>`;
					}
				}
				const sentenceText = this.getSentenceAlignedMatchText(hit);
				return `<span class="flexicorp-hit-sentence-plain">${this.escapeHtmlForPre(sentenceText)}</span>`;
			}
			const context = this.getHitContext(hit);
			if (!context) {
				if (hit && typeof hit === 'object') delete hit.__flexicorpRenderedXml;
				return '';
			}
			if (this.isClearlyCorruptedXmlContext(context)) {
				const fallbackText = this.getCorruptedXmlFallbackText(hit);
				const fallbackOut = '<pre class="flexicorp-hit-raw flexicorp-hit-xml-fallback">' + this.escapeHtmlForPre(fallbackText || '[stale XML context; reindex recommended]') + '</pre>';
				if (hit && typeof hit === 'object') {
					hit.__flexicorpRenderedXml = fallbackOut;
					hit.__flexicorpRenderedXmlKey = 'xml-corrupt-fallback';
				}
				return fallbackOut;
			}
			const highlightSig = (() => {
				try {
					const side = this.getAlignedHitSide(hit, sideHint) || '-';
					const groups = this.getHighlightMapGroups(hit, sideHint)
						.map((g) => `${String(g && (g.name || g.id) || '')}:${String(g && g.paletteClass || '')}:${Array.isArray(g && g.ids) ? g.ids.length : 0}`)
						.join(',');
					return `${side}|${groups}`;
				} catch (_) {
					return '-';
				}
			})();
			const renderKey = `${this.currentContextScopeKey()}|${this.activeKwicWindowSize()}|xml|${this.normalizeSearchViewMode(this.search && this.search.viewMode)}|${this.kwicHitUsesHighlighting(hit) ? 1 : 0}|${highlightSig}`;
			// Only use cache when it has content (avoid reusing empty from an earlier failed render)
			if (
				hit
				&& typeof hit === 'object'
				&& hit.__flexicorpRenderedXml
				&& hit.__flexicorpRenderedXml.length > 0
				&& hit.__flexicorpRenderedXmlKey === renderKey
			) return hit.__flexicorpRenderedXml;
			if (this.kwicScopeUsesTokenWindow()) {
				const xmlParts = this.getXmlKwicPartsForIds(hit, this.getHitMatchIds(hit), { wrap: false });
				if (xmlParts && xmlParts.isXml) {
					const out = `<span class="flexicorp-hit-xml flexicorp-kwic-xml">${xmlParts.left || ''}${xmlParts.match || ''}${xmlParts.right || ''}</span>`;
					if (hit && typeof hit === 'object' && out) {
						hit.__flexicorpRenderedXml = out;
						hit.__flexicorpRenderedXmlKey = renderKey;
					}
					return out;
				}
			}
			if (context && this.isRidxStyleContext(context)) {
				const out = '<pre class="flexicorp-hit-raw flexicorp-hit-ridx-fallback">' + this.escapeHtmlForPre(context) + '</pre>';
				if (hit && typeof hit === 'object') {
					hit.__flexicorpRenderedXml = out;
					hit.__flexicorpRenderedXmlKey = renderKey;
				}
				return out;
			}
			const container = this.buildContextContainer(hit);
			// If parsing produced no tok/seg (e.g. HTML parser ate the XML), show raw in <pre> instead
			const hasStructure =
				this.xmlContextTokenElements(container).length > 0 ||
				Array.from(container.querySelectorAll('*')).some((n) => {
					const ln = String(n.localName || '').toLowerCase();
					return ln === 'seg' || ln === 's' || ln === 'u' || ln === 'p' || ln === 'div' || ln === 'l' || ln === 'lb';
				});
			if (!hasStructure && context) {
				const fallback = '<pre class="flexicorp-hit-raw flexicorp-hit-xml-fallback">' + this.escapeHtmlForPre(context) + '</pre>';
				if (hit && typeof hit === 'object') {
					hit.__flexicorpRenderedXml = fallback;
					hit.__flexicorpRenderedXmlKey = renderKey;
				}
				return fallback;
			}
			this.applyHighlightTokenClassesToContainer(container, hit, sideHint);
			// Drop layout/facsimile attrs on token elements only (not <l>/<lb> line geometry). Stripping
			// from <tok> avoids huge inline facs previews beside running text; keeping <l bbox> matches
			// source XML and lets facsimile / line-crop logic see the verse-line box.
			const display = container.cloneNode(true);
			display.querySelectorAll('tok, dtok, mtok').forEach((el) => {
				el.removeAttribute('bbox');
				el.removeAttribute('facs');
				el.removeAttribute('baseline');
			});
			// Keep TEI structure intact, but neutralize facsimile/image triggers.
			display.querySelectorAll('pb, cb, lb').forEach((el) => {
				el.removeAttribute('facs');
				el.removeAttribute('img');
			});
			display.querySelectorAll('img, .imgdiv, .hlbar, .adminpart').forEach((el) => {
				if (el && el.remove) el.remove();
			});
			const rendered = display.innerHTML;
			if (hit && typeof hit === 'object' && rendered) {
				hit.__flexicorpRenderedXml = rendered;
				hit.__flexicorpRenderedXmlKey = renderKey;
			}
			return rendered;
		},

		/**
		 * TEI often uses xml:id on tokens; CWB tabulate may use the same logical id with/without a w/t prefix.
		 */
		xmlElementId(el) {
			if (!el || !el.getAttribute) return '';
			let v = el.getAttribute('data-flexicorp-orig-id');
			if (v && v.trim()) return v.trim();
			v = el.getAttribute('id');
			if (v && v.trim()) return v.trim();
			if (el.getAttributeNS) {
				v = el.getAttributeNS('http://www.w3.org/XML/1998/namespace', 'id');
				if (v && v.trim()) return v.trim();
			}
			return '';
		},

		xmlElementIds(el) {
			if (!el || !el.getAttribute) return [];
			const ids = [];
			const push = (v) => {
				const s = typeof v === 'string' ? v.trim() : '';
				if (s && !ids.includes(s)) ids.push(s);
			};
			push(el.getAttribute('data-flexicorp-orig-id'));
			push(el.getAttribute('id'));
			if (el.getAttributeNS) {
				push(el.getAttributeNS('http://www.w3.org/XML/1998/namespace', 'id'));
			}
			// Aligned flexicorp-pando highlights may use token tuid while XML carries both id+tuid.
			push(el.getAttribute('tuid'));
			return ids;
		},

		xmlElementStableIds(el) {
			if (!el || !el.getAttribute) return [];
			const ids = [];
			const push = (v) => {
				const s = typeof v === 'string' ? v.trim() : '';
				if (s && !ids.includes(s)) ids.push(s);
			};
			push(el.getAttribute('data-flexicorp-orig-id'));
			push(el.getAttribute('id'));
			if (el.getAttributeNS) {
				push(el.getAttributeNS('http://www.w3.org/XML/1998/namespace', 'id'));
			}
			return ids;
		},

		xmlElementTuid(el) {
			if (!el || !el.getAttribute) return '';
			const s = String(el.getAttribute('tuid') || '').trim();
			return s || '';
		},

		serializeXmlRange(range) {
			const div = document.createElement('div');
			div.appendChild(range.cloneContents());
			return div.innerHTML;
		},

		/**
		 * Keep KWIC snippets token-centric while preserving TEI tags. We only neutralize
		 * facsimile/image triggers and inline styles that can distort KWIC rendering.
		 */
		sanitizeKwicXmlHtml(html) {
			if (typeof html !== 'string' || !html.trim()) return '';
			const host = document.createElement('div');
			host.innerHTML = html;
			host.querySelectorAll('pb, cb, lb').forEach((el) => {
				el.removeAttribute('facs');
				el.removeAttribute('img');
			});
			host.querySelectorAll('img, .imgdiv, .hlbar, .adminpart').forEach((el) => {
				if (el && el.remove) el.remove();
			});
			host.querySelectorAll('[style]').forEach((el) => {
				el.removeAttribute('style');
			});
			return host.innerHTML;
		},

		/**
		 * Split TEITOK XML context into KWIC columns using DOM Range boundaries (document order),
		 * after applying query-group highlight classes. More reliable than string heuristics.
		 */
		getXmlKwicPartsForIds(hit, matchIds, options = {}) {
			if (!hit || !this.isXmlContext() || !this.wantsStructuredContext()) return null;
			if (!Array.isArray(matchIds) || !matchIds.length) return null;
			const wrapSegments = !(options && options.wrap === false);
			const matchSet = new Set(matchIds.filter(Boolean));
			if (!matchSet.size) return null;
			const container = this.buildContextContainer(hit);
			if (!container || !container.querySelector) return null;
			if (container.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback')) return null;
			const sel = 'tok, dtok, mtok';
			const els = [...container.querySelectorAll(sel)];
			const expandedSet = this._expandMatchIdSet(matchSet);
			const matchEls = els.filter((el) => {
				const stableIds = this.xmlElementStableIds(el);
				for (let i = 0; i < stableIds.length; i += 1) {
					if (expandedSet.has(stableIds[i])) return true;
				}
				const tuid = this.xmlElementTuid(el);
				return !!(tuid && expandedSet.has(tuid));
			});
			if (!matchEls.length) return null;
			const first = matchEls[0];
			const last = matchEls[matchEls.length - 1];
			// Window boundaries should follow orthographic tokens. Nested dtok pieces must map
			// to their parent tok/mtok so we don't get context slices made from decomposition parts.
			const windowEls = [...container.querySelectorAll('tok, mtok, dtok')].filter((el) => {
				if (!el || !el.tagName) return false;
				const tag = String(el.tagName).toLowerCase();
				if (tag !== 'dtok') return true;
				return !el.closest('tok, mtok');
			});
			const toWindowAnchor = (el) => {
				if (!el || !el.tagName) return el;
				const tag = String(el.tagName).toLowerCase();
				if (tag === 'dtok') {
					const parentTok = el.closest('tok, mtok');
					if (parentTok) return parentTok;
				}
				return el;
			};
			const firstAnchor = toWindowAnchor(first);
			const lastAnchor = toWindowAnchor(last);
			const firstIdx = windowEls.indexOf(firstAnchor);
			const lastIdx = windowEls.indexOf(lastAnchor);
			let leftStartNode = null;
			let rightEndNode = null;
			if (this.kwicScopeUsesTokenWindow() && firstIdx >= 0 && lastIdx >= 0) {
				const win = this.activeKwicWindowSize();
				leftStartNode = windowEls[Math.max(0, firstIdx - win)] || firstAnchor;
				rightEndNode = windowEls[Math.min(windowEls.length - 1, lastIdx + win)] || lastAnchor;
			}
			if (this.kwicHitUsesHighlighting(hit)) {
				this.applyHighlightTokenClassesToContainer(container, hit);
			}

			const rLeft = document.createRange();
			if (leftStartNode) rLeft.setStartBefore(leftStartNode);
			else rLeft.selectNodeContents(container);
			rLeft.setEndBefore(first);
			const left = this.sanitizeKwicXmlHtml(this.serializeXmlRange(rLeft));

			const rMid = document.createRange();
			rMid.setStartBefore(first);
			rMid.setEndAfter(last);
			const match = this.sanitizeKwicXmlHtml(this.serializeXmlRange(rMid));

			const rRight = document.createRange();
			rRight.setStartAfter(last);
			if (rightEndNode) rRight.setEndAfter(rightEndNode);
			else rRight.setEnd(container, container.childNodes.length);
			const right = this.sanitizeKwicXmlHtml(this.serializeXmlRange(rRight));

			const wrap = (h) => {
				if (!h) return '';
				return wrapSegments ? `<div class="flexicorp-hit-xml flexicorp-kwic-xml">${h}</div>` : h;
			};
			return { left: wrap(left), match: wrap(match), right: wrap(right), isXml: true };
		},

		getContextTokens(hit) {
			if (hit && Array.isArray(hit.__flexicorpContextTokens)) return hit.__flexicorpContextTokens;
			const container = this.buildContextContainer(hit);
			const tokens = Array.from(container.querySelectorAll('tok, mtok, dtok'))
				.filter((node) => {
					if (!node || !node.tagName) return false;
					const tag = String(node.tagName).toLowerCase();
					if (tag !== 'dtok') return true;
					// Keep standalone dtok only; nested dtok are decomposition details of a tok.
					return !node.closest('tok, mtok');
				})
				.map(node => ({
					id: this.xmlElementId(node),
					text: (node.textContent || node.getAttribute('form') || '').trim(),
				}))
				.filter(token => token.text !== '');
			if (hit && typeof hit === 'object') hit.__flexicorpContextTokens = tokens;
			return tokens;
		},

		getTokenPositionsForIds(tokens, ids) {
			if (!Array.isArray(tokens) || !tokens.length || !Array.isArray(ids) || !ids.length) return [];
			const idSet = this._expandMatchIdSet(new Set(ids.filter(Boolean)));
			const positions = [];
			tokens.forEach((token, idx) => {
				if (!token || !token.id) return;
				const tokAliases = this._expandMatchIdSet(new Set([token.id]));
				for (const a of tokAliases) {
					if (idSet.has(a)) {
						positions.push(idx);
						break;
					}
				}
			});
			return positions;
		},

		getHitShapeInfo(hit) {
			if (hit && hit.__flexicorpHitShapeInfo) return hit.__flexicorpHitShapeInfo;
			const tokens = this.getContextTokens(hit);
			const matchIds = this.getHitMatchIds(hit);
			let positions = this.getTokenPositionsForIds(tokens, matchIds);
			let start = null;
			let end = null;
			if (positions.length) {
				start = Math.min(...positions);
				end = Math.max(...positions);
			} else {
				const rawStart = Number.isFinite(hit && hit.match_start) ? Number(hit.match_start) : null;
				const rawEnd = Number.isFinite(hit && hit.match_end) ? Number(hit.match_end) : null;
				if (rawStart !== null && rawEnd !== null && rawEnd >= rawStart) {
					start = rawStart;
					end = rawEnd;
				}
			}
			const matchedCount = matchIds.length || (Array.isArray(hit && hit.toks) ? hit.toks.filter(Boolean).length : 0);
			const spanLength = (start !== null && end !== null && end >= start) ? (end - start + 1) : matchedCount;
			const gapCount = Math.max(0, spanLength - matchedCount);
			const contiguous = matchedCount > 0 && gapCount === 0;
			const discontinuous = matchedCount > 1 && gapCount > 0;
			const wideDiscontinuous = discontinuous && gapCount >= Math.max(3, matchedCount);
			const info = {
				hasStructuredTokens: !!tokens.length,
				matchIds,
				positions,
				matchedCount,
				spanLength,
				gapCount,
				start,
				end,
				contiguous,
				discontinuous,
				wideDiscontinuous,
			};
			if (hit && typeof hit === 'object') hit.__flexicorpHitShapeInfo = info;
			return info;
		},

		isHitDiscontinuous(hit) {
			return !!this.getHitShapeInfo(hit).discontinuous;
		},

		searchHasDiscontinuousHits() {
			const hits = this.search && Array.isArray(this.search.hits) ? this.search.hits : [];
			return hits.some((hit) => this.isHitDiscontinuous(hit));
		},

		/** In KWIC, group highlights are only useful when hits are discontinuous. */
		kwicUsesHighlighting() {
			if (!this.isSearchViewMode('kwic') && !this.isSearchViewMode('anchor_kwic')) return true;
			return this.searchHasDiscontinuousHits();
		},

		/** Per-hit variant used while building XML KWIC fragments. */
		kwicHitUsesHighlighting(hit) {
			if (!this.isSearchViewMode('kwic') && !this.isSearchViewMode('anchor_kwic')) return true;
			return this.isHitDiscontinuous(hit);
		},

		searchShouldShowHighlightLegend() {
			if (!this.search || !Array.isArray(this.search.hits) || !this.search.hits.length) return false;
			if (!this.searchHighlightLegend().length) return false;
			if (this.isSearchViewMode('kwic') || this.isSearchViewMode('anchor_kwic')) {
				return this.kwicUsesHighlighting();
			}
			return true;
		},

		getAnchorGroup(hit) {
			const groups = this.getHighlightMapGroups(hit).filter((group) => group && Array.isArray(group.ids) && group.ids.length);
			if (!groups.length) return null;
			const specialNames = new Set(['target', 'head', 'focus', 'keyword', 'root']);
			const special = groups.find((group) => specialNames.has(String(group.name || '').toLowerCase()));
			if (special) return special;
			const named = groups.find((group) => {
				const name = String(group.name || '').trim();
				return name && name !== 'match' && !/^t\d+$/i.test(name);
			});
			if (named) return named;
			const firstNonMatch = groups.find((group) => String(group.name || '').trim().toLowerCase() !== 'match');
			if (firstNonMatch) return firstNonMatch;
			return groups[0] || null;
		},

		getAnchorTokenIds(hit) {
			const group = this.getAnchorGroup(hit);
			if (group && Array.isArray(group.ids) && group.ids.length) return group.ids.filter(Boolean);
			const matchIds = this.getHitMatchIds(hit);
			return matchIds.length ? [matchIds[0]] : [];
		},

		getAnchorLabel(hit) {
			const group = this.getAnchorGroup(hit);
			if (!group) return 'first match';
			return group.querySpan || group.name || 'anchor';
		},

		hitSupportsAnchorKwic(hit) {
			const tokens = this.getContextTokens(hit);
			if (!tokens.length) return false;
			return this.getAnchorTokenIds(hit).length > 0;
		},

		searchSupportsAnchorKwic() {
			const hits = this.search && Array.isArray(this.search.hits) ? this.search.hits : [];
			return hits.some((hit) => this.isHitDiscontinuous(hit) && this.hitSupportsAnchorKwic(hit));
		},

		getAnchorKwicParts(hit) {
			const cacheKey = [
				(this.search && this.search.contextFormat) || 'xml',
				this.currentContextScopeKey(),
				this.activeKwicWindowSize(),
				this.normalizeSearchViewMode(this.search && this.search.viewMode),
				this.kwicHitUsesHighlighting(hit) ? 1 : 0,
			].join('|');
			if (hit && hit.__flexicorpAnchorKwicParts && hit.__flexicorpAnchorKwicPartsFmt === cacheKey) {
				return hit.__flexicorpAnchorKwicParts;
			}
			if (this.isXmlContext() && this.wantsStructuredContext() && this.hitHasStructuredContext(hit)) {
				const anchorIds = this.getAnchorTokenIds(hit);
				if (anchorIds.length) {
					const xmlParts = this.getXmlKwicPartsForIds(hit, anchorIds);
					if (xmlParts) {
						if (hit && typeof hit === 'object') {
							hit.__flexicorpAnchorKwicParts = xmlParts;
							hit.__flexicorpAnchorKwicPartsFmt = cacheKey;
						}
						return xmlParts;
					}
				}
			}
			const tokens = this.getContextTokens(hit);
			const anchorIds = this.getAnchorTokenIds(hit);
			const positions = this.getTokenPositionsForIds(tokens, anchorIds);
			if (!tokens.length || !positions.length) {
				const fallback = this.getKwicParts(hit);
				if (hit && typeof hit === 'object') {
					hit.__flexicorpAnchorKwicParts = fallback;
					hit.__flexicorpAnchorKwicPartsFmt = cacheKey;
				}
				return fallback;
			}
			const start = Math.min(...positions);
			const end = Math.max(...positions);
			const parts = {
				left: tokens.slice(0, start).map((token) => token.text).join(' '),
				match: tokens.slice(start, end + 1).map((token) => token.text).join(' '),
				right: tokens.slice(end + 1).map((token) => token.text).join(' '),
				isXml: false,
			};
			if (hit && typeof hit === 'object') {
				hit.__flexicorpAnchorKwicParts = parts;
				hit.__flexicorpAnchorKwicPartsFmt = cacheKey;
			}
			return parts;
		},

		getActiveKwicParts(hit) {
			if (this.isSearchViewMode('anchor_kwic')) return this.getAnchorKwicParts(hit);
			return this.getKwicParts(hit);
		},

		/** True when KWIC columns render TEITOK XML (x-html) instead of plain text. */
		kwicCellIsXml(hit) {
			const p = this.getActiveKwicParts(hit);
			return !!(p && p.isXml);
		},

		kwicMatchColumnLabel() {
			return this.isSearchViewMode('anchor_kwic') ? 'Anchor' : 'Match';
		},

		/**
		 * Build left/match/right when DOM context tokens are missing but hit.tokens + match ids exist
		 * (flexicorp-pando without TEITOK XML fragment).
		 */
		getKwicPartsFromJsonTokens(hit, matchIds) {
			if (!hit || !Array.isArray(hit.tokens) || !hit.tokens.length || !Array.isArray(matchIds) || !matchIds.length) {
				return null;
			}
			const row = [];
			for (let i = 0; i < hit.tokens.length; i++) {
				const t = hit.tokens[i];
				const id = this.hitTokenIdString(t);
				if (!id) continue;
				row.push({ id, text: this.hitTokenSurfaceText(t) || '' });
			}
			if (!row.length) return null;
			const want = this._expandMatchIdSet(new Set(matchIds.map((m) => String(m))));
			const positions = [];
			for (let i = 0; i < row.length; i++) {
				const rid = String(row[i].id || '');
				if (!rid) continue;
				const aliases = this._expandMatchIdSet(new Set([rid]));
				let matched = false;
				for (const a of aliases) {
					if (want.has(String(a))) {
						matched = true;
						break;
					}
				}
				if (matched) positions.push(i);
			}
			if (!positions.length) {
				const rawStart = Number.isFinite(hit && hit.match_start) ? Number(hit.match_start) : null;
				const rawEnd = Number.isFinite(hit && hit.match_end) ? Number(hit.match_end) : null;
				if (rawStart !== null && rawEnd !== null && rawEnd >= rawStart) {
					const start = Math.max(0, rawStart);
					const end = Math.min(row.length - 1, rawEnd);
					for (let i = start; i <= end; i++) positions.push(i);
				}
			}
			if (!positions.length) return null;
			const start = Math.min(...positions);
			const end = Math.max(...positions);
			const joinSp = (slice) =>
				slice
					.map((x) => x.text)
					.filter((s) => s && s.trim())
					.join(' ');
			return {
				left: joinSp(row.slice(0, start)),
				match: joinSp(row.slice(start, end + 1)),
				right: joinSp(row.slice(end + 1)),
				isXml: false,
			};
		},

		isAbiendoloDebugHit(hit) {
			return false;
		},

		logAbiendoloKwic(stage, hit, payload = {}) {
			/* debug logging disabled */
		},

		logEmptySideKwic(stage, hit, parts, extra = {}) {
			/* debug logging disabled */
		},

		isInvalidKwicCachedParts(parts) {
			if (!parts || typeof parts !== 'object') return true;
			const match = typeof parts.match === 'string' ? parts.match : '';
			if (parts.isXml) return false;
			// Never keep ridx locator rows as rendered KWIC content.
			return this.isRidxStyleContext(match);
		},

		getPlainContextPartsFromHit(hit) {
			if (!hit || typeof hit !== 'object') return null;
			const ctx = hit.context && typeof hit.context === 'object' ? hit.context : null;
			const pick = (obj, keys) => {
				if (!obj || typeof obj !== 'object') return '';
				for (const k of keys) {
					const v = obj[k];
					if (typeof v === 'string' && v.trim() !== '') return v;
				}
				return '';
			};
			const left = pick(ctx, ['left', 'left_context', 'pre', 'before']) || pick(hit, ['left', 'left_context', 'kwic_left', 'pre', 'before']);
			const match = pick(ctx, ['match', 'mid', 'center', 'token']) || pick(hit, ['match', 'mid', 'center', 'token', 'word', 'form']);
			const right = pick(ctx, ['right', 'right_context', 'post', 'after']) || pick(hit, ['right', 'right_context', 'kwic_right', 'post', 'after']);
			if (!left && !match && !right) return null;
			if (this.hitIsSentenceAligned(hit)) {
				const sentenceText = this.getSentenceAlignedMatchText(hit) || match || '';
				return { left: '', match: sentenceText, right: '', isXml: false };
			}
			return { left, match, right, isXml: false };
		},

		getSentenceAlignedKwicParts(hit) {
			if (!this.hitIsSentenceAligned(hit)) return null;
			const textDisplay = this.search && this.search.contextFormat === 'text';
			if (this.isXmlContext() && this.wantsStructuredContext() && this.hitHasTeitokXmlContext(hit) && !textDisplay) {
				const rendered = this.getRenderedXmlContext(hit);
				if (typeof rendered === 'string' && rendered.trim()) {
					return { left: '', match: rendered, right: '', isXml: true };
				}
			}
			const plain = this.getSentenceAlignedMatchText(hit);
			return { left: '', match: typeof plain === 'string' ? plain : '', right: '', isXml: false };
		},

		hitUsesDtokMatchIds(hit) {
			const ids = this.getHitMatchIds(hit);
			if (!ids.length) return false;
			return ids.some((id) => /^d-[^-]+-\d+$/i.test(String(id || '').trim()));
		},

		getKwicParts(hit) {
			const cacheKey = [
				(this.search && this.search.contextFormat) || 'xml',
				this.currentContextScopeKey(),
				this.activeKwicWindowSize(),
				this.normalizeSearchViewMode(this.search && this.search.viewMode),
				this.kwicHitUsesHighlighting(hit) ? 1 : 0,
				this.hitIsSentenceAligned(hit) ? 1 : 0,
			].join('|');
			if (hit && hit.__flexicorpKwicParts && hit.__flexicorpKwicPartsFmt === cacheKey) {
				if (this.isInvalidKwicCachedParts(hit.__flexicorpKwicParts)) {
					delete hit.__flexicorpKwicParts;
					delete hit.__flexicorpKwicPartsFmt;
				} else {
				this.logAbiendoloKwic('cache-hit', hit, {
					left: hit.__flexicorpKwicParts.left || '',
					match: hit.__flexicorpKwicParts.match || '',
					right: hit.__flexicorpKwicParts.right || '',
					isXml: !!hit.__flexicorpKwicParts.isXml,
				});
				return hit.__flexicorpKwicParts;
				}
			}
			const hasXml = this.hitHasTeitokXmlContext(hit);
			const textDisplay = this.search && this.search.contextFormat === 'text';
			let xmlMatchOnlyFallbackText = '';
			let xmlMatchOnlyFallbackHtml = '';
			const inferredPlain = this.getPlainContextPartsFromHit(hit);
			const ctxPlain = inferredPlain
				? { left: inferredPlain.left, match: inferredPlain.match, right: inferredPlain.right }
				: null;
			const sentenceAlignedParts = this.getSentenceAlignedKwicParts(hit);
			if (sentenceAlignedParts) {
				if (hit && typeof hit === 'object') {
					hit.__flexicorpKwicParts = sentenceAlignedParts;
					hit.__flexicorpKwicPartsFmt = cacheKey;
				}
				return sentenceAlignedParts;
			}
			const plainHasSides = !!(
				ctxPlain
				&& (
					(typeof ctxPlain.left === 'string' && ctxPlain.left.trim() !== '')
					|| (typeof ctxPlain.right === 'string' && ctxPlain.right.trim() !== '')
				)
			);
			// Fast path for token-window scope: use backend/native plain context directly.
			// This avoids expensive XML parsing and fixes contracted-form rows where XML clipping
			// may collapse to one orthographic token (e.g. a single-token "abiendolo" row).
			if (
				this.kwicScopeUsesTokenWindow()
				&& ctxPlain
				&& (plainHasSides || !hasXml || textDisplay)
				&& (typeof ctxPlain.left === 'string' || typeof ctxPlain.match === 'string' || typeof ctxPlain.right === 'string')
			) {
				const parts = {
					left: typeof ctxPlain.left === 'string' ? ctxPlain.left : '',
					match: typeof ctxPlain.match === 'string' ? ctxPlain.match : '',
					right: typeof ctxPlain.right === 'string' ? ctxPlain.right : '',
					isXml: false,
				};
				if (hit && typeof hit === 'object') {
					hit.__flexicorpKwicParts = parts;
					hit.__flexicorpKwicPartsFmt = cacheKey;
				}
				this.logEmptySideKwic('plain-fast-path', hit, parts);
				this.logAbiendoloKwic('plain-fast-path', hit, parts);
				return parts;
			}
			// Prefer TEITOK XML KWIC (highlights, facs, …) when a fragment exists and the user did not ask for plain text.
			if (this.isXmlContext() && this.wantsStructuredContext() && hasXml && !textDisplay) {
				const xmlParts = this.getXmlKwicPartsForIds(hit, this.getHitMatchIds(hit));
				if (xmlParts) {
					const plainCtx = ctxPlain;
					// Contracted forms can produce XML fragments with only the enclosing orthographic token
					// (e.g. one <tok> with matching <dtok>), which leaves XML KWIC with no side context.
					// If backend plain context has left/right, prefer that so the row is informative.
					if (this.kwicScopeUsesTokenWindow() && plainHasSides) {
						const xmlLeft = this.fragmentToText(xmlParts.left || '').trim();
						const xmlRight = this.fragmentToText(xmlParts.right || '').trim();
						if (xmlLeft === '' && xmlRight === '') {
							xmlMatchOnlyFallbackText = this.fragmentToText(xmlParts.match || '').trim();
							xmlMatchOnlyFallbackHtml = typeof xmlParts.match === 'string' ? xmlParts.match : '';
							if (plainHasSides) {
								const rescued = {
									left: typeof plainCtx.left === 'string' ? plainCtx.left : '',
									match: typeof plainCtx.match === 'string' ? plainCtx.match : '',
									right: typeof plainCtx.right === 'string' ? plainCtx.right : '',
									isXml: false,
								};
								if (hit && typeof hit === 'object') {
									hit.__flexicorpKwicParts = rescued;
									hit.__flexicorpKwicPartsFmt = cacheKey;
								}
								this.logEmptySideKwic('xml-empty-sides-plain-rescue', hit, rescued);
								return rescued;
							}
							// fall through to plain/json fallback paths below
						} else {
							if (hit && typeof hit === 'object') {
								hit.__flexicorpKwicParts = xmlParts;
								hit.__flexicorpKwicPartsFmt = cacheKey;
							}
							this.logEmptySideKwic('xml-kwic', hit, {
								left: this.fragmentToText(xmlParts.left || ''),
								match: this.fragmentToText(xmlParts.match || ''),
								right: this.fragmentToText(xmlParts.right || ''),
								isXml: true,
							});
							this.logAbiendoloKwic('xml-kwic', hit, {
								leftText: this.fragmentToText(xmlParts.left || '').trim(),
								matchText: this.fragmentToText(xmlParts.match || '').trim(),
								rightText: this.fragmentToText(xmlParts.right || '').trim(),
								isXml: true,
							});
							return xmlParts;
						}
					} else {
						const xmlLeft = this.fragmentToText(xmlParts.left || '').trim();
						const xmlRight = this.fragmentToText(xmlParts.right || '').trim();
						if (this.kwicScopeUsesTokenWindow() && xmlLeft === '' && xmlRight === '') {
							xmlMatchOnlyFallbackText = this.fragmentToText(xmlParts.match || '').trim();
							xmlMatchOnlyFallbackHtml = typeof xmlParts.match === 'string' ? xmlParts.match : '';
							// Generic policy: match-only XML rows are non-final in token-window mode.
							// This is especially common with dtok matches where fragment clipping may
							// collapse to a single orthographic token; continue to richer fallbacks.
							this.logEmptySideKwic('xml-empty-sides-fallthrough', hit, {
								left: xmlLeft,
								match: this.fragmentToText(xmlParts.match || '').trim(),
								right: xmlRight,
								isXml: true,
								dtokMatch: this.hitUsesDtokMatchIds(hit),
							});
						} else {
						if (hit && typeof hit === 'object') {
							hit.__flexicorpKwicParts = xmlParts;
							hit.__flexicorpKwicPartsFmt = cacheKey;
						}
						this.logEmptySideKwic('xml-kwic', hit, {
							left: this.fragmentToText(xmlParts.left || ''),
							match: this.fragmentToText(xmlParts.match || ''),
							right: this.fragmentToText(xmlParts.right || ''),
							isXml: true,
						});
						this.logAbiendoloKwic('xml-kwic', hit, {
							leftText: this.fragmentToText(xmlParts.left || '').trim(),
							matchText: this.fragmentToText(xmlParts.match || '').trim(),
							rightText: this.fragmentToText(xmlParts.right || '').trim(),
							isXml: true,
						});
						return xmlParts;
						}
					}
				}
			}
			const rawParts = this.parseEngineKwicRaw(hit && hit.raw ? hit.raw : '');
			if (rawParts) {
				if (hit && typeof hit === 'object') {
					hit.__flexicorpKwicParts = rawParts;
					hit.__flexicorpKwicPartsFmt = cacheKey;
				}
				this.logEmptySideKwic('raw-delimiter', hit, rawParts);
				this.logAbiendoloKwic('raw-delimiter', hit, rawParts);
				return rawParts;
			}
			// flexicorp-pando: plain { left, match, right } when there is no TEITOK XML, or when Display = plain text.
			if (!hasXml || textDisplay) {
				if (
					ctxPlain &&
					(typeof ctxPlain.left === 'string' || typeof ctxPlain.match === 'string' || typeof ctxPlain.right === 'string')
				) {
					const parts = {
						left: typeof ctxPlain.left === 'string' ? ctxPlain.left : '',
						match: typeof ctxPlain.match === 'string' ? ctxPlain.match : '',
						right: typeof ctxPlain.right === 'string' ? ctxPlain.right : '',
						isXml: false,
					};
					if (hit && typeof hit === 'object') {
						hit.__flexicorpKwicParts = parts;
						hit.__flexicorpKwicPartsFmt = cacheKey;
					}
					this.logEmptySideKwic('plain-fallback', hit, parts);
					this.logAbiendoloKwic('plain-fallback', hit, parts);
					return parts;
				}
			}
			const tokens = this.getContextTokens(hit);
			const matchIds = this.getHitMatchIds(hit);
			// Pando may omit XML fragment but still send hit.tokens + highlight_map (xidx not beside index).
			if (!tokens.length && matchIds.length && !this.hitUsesDtokMatchIds(hit)) {
				const jsonParts = this.getKwicPartsFromJsonTokens(hit, matchIds);
				if (jsonParts) {
					if (hit && typeof hit === 'object') {
						hit.__flexicorpKwicParts = jsonParts;
						hit.__flexicorpKwicPartsFmt = cacheKey;
					}
					this.logEmptySideKwic('json-token-fallback', hit, jsonParts);
					this.logAbiendoloKwic('json-token-fallback', hit, jsonParts);
					return jsonParts;
				}
			}
			if (!tokens.length || !matchIds.length) {
				const ctx = this.getHitContext(hit);
				const safeCtx = this.isRidxStyleContext(ctx) ? '' : ctx;
				if (xmlMatchOnlyFallbackHtml) {
					const fallbackXml = { left: '', match: xmlMatchOnlyFallbackHtml, right: '', isXml: true };
					if (hit && typeof hit === 'object') {
						hit.__flexicorpKwicParts = fallbackXml;
						hit.__flexicorpKwicPartsFmt = cacheKey;
					}
					return fallbackXml;
				}
				const fallback = {
					left: '',
					match: safeCtx || xmlMatchOnlyFallbackText || '',
					right: '',
					isXml: false,
				};
				if (hit && typeof hit === 'object') {
					hit.__flexicorpKwicParts = fallback;
					hit.__flexicorpKwicPartsFmt = cacheKey;
				}
				this.logEmptySideKwic('last-fallback-no-tokens-or-ids', hit, fallback);
				this.logAbiendoloKwic('last-fallback-no-tokens-or-ids', hit, fallback);
				return fallback;
			}
			const positions = [];
			tokens.forEach((token, idx) => {
				if (matchIds.includes(token.id)) positions.push(idx);
			});
			if (!positions.length) {
				const ctx = this.getHitContext(hit);
				const safeCtx = this.isRidxStyleContext(ctx) ? '' : ctx;
				if (xmlMatchOnlyFallbackHtml) {
					const fallbackXml = { left: '', match: xmlMatchOnlyFallbackHtml, right: '', isXml: true };
					if (hit && typeof hit === 'object') {
						hit.__flexicorpKwicParts = fallbackXml;
						hit.__flexicorpKwicPartsFmt = cacheKey;
					}
					return fallbackXml;
				}
				const fallback = {
					left: '',
					match: safeCtx || xmlMatchOnlyFallbackText || '',
					right: '',
					isXml: false,
				};
				if (hit && typeof hit === 'object') {
					hit.__flexicorpKwicParts = fallback;
					hit.__flexicorpKwicPartsFmt = cacheKey;
				}
				this.logEmptySideKwic('last-fallback-no-positions', hit, fallback);
				this.logAbiendoloKwic('last-fallback-no-positions', hit, fallback);
				return fallback;
			}
			const start = Math.min(...positions);
			const end = Math.max(...positions);
			const parts = {
				left: tokens.slice(0, start).map(token => token.text).join(' '),
				match: tokens.slice(start, end + 1).map(token => token.text).join(' '),
				right: tokens.slice(end + 1).map(token => token.text).join(' '),
				isXml: false,
			};
			if (hit && typeof hit === 'object') {
				hit.__flexicorpKwicParts = parts;
				hit.__flexicorpKwicPartsFmt = cacheKey;
			}
			this.logEmptySideKwic('xml-token-position-path', hit, parts);
			this.logAbiendoloKwic('xml-token-position-path', hit, parts);
			return parts;
		},

		getContextGroupKey(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const docId = this.getHitDocLabel(hit) || hit.doc_id || '';
			const ctxObj = hit.context && typeof hit.context === 'object' ? hit.context : null;
			const rawSentenceId = this.getHitSentenceIdForNavigation(hit);
			const sentenceId = String(rawSentenceId).trim();
			const scope = ctxObj && ctxObj.scope ? String(ctxObj.scope) : '';
			const format = ctxObj && ctxObj.format ? String(ctxObj.format) : '';
			if (sentenceId) return `sent::${docId}::${scope}::${format}::${sentenceId}`;
			// flexicorp-pando (no XML fragment): getHitContext() often collapses to the match word only
			// (synthetic line from hit.tokens), so never use context-string grouping before corpus positions —
			// otherwise every hit merges into one row (e.g. ten × "byt").
			const ms = hit.match_start;
			const me = hit.match_end;
			if (ms !== undefined && ms !== null && ms !== '' && Number.isFinite(Number(ms))) {
				const endPart = me !== undefined && me !== null && me !== '' && Number.isFinite(Number(me)) ? String(me) : '';
				return `pos::${docId}::${ms}::${endPart}`;
			}
			const context = this.getHitContext(hit);
			if (context) return `ctx::${docId}::${scope}::${format}::${context}`;
			if (hit.raw) return `raw::${docId}::${hit.raw}`;
			return `hit::${docId}::${hit.match_start || ''}::${hit.match_end || ''}::${this.getHitTokenIds(hit)}`;
		},

		getMergedHitCount(hit) {
			if (!hit || typeof hit !== 'object') return 1;
			const count = Number(hit.__flexicorpMergedHitCount);
			return Number.isFinite(count) && count > 0 ? count : 1;
		},

		buildMergedHit(hitGroup) {
			const sourceHits = Array.isArray(hitGroup) ? hitGroup.filter(Boolean) : [];
			if (!sourceHits.length) return null;
			const base = Object.assign({}, sourceHits[0]);
			const tokenIds = [];
			const groupsByName = {};
			const groupsByComposite = {};
			const alignedCompanions = [];
			let mergedTeitokTuview = null;
			const companionSeen = new Set();
			const companionKeyFor = (h) => {
				if (!h || typeof h !== 'object') return '';
				const docId = this.getHitDocLabel(h) || h.doc_id || '';
				const sid = String(this.getHitSentenceIdForNavigation(h) || '').trim();
				const ms = Number.isFinite(Number(h.match_start)) ? String(h.match_start) : '';
				const me = Number.isFinite(Number(h.match_end)) ? String(h.match_end) : '';
				const tok = this.getHitTokenIds(h) || '';
				const ctx = this.getSentenceAlignedMatchText(h) || this.getHitContext(h) || '';
				return `${docId}::${sid}::${ms}::${me}::${tok}::${ctx}`;
			};
			sourceHits.forEach(hit => {
				if (!mergedTeitokTuview && hit && hit.teitok_tuview && typeof hit.teitok_tuview === 'object') {
					mergedTeitokTuview = hit.teitok_tuview;
				}
				if (Array.isArray(hit.toks)) tokenIds.push(...hit.toks.filter(Boolean));
				this.getHighlightMapGroups(hit).forEach(({ name, ids, resultGroup }) => {
					if (!groupsByName[name]) groupsByName[name] = [];
					(Array.isArray(ids) ? ids : []).forEach(id => {
						if (id) groupsByName[name].push(id);
					});
					const rgKey = Number.isFinite(Number(resultGroup)) ? Number(resultGroup) : -1;
					const compositeKey = `${String(name)}::${String(rgKey)}`;
					if (!groupsByComposite[compositeKey]) {
						groupsByComposite[compositeKey] = { name: String(name), result_group: rgKey, tok_ids: [] };
					}
					(Array.isArray(ids) ? ids : []).forEach(id => {
						if (id) groupsByComposite[compositeKey].tok_ids.push(id);
					});
				});
				const companion = this.getAlignedCompanionHit(hit);
				if (companion) {
					const key = companionKeyFor(companion);
					if (key && !companionSeen.has(key)) {
						companionSeen.add(key);
						alignedCompanions.push(companion);
					}
				}
			});
			const uniq = (arr) => Array.from(new Set((arr || []).filter(Boolean)));
			const mergedTokenIds = uniq(tokenIds);
			const mergedHighlightMap = {
				groups: [],
				default: { tok_ids: mergedTokenIds },
			};
			Object.keys(groupsByComposite).forEach((key) => {
				const entry = groupsByComposite[key];
				const ids = uniq(entry && Array.isArray(entry.tok_ids) ? entry.tok_ids : []);
				if (!ids.length) return;
				const rg = Number.isFinite(Number(entry.result_group)) && Number(entry.result_group) >= 0 ? Number(entry.result_group) : null;
				const gid = rg !== null ? `${entry.name}_rg${rg}` : String(entry.name);
				mergedHighlightMap.groups.push({
					id: gid,
					name: String(entry.name),
					tok_ids: ids,
					result_group: rg,
				});
			});
			Object.keys(groupsByName).forEach(name => {
				const ids = uniq(groupsByName[name]);
				if (!ids.length) return;
				if (name === 'match') mergedHighlightMap.match = ids;
				else mergedHighlightMap[name] = ids;
			});
			base.toks = mergedTokenIds;
			base.highlight_map = mergedHighlightMap;
			base.__flexicorpMergedHits = sourceHits;
			base.__flexicorpMergedHitCount = sourceHits.length;
			if (alignedCompanions.length) {
				base.__flexicorpAlignedCompanions = alignedCompanions;
				base.aligned_counterpart = alignedCompanions[0];
			}
			if (mergedTeitokTuview) base.teitok_tuview = mergedTeitokTuview;
			return base;
		},

		getMergedSearchHits() {
			const hits = this.search && Array.isArray(this.search.hits) ? this.search.hits : [];
			if (!hits.length) return [];
			const shouldGroup = !(this.settings && this.settings.groupHitsBySentence === false);
			// Structural aligned targets (<s>, <p>, ...) already represent a full region on the target side.
			// Merging by sentence key collapses many distinct aligned rows into one unhelpful occurrence.
			if (this.queryUsesStructuralAlignedTarget()) return hits;
			if (!shouldGroup) return hits;
			const rawHits = _fcRaw(hits);
			const memoKey = this.isXmlContext() ? 'xml' : 'plain';
			const memo = _fcMergedHitsMemo.get(rawHits);
			if (memo && memo.key === memoKey && memo.length === rawHits.length) return memo.value;
			const merged = [];
			const seen = {};
			hits.forEach(hit => {
				const key = this.getContextGroupKey(hit);
				if (!seen[key]) {
					seen[key] = [];
					merged.push(seen[key]);
				}
				seen[key].push(hit);
			});
			const value = merged
				.map(group => this.buildMergedHit(group))
				.filter(Boolean);
			_fcMergedHitsMemo.set(rawHits, { key: memoKey, length: rawHits.length, value });
			return value;
		},

		groupHitsByDoc() {
			const groups = [];
			const seen = {};
			this.getMergedSearchHits().forEach(hit => {
				const key = this.getHitDocLabel(hit) || '(unknown document)';
				if (!seen[key]) {
					seen[key] = {
						docId: key,
						docCid: this.getHitDocCid(hit),
						docUrl: this.getHitDocUrl(hit),
						docTooltip: this.getDocumentTooltip(key),
						hits: [],
						totalHits: 0,
					};
					groups.push(seen[key]);
				}
				const bucket = seen[key];
				if (this.queryUsesStructuralAlignedTarget()) {
					if (!bucket._bySourceKey) bucket._bySourceKey = {};
					const srcKey = this.getStructuralAlignedSourceGroupKey(hit);
					let sourceRow = bucket._bySourceKey[srcKey];
					if (!sourceRow) {
						sourceRow = Object.assign({}, hit);
						sourceRow.__flexicorpAlignedCompanions = [];
						bucket._bySourceKey[srcKey] = sourceRow;
						bucket.hits.push(sourceRow);
						bucket.totalHits += this.getMergedHitCount(hit);
					}
					const companion = this.getAlignedCompanionHit(hit);
					if (companion) {
						const compKey = this.getStructuralAlignedCompanionKey(companion);
						const existing = sourceRow.__flexicorpAlignedCompanions.some((c) => this.getStructuralAlignedCompanionKey(c) === compKey);
						if (!existing) sourceRow.__flexicorpAlignedCompanions.push(companion);
						if (!sourceRow.aligned_counterpart) sourceRow.aligned_counterpart = companion;
					}
				} else {
					bucket.hits.push(hit);
					bucket.totalHits += this.getMergedHitCount(hit);
				}
			});
			return groups;
		},
	};
};
