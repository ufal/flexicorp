/**
 * flexicorp TEITOK UI: Hit context, ids, audio, facsimile, alignment and eye-candy.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpApp() (flexicorp.js)
 * through window.ttFlexicorpCoreParts; `this` is the component.
 */
window.ttFlexicorpCoreParts = window.ttFlexicorpCoreParts || {};
window.ttFlexicorpCoreParts.hitdetail = function () {
	/**
	 * Memo for values derived from one search result, kept outside the Alpine component so that
	 * filling it does not trigger re-renders. Keys are the raw (unproxied) hits array or hit object;
	 * a new search, or a further page, replaces the hits array, so entries expire with it.
	 * Without it the results table rebuilt the merged hit list (re-parsing every XML fragment)
	 * for each x-show/x-bind that asked for it: about 50 times for 25 aligned rows.
	 */
	const _fcRaw = (o) => (o && typeof window !== 'undefined' && window.Alpine && typeof window.Alpine.raw === 'function') ? window.Alpine.raw(o) : o;
	const _fcSentenceIdMemo = new WeakMap();
	return {
		/** True if string looks like tt-cwb-ridx input (path, tabs, numbers, token id) rather than XML. */
		isRidxStyleContext(str) {
			if (typeof str !== 'string' || !str.trim()) return false;
			const line = str.trim();
			// Single line: path.xml + tab + digits + tab + digits + tab + token id (e.g. w-1127)
			if (line.indexOf('\n') >= 0) return false;
			if (!/\.xml\t\d+\t\d+\t[\w-]+/.test(line)) return false;
			// Do not treat as XML if it has no angle brackets
			if (/<[\w/]/.test(line)) return false;
			return true;
		},

		/** Extract a single string from hit.context (may be object with .data or .xml). */
		getContextString(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const c = hit.context;
			if (typeof c === 'string') return c;
			if (c && typeof c === 'object' && typeof c.data === 'string') return c.data;
			if (c && typeof c === 'object' && typeof c.xml === 'string') return c.xml;
			if (typeof hit.context_data === 'string') return hit.context_data;
			return '';
		},

		/**
		 * True when the hit carries TEITOK / fragment XML for rich display (tok, highlights, facs, …).
		 * Plain flexicorp-pando { left, match, right } without XML must stay false so we do not skip XML.
		 */
		hitHasTeitokXmlContext(hit) {
			if (!hit || typeof hit !== 'object') return false;
			const looksXml = (s) =>
				typeof s === 'string' &&
				s.trim() &&
				(s.includes('<tok') ||
					s.includes('<dtok') ||
					s.includes('<mtok') ||
					s.includes('<seg') ||
					s.includes('<s ') ||
					s.includes('<s>'));
			if (looksXml(hit.fragment)) return true;
			if (looksXml(hit.context_xml)) return true;
			if (looksXml(hit.content)) return true;
			if (typeof hit.context === 'string' && looksXml(hit.context)) return true;
			const c = hit.context;
			if (c && typeof c === 'object') {
				if (looksXml(c.data)) return true;
				if (looksXml(c.xml)) return true;
			}
			if (looksXml(hit.context_text)) return true;
			if (looksXml(hit.context_data)) return true;
			return false;
		},

		/** flexicorp-pando plain KWIC object (no fragment) — one line for table / fallback only. */
		getPlainPandoKwicLine(hit) {
			const c = hit && hit.context;
			if (!c || typeof c !== 'object') return '';
			if (typeof c.data === 'string' && c.data.trim()) return '';
			if (typeof c.xml === 'string' && c.xml.trim()) return '';
			if (typeof c.left !== 'string' && typeof c.match !== 'string' && typeof c.right !== 'string') return '';
			const a = typeof c.left === 'string' ? c.left : '';
			const b = typeof c.match === 'string' ? c.match : '';
			const d = typeof c.right === 'string' ? c.right : '';
			return [a, b, d].join(' ').replace(/\s+/g, ' ').trim();
		},

		/** Plain surface string from a backend token object (Pando JSON, etc.). */
		hitTokenSurfaceText(tok) {
			if (!tok || typeof tok !== 'object') return '';
			const keys = ['lemma', 'word', 'form', 'surface', 'text', 'norm'];
			for (let i = 0; i < keys.length; i++) {
				const k = keys[i];
				if (typeof tok[k] === 'string' && tok[k].trim()) return tok[k].trim();
			}
			return '';
		},

		/** Token id for matching highlight_map / KWIC (aligned with flexicorp.php). */
		hitTokenIdString(tok) {
			if (!tok || typeof tok !== 'object') return '';
			if (tok.tuid != null && String(tok.tuid).trim() !== '') return String(tok.tuid).trim();
			const id = tok.id != null ? String(tok.id).trim() : '';
			if (id !== '' && id !== '_') return id;
			return '';
		},

		/** One line of plain text from hit.tokens when no XML fragment is present. */
		hitTokensToPlainLine(tokens) {
			if (!Array.isArray(tokens) || !tokens.length) return '';
			const parts = [];
			for (let i = 0; i < tokens.length; i++) {
				const s = this.hitTokenSurfaceText(tokens[i]);
				if (s) parts.push(s);
			}
			return parts.join(' ');
		},

		/** When fragment/raw/context are empty but tokens[] carries surfaces (flexicorp-pando without xidx XML). */
		getHitContextSyntheticFromTokens(hit) {
			if (!hit || typeof hit !== 'object' || !Array.isArray(hit.tokens) || !hit.tokens.length) return '';
			return this.hitTokensToPlainLine(hit.tokens);
		},

		getHitContext(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const finalize = (raw) => this.normalizeContextArtifacts(typeof raw === 'string' ? raw : '');
			const fmt = (this.search && this.search.contextFormat) || 'xml';
			// Prefer format-specific field so "raw" (text) shows plain text like the server would after fragment_to_text,
			// even when the last search was XML-only (cached hit.context.format === 'xml').
			if (fmt === 'text') {
				const toRawDisplay = (s) => {
					const t = typeof s === 'string' ? s : '';
					if (!t.trim()) return '';
					const decoded = this.decodeHtmlEntities(t);
					if (this.isRidxStyleContext(decoded)) return decoded;
					return this.fragmentToText(decoded);
				};
				if (typeof hit.context_text === 'string' && hit.context_text.trim()) {
					return finalize(toRawDisplay(hit.context_text));
				}
				const c = hit.context;
				if (c && typeof c === 'object' && String(c.format || '').toLowerCase() === 'xml' && typeof c.data === 'string' && c.data.trim()) {
					return finalize(toRawDisplay(c.data));
				}
				let raw = this.getContextString(hit) || hit.content || hit.context_xml || hit.fragment || '';
				if (!String(raw).trim()) {
					const plain = this.getPlainPandoKwicLine(hit);
					if (plain) raw = plain;
				}
				if (!String(raw).trim()) {
					const synth = this.getHitContextSyntheticFromTokens(hit);
					if (synth) raw = synth;
				}
				return finalize(toRawDisplay(raw));
			}
			if (fmt === 'xml') {
				// Backend may put XML in context.data (nested) or content; prefer explicit context.data
				const nested = this.getContextString(hit);
				const candidates = [nested, hit.context_xml, hit.content, hit.context_text, hit.context_data, hit.fragment];
				if (typeof hit.context === 'string') candidates.unshift(hit.context);
				for (const raw of candidates) {
					if (typeof raw !== 'string' || !raw) continue;
					if (this.isRidxStyleContext(raw)) continue;
					return finalize(raw);
				}
				// Aligned side may only provide plain KWIC fields (left/match/right) without XML.
				const plainParts = this.getPlainContextPartsFromHit(hit);
				if (plainParts) {
					const joined = [plainParts.left, plainParts.match, plainParts.right]
						.map((s) => (typeof s === 'string' ? s.trim() : ''))
						.filter((s) => s.length > 0)
						.join(' ');
					if (joined) return finalize(joined);
				}
				// Backend may only provide ridx-style line (e.g. tt-cwb-xidx not run); use it so we show something
				let ridxFallback = '';
				const manateePlain = this.manateeHitPlainLine(hit);
				if (manateePlain) {
					ridxFallback = manateePlain;
				}
				if (!String(ridxFallback).trim()) {
					ridxFallback =
						hit.raw ||
						hit.context_xml ||
						(typeof hit.context === 'string' ? hit.context : '') ||
						hit.content ||
						hit.context_text ||
						hit.fragment ||
						'';
				}
				if (!String(ridxFallback).trim()) {
					const synth = this.getHitContextSyntheticFromTokens(hit);
					if (synth) ridxFallback = synth;
				}
				if (!String(ridxFallback).trim()) {
					const plain = this.getPlainPandoKwicLine(hit);
					if (plain) ridxFallback = plain;
				}
				return finalize(ridxFallback);
			}
			let raw =
				hit.content ||
				this.getContextString(hit) ||
				hit.context_xml ||
				hit.context_text ||
				(typeof hit.context === 'string' ? hit.context : '') ||
				hit.fragment ||
				'';
			if (!String(raw).trim()) {
				const plain = this.getPlainPandoKwicLine(hit);
				if (plain) raw = plain;
			}
			if (!String(raw).trim()) {
				const synth = this.getHitContextSyntheticFromTokens(hit);
				if (synth) raw = synth;
			}
			return finalize(raw);
		},

		normalizeContextArtifacts(str) {
			if (typeof str !== 'string' || !str) return str;
			// Common mixed-decoding artifact for non-breaking spaces in TEITOK XML.
			return str.replace(/\u00c2\u00a0/g, '\u00a0');
		},

		/** Decode HTML entities so encoded XML (e.g. &lt;tok&gt;) can be parsed or shown as plain text */
		decodeHtmlEntities(str) {
			if (typeof str !== 'string' || !str) return str;
			if (!/&(?:lt|gt|amp|quot|#\d+;|#x[\da-f]+;)/i.test(str)) return str;
			let out = str;
			let prev = null;
			let guard = 0;
			while (prev !== out && guard++ < 8) {
				prev = out;
				const textarea = document.createElement('textarea');
				textarea.innerHTML = out;
				out = this.normalizeContextArtifacts(textarea.value);
				if (!/&(?:lt|gt|amp|quot|#\d+;|#x[\da-f]+;)/i.test(out)) break;
			}
			return out;
		},

		/**
		 * Plain text from a TEITOK XML fragment (matches flexicorp.teitok_context.fragment_to_text).
		 * Used when Display is "raw" (text) but the last search still has XML in context.data.
		 */
		fragmentToText(fragment) {
			if (typeof fragment !== 'string' || !fragment.trim()) return '';
			const wrapped = `<root>${fragment}</root>`;
			try {
				const parser = new DOMParser();
				const xmlDoc = parser.parseFromString(wrapped, 'application/xml');
				if (xmlDoc.querySelector('parsererror')) throw new Error('parse');
				const root = xmlDoc.documentElement;
				if (!root) return '';
				const parts = [];
				const walk = (node) => {
					if (node.nodeType === Node.TEXT_NODE) {
						const t = (node.textContent || '').trim();
						if (t) parts.push(t);
					} else if (node.childNodes) {
						node.childNodes.forEach(walk);
					}
				};
				walk(root);
				return parts.join(' ');
			} catch (_) {
				const plain = fragment.replace(/<[^>]+>/g, ' ');
				return plain.split(/\s+/).filter(Boolean).join(' ');
			}
		},

		/** Context string for the text-format &lt;pre&gt;: decoded so user sees readable text, not entity codes */
		getDisplayableTextContext(hit) {
			if (this.kwicScopeUsesTokenWindow()) {
				const parts = this.getKwicParts(hit);
				if (parts) {
					const merged = [parts.left, parts.match, parts.right]
						.map((s) => (typeof s === 'string' ? s.trim() : ''))
						.filter((s) => s.length > 0)
						.join(' ');
					if (merged) return this.decodeHtmlEntities(merged);
				}
			}
			return this.decodeHtmlEntities(this.getHitContext(hit));
		},

		isEngineKwicRaw(str) {
			return typeof str === 'string' && str.includes(this.rawKwicDelimiter || '--%%%--');
		},

		parseEngineKwicRaw(str) {
			if (!this.isEngineKwicRaw(str)) return null;
			const delim = this.rawKwicDelimiter || '--%%%--';
			const parts = String(str).split(delim);
			if (parts.length < 3) return null;
			return {
				left: this.normalizeContextArtifacts(parts[0] || ''),
				match: this.normalizeContextArtifacts(parts[1] || ''),
				right: this.normalizeContextArtifacts(parts.slice(2).join(delim) || ''),
				isXml: false,
			};
		},

		getRenderedRawHit(hit) {
			const plain = this.manateeHitPlainLine(hit);
			if (plain) {
				const doc = this.getHitDocLabel(hit);
				const line = doc ? `${doc}: ${plain}` : plain;
				return `<span class="flexicorp-hit-plain-match">${this.escapeHtmlForPre(line)}</span>`;
			}
			const raw = hit && typeof hit === 'object' ? String(hit.raw || '') : '';
			if (!raw) return '';
			if (this.hitLooksLikeManateeEngine(hit) && raw.includes('\t')) {
				const parts = raw.split('\t').filter((p) => String(p).trim() !== '');
				if (parts.length >= 2) {
					const matchText = parts[parts.length - 1];
					const docId = parts[0];
					const line = docId && matchText ? `${docId}: ${matchText}` : matchText || raw;
					return `<span class="flexicorp-hit-plain-match">${this.escapeHtmlForPre(line)}</span>`;
				}
			}
			const parsed = this.parseEngineKwicRaw(raw);
			if (!parsed) {
				return `<span class="flexicorp-hit-kwic-raw">${this.escapeHtmlForPre(raw)}</span>`;
			}
			return [
				'<span class="flexicorp-hit-kwic-raw">',
				`<span class="flexicorp-hit-kwic-raw-left">${this.escapeHtmlForPre(parsed.left)}</span>`,
				`<span class="flexicorp-hit-kwic-raw-match flexicorp-hit-match">${this.escapeHtmlForPre(parsed.match)}</span>`,
				`<span class="flexicorp-hit-kwic-raw-right">${this.escapeHtmlForPre(parsed.right)}</span>`,
				'</span>',
			].join('');
		},

		getHitTokenId(hit) {
			if (!hit || typeof hit !== 'object') return '';
			// highlight_map.default.tok_ids carries TEITOK xml:ids when the backend resolved them;
			// hit.toks are surface strings from Manatee lexicon — prefer ids for jmp= / TEITOK links.
			const hmIds =
				hit.highlight_map &&
				hit.highlight_map.default &&
				Array.isArray(hit.highlight_map.default.tok_ids)
					? hit.highlight_map.default.tok_ids.filter((t) => t != null && String(t).trim() !== '')
					: [];
			if (hmIds.length) return String(hmIds[0]);
			if (Array.isArray(hit.toks) && hit.toks.length) return hit.toks[0];
			if (hit.tok_id) return hit.tok_id;
			if (hit.token_id) return hit.token_id;
			return '';
		},

		getHitTokenIds(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const hmIds =
				hit.highlight_map &&
				hit.highlight_map.default &&
				Array.isArray(hit.highlight_map.default.tok_ids)
					? hit.highlight_map.default.tok_ids.filter((t) => t != null && String(t).trim() !== '')
					: [];
			if (hmIds.length) return hmIds.map((t) => String(t)).join(', ');
			if (Array.isArray(hit.toks) && hit.toks.length) return hit.toks.join(', ');
			const one = this.getHitTokenId(hit);
			return one || '';
		},

		getHitDocCid(hit) {
			if (!hit || typeof hit !== 'object') return '';
			// Keep raw TEITOK cid for showdocinfo/tokview hover lookups.
			// Normalized ids are for UI labels and local doc-lookup only.
			return String(hit.doc_id || '').trim();
		},

		getHitDocLabel(hit) {
			if (!hit || typeof hit !== 'object') return '';
			return this.normalizeDocId(hit.doc_id || '') || (hit.doc_id || '');
		},

		getHitDocUrl(hit) {
			if (!hit || typeof hit !== 'object' || !hit.doc_id) return '';
			if (!this.currentBackendSupportsDocumentLinks()) return '';
			const url = new URL('index.php', window.location.href);
			url.searchParams.set('action', 'file');
			url.searchParams.set('cid', hit.doc_id);
			const tokId = this.getHitTokenId(hit);
			if (tokId) url.searchParams.set('jmp', tokId);
			return url.toString();
		},

		_getHitEyeCandyCache(hit) {
			if (!hit || typeof hit !== 'object') return null;
			if (!hit.__flexicorpEyeCandy) hit.__flexicorpEyeCandy = {};
			if (!hit.__flexicorpEyeCandyCacheKey) {
				const docId = this.getHitDocCid(hit) || hit.doc_id || '';
				const sid = this.getHitSentenceIdForNavigation(hit);
				const fmt = (hit && hit.context && hit.context.format) ? hit.context.format : '';
				hit.__flexicorpEyeCandyCacheKey = `${docId}::${sid}::${fmt}::${this.getHitTokenIds(hit)}`;
			}
			return hit.__flexicorpEyeCandy;
		},

		getHitSentenceId(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const ctx = hit.context && typeof hit.context === 'object' ? hit.context : null;
			const locator = ctx && ctx.locator && typeof ctx.locator === 'object' ? ctx.locator : null;
			return String(hit.sentence_id ?? (locator && locator.sentence_id) ?? '').trim();
		},

		/**
		 * flexicorp-pando (and similar) often put the sentence region id on token rows (e.g. s_id) while
		 * hit.sentence_id is empty — CWB tabulate instead fills sentence_id on the hit.
		 */
		getHitSentenceRegionIdFromTokens(hit) {
			if (!hit || typeof hit !== 'object' || !Array.isArray(hit.tokens) || !hit.tokens.length) return '';
			const keys = ['s_id', 'sentence_id', 'sid'];
			const tryTok = (tok) => {
				if (!tok || typeof tok !== 'object') return '';
				for (let i = 0; i < keys.length; i++) {
					const k = keys[i];
					if (tok[k] == null) continue;
					const v = String(tok[k]).trim();
					if (v !== '' && v !== '_') return v;
				}
				return '';
			};
			const matchIds = this.getHitMatchIds(hit);
			const want = new Set((matchIds || []).map((x) => String(x)));
			if (want.size) {
				for (let i = 0; i < hit.tokens.length; i++) {
					const tok = hit.tokens[i];
					const tid = this.hitTokenIdString(tok);
					if (!tid || !want.has(tid)) continue;
					const s = tryTok(tok);
					if (s) return s;
				}
			}
			for (let i = 0; i < hit.tokens.length; i++) {
				const s = tryTok(hit.tokens[i]);
				if (s) return s;
			}
			return '';
		},

		/**
		 * First sentence-like element in parsed XML: &lt;s&gt;, &lt;seg&gt;, or &lt;sentence&gt;.
		 * Uses localName/nodeName so both plain &lt;s&gt; and prefixed names like &lt;tei:s&gt; work.
		 */
		_flexicorpFirstSentenceLikeElement(xmlRoot) {
			if (!xmlRoot || !xmlRoot.getElementsByTagName) return null;
			const wanted = new Set(['s', 'seg', 'sentence']);
			const all = xmlRoot.getElementsByTagName('*');
			for (let i = 0; i < all.length; i++) {
				const el = all[i];
				if (!el) continue;
				const local = String(el.localName || '').toLowerCase();
				if (local && wanted.has(local)) return el;
				const node = String(el.nodeName || '').toLowerCase();
				const bare = node.includes(':') ? node.split(':').pop() : node;
				if (bare && wanted.has(bare)) return el;
			}
			return null;
		},

		/**
		 * Sentence/region id from the TEITOK XML fragment when tokens omit s_id (e.g. some Pando attrs).
		 * Picks the first sentence-like wrapper: &lt;s&gt;, &lt;seg&gt;, or &lt;sentence&gt; with s_id or id.
		 */
		getHitSentenceRegionIdFromXmlContext(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const candidates = [
				hit.fragment,
				hit.context_xml,
				hit.context_data,
				this.getContextString(hit),
				hit.content,
				typeof hit.context === 'string' ? hit.context : '',
			];
			let raw = '';
			for (let i = 0; i < candidates.length; i++) {
				const c = candidates[i];
				if (typeof c === 'string' && c.trim() && /[</]/.test(c)) {
					raw = c;
					break;
				}
			}
			if (!raw && hit.context && typeof hit.context === 'object') {
				const d = hit.context.data;
				const x = hit.context.xml;
				if (typeof d === 'string' && d.trim() && /[</]/.test(d)) raw = d;
				else if (typeof x === 'string' && x.trim() && /[</]/.test(x)) raw = x;
			}
			if (!raw || !raw.trim()) return '';
			if (this.isRidxStyleContext(raw)) return '';
			let toParse = raw;
			if (/&(?:lt|gt|amp|quot|#\d+;|#x[\da-f]+;)/i.test(toParse)) {
				toParse = this.decodeHtmlEntities(toParse);
			}
			const tryReadSidFromElement = (el) => {
				if (!el || !el.getAttribute) return '';
				let sid = (el.getAttribute('s_id') || el.getAttribute('id') || '').trim();
				if (sid && sid !== '_') return sid;
				if (el.getAttributeNS) {
					const xid = (el.getAttributeNS('http://www.w3.org/XML/1998/namespace', 'id') || '').trim();
					if (xid && xid !== '_') return xid;
				}
				return '';
			};
			try {
				const parser = new DOMParser();
				const xmlDoc = parser.parseFromString(`<flexicorp-root>${toParse}</flexicorp-root>`, 'application/xml');
				if (xmlDoc.querySelector('parsererror')) return '';
				const root = xmlDoc.documentElement;
				if (root) {
					const el = this._flexicorpFirstSentenceLikeElement(root);
					if (el) {
						const sid = tryReadSidFromElement(el);
						if (sid) return sid;
					}
					const anySid = root.querySelector('[s_id]');
					if (anySid && anySid.getAttribute) {
						const v = (anySid.getAttribute('s_id') || '').trim();
						if (v && v !== '_') return v;
					}
				}
				return '';
			} catch (_) {
				/* ignore */
			}
			return '';
		},

		/**
		 * Walk from matched token nodes up to a sentence-like wrapper and read &lt;s&gt;/&lt;seg&gt; @id / @s_id.
		 * Needed when the fragment omits a leading &lt;s&gt; sibling but matched tokens are nested under &lt;s&gt;.
		 */
		getHitSentenceIdFromXmlAncestorsOfMatch(hit) {
			if (!this.isXmlContext() || !this.hitHasStructuredContext(hit)) return '';
			let container = null;
			try {
				container = this.buildContextContainer(hit);
			} catch (_) {
				return '';
			}
			if (!container || !container.querySelector || container.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback')) {
				return '';
			}
			const wanted = new Set(['s', 'seg', 'sentence']);
			const matchIds = this.getHitMatchIds(hit);
			const idSet = this._expandMatchIdSet(new Set(Array.isArray(matchIds) ? matchIds : []));
			const tokNodes = this.xmlContextTokenElements(container);
			const seeds = idSet.size
				? tokNodes.filter((n) => {
						const ids = this.xmlElementStableIds(n);
						return ids.some((id) => idSet.has(id));
					})
				: tokNodes;
			const readSidFromEl = (el) => {
				if (!el || !el.getAttribute) return '';
				let sid = (el.getAttribute('s_id') || el.getAttribute('id') || '').trim();
				if (sid && sid !== '_') return sid;
				if (el.getAttributeNS) {
					const xid = (el.getAttributeNS('http://www.w3.org/XML/1998/namespace', 'id') || '').trim();
					if (xid && xid !== '_') return xid;
				}
				return '';
			};
			const walkUp = (start) => {
				let el = start;
				while (el && el !== container) {
					const local = String(el.localName || '').toLowerCase();
					const bare = String(el.nodeName || '').toLowerCase();
					const bareTail = bare.includes(':') ? bare.split(':').pop() : bare;
					if (wanted.has(local) || wanted.has(bareTail)) {
						const sid = readSidFromEl(el);
						if (sid) return sid;
					}
					el = el.parentElement;
				}
				return '';
			};
			for (let i = 0; i < seeds.length; i += 1) {
				const sid = walkUp(seeds[i]);
				if (sid) return sid;
			}
			const allTok = this.xmlContextTokenElements(container);
			const firstTok = allTok[0] || null;
			return firstTok ? walkUp(firstTok) : '';
		},

		/** Last resort: pull sentence id from raw XML text when DOM parsing misses (parallel slices, escaped markup). */
		_flexicorpExtractSentenceIdRegexFromContext(hit) {
			if (!hit || typeof hit !== 'object') return '';
			const candidates = [
				hit.fragment,
				hit.context_xml,
				hit.context_data,
				this.getContextString(hit),
				hit.content,
				typeof hit.context === 'string' ? hit.context : '',
			];
			if (hit.context && typeof hit.context === 'object') {
				const d = hit.context.data;
				const x = hit.context.xml;
				if (typeof d === 'string') candidates.push(d);
				if (typeof x === 'string') candidates.push(x);
			}
			const reList = [
				/<s\b[^>]*?\bid\s*=\s*["']([^"']+)["']/i,
				/<s\b[^>]*?\bs_id\s*=\s*["']([^"']+)["']/i,
				/<seg\b[^>]*?\bid\s*=\s*["']([^"']+)["']/i,
				/<seg\b[^>]*?\bs_id\s*=\s*["']([^"']+)["']/i,
			];
			for (let i = 0; i < candidates.length; i += 1) {
				const raw = candidates[i];
				if (typeof raw !== 'string' || !raw.trim()) continue;
				let text = raw;
				if (/&(?:lt|gt|amp|quot|#\d+;|#x[\da-f]+;)/i.test(text)) {
					text = this.decodeHtmlEntities(text);
				}
				for (let r = 0; r < reList.length; r += 1) {
					const m = text.match(reList[r]);
					if (m && m[1]) {
						const v = String(m[1]).trim();
						if (v && v !== '_') return v;
					}
				}
			}
			return '';
		},

		/** Sentence id for navigation (deptree, etc.): CWB fields first, then token s_id (Pando), then XML &lt;s&gt;/&lt;seg&gt;. */
		getHitSentenceIdForNavigation(hit) {
			const rawHit = hit && typeof hit === 'object' ? _fcRaw(hit) : null;
			const memoKey = this.isXmlContext() ? 'xml' : 'plain';
			if (rawHit) {
				const m = _fcSentenceIdMemo.get(rawHit);
				if (m && m.key === memoKey) return m.value;
			}
			const value = this._getHitSentenceIdForNavigationUncached(hit);
			if (rawHit) _fcSentenceIdMemo.set(rawHit, { key: memoKey, value });
			return value;
		},

		_getHitSentenceIdForNavigationUncached(hit) {
			const a = this.getHitSentenceId(hit);
			if (a) return a;
			const b = this.getHitSentenceRegionIdFromTokens(hit);
			if (b) return b;
			const c = this.getHitSentenceRegionIdFromXmlContext(hit);
			if (c) return c;
			const d = this.getHitSentenceIdFromXmlAncestorsOfMatch(hit);
			if (d) return d;
			return this._flexicorpExtractSentenceIdRegexFromContext(hit);
		},

		getHitAudioInfo(hit) {
			const cache = this._getHitEyeCandyCache(hit);
			if (cache && cache.audio !== undefined) return cache.audio;
			// Best-effort: derive from structured XML fragment.
			let out = null;
			if (this.isXmlContext() && this.hitHasStructuredContext(hit)) {
				const container = this.buildContextContainer(hit);
				// Skip if context is fallback <pre> (ridx line / non-XML).
				if (container && !container.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback')) {
					const matchIds = this.getHitMatchIds(hit);
					const idSet = this._expandMatchIdSet(new Set(matchIds));

					// 1) Media URL: <media>/<audio>/<video>, <recording>, chunk_url (e.g. ParCzech), or utterance / ancestors.
					let url = '';
					const media = container.querySelector('media, audio, video');
					if (media) {
						url = (media.getAttribute('url') || media.getAttribute('src') || media.getAttribute('href') || '').trim();
					}
					if (!url) {
						const rec = container.querySelector('recording');
						if (rec) url = (rec.getAttribute('url') || rec.getAttribute('target') || '').trim();
					}
					if (!url) {
						const chunkEl = container.querySelector('[chunk_url], u[chunk_url]');
						if (chunkEl) url = (chunkEl.getAttribute('chunk_url') || '').trim();
					}
					if (!url) {
						const uMedia = container.querySelector('u[url], u[src], u[href]');
						if (uMedia) {
							url = (uMedia.getAttribute('url') || uMedia.getAttribute('src') || uMedia.getAttribute('href') || '').trim();
						}
					}

					// 2) start/end: often on <u> (oral/TEI) or another wrapper, not on <tok>.
					let start = null;
					let end = null;
					const readTimes = (el) => {
						if (!el || !el.getAttribute) return null;
						const sr = el.getAttribute('start');
						const er = el.getAttribute('end');
						if (sr === null || er === null || sr === '' || er === '') return null;
						const s = Number(sr);
						const e = Number(er);
						if (!Number.isFinite(s) || !Number.isFinite(e) || e < s) return null;
						return { start: s, end: e };
					};
					const seeds = this.xmlContextTokenElements(container).filter(
						(n) => n && n.getAttribute && (idSet.size ? idSet.has(this.xmlElementId(n)) : true)
					);
					const walkSeeds = seeds.length ? seeds : this.xmlContextTokenElements(container);
					for (const t of walkSeeds) {
						let el = t;
						while (el && el !== container) {
							const times = readTimes(el);
							if (times) {
								start = times.start;
								end = times.end;
								break;
							}
							if (!url) {
								const au = (el.getAttribute('url') || el.getAttribute('src') || el.getAttribute('href') || '').trim();
								if (au) url = au;
							}
							el = el.parentElement;
						}
						if (start != null && end != null) break;
					}
					if (start == null || end == null) {
						const uSpan = container.querySelector('u[start][end]');
						const times = readTimes(uSpan);
						if (times) {
							start = times.start;
							end = times.end;
						}
					}
					// Match-span timing on tokens (oral corpora): first matched token @start, last @end.
					if (start == null && end == null && seeds.length > 0) {
						const firstTok = seeds[0];
						const lastTok = seeds[seeds.length - 1];
						const sr = firstTok.getAttribute('start');
						const er = lastTok.getAttribute('end');
						if (sr !== null && er !== null && sr !== '' && er !== '') {
							const s = Number(sr);
							const e = Number(er);
							if (Number.isFinite(s) && Number.isFinite(e) && e >= s) {
								start = s;
								end = e;
							}
						}
					}

					if (!url && walkSeeds[0]) {
						let el = walkSeeds[0];
						while (el && el !== container) {
							const au = (el.getAttribute('url') || el.getAttribute('src') || el.getAttribute('href') || '').trim();
							if (au) {
								url = au;
								break;
							}
							el = el.parentElement;
						}
					}

					const hasTimes = Number.isFinite(start) && Number.isFinite(end) && end >= start;
					const hasUrl = typeof url === 'string' && url.trim() !== '';
					if (hasUrl || hasTimes) {
						out = {
							url: hasUrl ? url.trim() : '',
							start: hasTimes ? start : null,
							end: hasTimes ? end : null,
						};
					}
				}
			}
			if (cache) cache.audio = out;
			return out;
		},

		/**
		 * Parse tuid from raw context XML when the DOM slice omits an opening <s> or namespaced tags
		 * confuse querySelector (mirrors flexicorp_pando extract_scope_tuid_from_xml_open_tags).
		 */
		extractScopeTuidFromContextString(raw) {
			if (typeof raw !== 'string' || !raw.trim()) return '';
			let ctx = raw;
			if (/&(?:lt|gt|amp|quot|#\d+;|#x[\da-f]+;)/i.test(ctx)) {
				ctx = this.decodeHtmlEntities(ctx);
			}
			let best = '';
			const tags = ['<s', '<seg', '<u'];
			for (let ti = 0; ti < tags.length; ti += 1) {
				const tag = tags[ti];
				let p = 0;
				for (;;) {
					p = ctx.indexOf(tag, p);
					if (p < 0) break;
					const gt = ctx.indexOf('>', p);
					if (gt < 0) break;
					const open = ctx.slice(p, gt + 1);
					for (let qi = 0; qi < 2; qi += 1) {
						const quote = qi === 0 ? '"' : "'";
						const needle = `tuid=${quote}`;
						let k = 0;
						for (;;) {
							k = open.indexOf(needle, k);
							if (k < 0) break;
							const v0 = k + needle.length;
							const v1 = open.indexOf(quote, v0);
							if (v1 > v0) {
								const val = open.slice(v0, v1).trim();
								if (val && val !== '_') best = val;
							}
							k = v1 < 0 ? k + needle.length : v1 + 1;
						}
					}
					p = gt + 1;
				}
			}
			// Prefixed TEI: <tei:s ... tuid="..."> (and :seg / :u)
			const prefRe = /<[^>/\s:]+\s*:\s*(s|seg|u)\b[^>]*\btuid\s*=\s*"([^"]+)"/gi;
			let pm;
			for (;;) {
				pm = prefRe.exec(ctx);
				if (!pm) break;
				const val = String(pm[2] || '').trim();
				if (val && val !== '_') best = val;
			}
			return best;
		},

		/** Set `localStorage.setItem('flexicorp_debug_alignment_tuid','1')` or `window.FLEXICORP_DEBUG_ALIGNMENT_TUID = true`, then reload. */
		_alignmentTuidDebugEnabled() {
			try {
				return (
					(typeof window !== 'undefined' && window.FLEXICORP_DEBUG_ALIGNMENT_TUID === true) ||
					localStorage.getItem('flexicorp_debug_alignment_tuid') === '1'
				);
			} catch (_) {
				return typeof window !== 'undefined' && window.FLEXICORP_DEBUG_ALIGNMENT_TUID === true;
			}
		},

		_logAlignmentTuidDebug(hit, step, extra) {
			if (!this._alignmentTuidDebugEnabled()) return;
			try {
				const base = {
					step,
					doc_id: hit && hit.doc_id,
					tokenIds: hit && typeof this.getHitTokenIds === 'function' ? this.getHitTokenIds(hit) : '',
					matchIds: hit && typeof this.getHitMatchIds === 'function' ? this.getHitMatchIds(hit) : [],
					isXmlContext: typeof this.isXmlContext === 'function' ? this.isXmlContext() : '',
					supportsXmlContext:
						typeof this.currentBackendSupportsXmlContext === 'function' ? this.currentBackendSupportsXmlContext() : '',
					hitHasStructuredContext:
						hit && typeof this.hitHasStructuredContext === 'function' ? this.hitHasStructuredContext(hit) : '',
					contextFormat: this.search && this.search.contextFormat,
					contextScope: this.search && this.search.contextScope,
					kwicScopeUsesTokenWindow:
						typeof this.kwicScopeUsesTokenWindow === 'function' ? this.kwicScopeUsesTokenWindow() : '',
					mergedHit: !!(hit && hit.__flexicorpMergedHits),
					mergedCount: hit && hit.__flexicorpMergedHitCount,
				};
				console.log('[flexicorp:alignment-tuid]', Object.assign(base, extra || {}));
			} catch (e) {
				console.warn('[flexicorp:alignment-tuid] log failed', e);
			}
		},

		_truncXmlDbg(s, max) {
			const t = typeof s === 'string' ? s : '';
			const n = Number(max) > 40 ? Number(max) : 220;
			if (t.length <= n) return t;
			return `${t.slice(0, n)}…[${t.length} chars]`;
		},

		/**
		 * Sentence / utterance / token alignment id from context XML: prefer <s>/<seg>/<u> @tuid,
		 * else @tuid on the matched tok/dtok/mtok/w (TEITOK often puts tuid on tokens only).
		 */
		getHitEnclosingScopeTuidFromXmlContext(hit) {
			const assembled = this._assembledKwicXmlContextForHit(hit);
			const rawCtx = this.getHitContext(hit) || '';
			const fromStr =
				this.extractScopeTuidFromContextString(assembled) || this.extractScopeTuidFromContextString(rawCtx);
			this._logAlignmentTuidDebug(hit, 'enclosingScope:strings', {
				assembledLen: assembled.length,
				rawCtxLen: rawCtx.length,
				fromStrExtract: fromStr || '(empty)',
				assembledHead: this._truncXmlDbg(assembled, 200),
				rawCtxHead: this._truncXmlDbg(rawCtx, 200),
			});
			if (!this.isXmlContext() || !this.hitHasStructuredContext(hit)) {
				this._logAlignmentTuidDebug(hit, 'enclosingScope:earlyReturn', {
					reason: !this.isXmlContext() ? '!isXmlContext' : '!hitHasStructuredContext',
					returning: fromStr || '(empty)',
				});
				return fromStr;
			}
			try {
				const candidates = [];
				if (assembled.trim()) candidates.push(assembled);
				if (rawCtx.trim()) candidates.push(rawCtx);
				const seen = new Set();
				for (let ci = 0; ci < candidates.length; ci += 1) {
					const ctx = candidates[ci];
					if (!ctx || seen.has(ctx)) continue;
					seen.add(ctx);
					const container = this.buildContextContainer(hit, ctx);
					const preFb = !!(container && container.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback'));
					if (!container || preFb) {
						this._logAlignmentTuidDebug(hit, 'enclosingScope:skipCandidate', {
							candidateIndex: ci,
							preFallback: preFb,
							ctxHead: this._truncXmlDbg(ctx, 160),
						});
						continue;
					}
					const matchIds = this.getHitMatchIds(hit);
					const idSet = this._expandMatchIdSet(new Set(Array.isArray(matchIds) ? matchIds : []));
					const seeds = this.xmlContextTokenElements(container).filter((n) => {
						if (!n || !n.getAttribute) return false;
						if (!idSet.size) return true;
						const stable = this.xmlElementStableIds(n);
						const withTuid = String(n.getAttribute('tuid') || '').trim();
						if (withTuid && idSet.has(withTuid)) return true;
						return stable.some((id) => idSet.has(id));
					});
					const walkSeeds = seeds.length ? seeds : this.xmlContextTokenElements(container);
					this._logAlignmentTuidDebug(hit, 'enclosingScope:domWalk', {
						candidateIndex: ci,
						matchIds,
						idSetSize: idSet.size,
						seedsCount: seeds.length,
						walkSeedsCount: walkSeeds.length,
						tokenNodesInContainer: this.xmlContextTokenElements(container).length,
					});
					for (let si = 0; si < walkSeeds.length; si += 1) {
						let tokenTuid = '';
						let scopeTuid = '';
						let el = walkSeeds[si];
						while (el && el !== container) {
							const ln = (el.localName || el.tagName || '').toLowerCase();
							const tu = el.getAttribute && String(el.getAttribute('tuid') || '').trim();
							if (tu && tu !== '_') {
								if ((ln === 's' || ln === 'seg' || ln === 'u') && !scopeTuid) scopeTuid = tu;
								else if ((ln === 'tok' || ln === 'dtok' || ln === 'mtok' || ln === 'w') && !tokenTuid) tokenTuid = tu;
							}
							el = el.parentElement;
						}
						if (scopeTuid) {
							this._logAlignmentTuidDebug(hit, 'enclosingScope:foundScopeTuid', { scopeTuid, seedIndex: si });
							return scopeTuid;
						}
						if (tokenTuid) {
							this._logAlignmentTuidDebug(hit, 'enclosingScope:foundTokenTuid', { tokenTuid, seedIndex: si });
							return tokenTuid;
						}
					}
				}
				this._logAlignmentTuidDebug(hit, 'enclosingScope:fallbackFromStr', { returning: fromStr || '(empty)' });
				return fromStr;
			} catch (err) {
				this._logAlignmentTuidDebug(hit, 'enclosingScope:catch', { err: err && err.message ? err.message : String(err) });
				return fromStr;
			}
		},

		getHitDependencyTreeInfo(hit) {
			const cache = this._getHitEyeCandyCache(hit);
			if (cache && cache.deptree !== undefined) return cache.deptree;
			let out = null;
			const cid = hit && hit.doc_id ? String(hit.doc_id) : '';
			const sidResolved = this.getHitSentenceIdForNavigation(hit);
			const hasDepsInTokens =
				hit &&
				Array.isArray(hit.tokens) &&
				hit.tokens.some((t) => {
					if (!t || typeof t !== 'object') return false;
					const h = t.head != null ? String(t.head).trim() : '';
					const d = t.deprel != null ? String(t.deprel).trim() : '';
					return (h !== '' && h !== '_') || (d !== '' && d !== '_');
				});
			if (this.isXmlContext() && this.hitHasStructuredContext(hit)) {
				const container = this.buildContextContainer(hit);
				if (container && !container.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback')) {
					const hasDeps = !!container.querySelector('tok[head], tok[deprel], dtok[head], dtok[deprel], mtok[head], mtok[deprel]');
					// Pando: sentence id often only on token rows (s_id), not on hit.sentence_id / XML <s>.
					if (hasDeps && cid) out = { cid, sid: sidResolved || '' };
				}
			}
			// Plain Pando JSON (no XML fragment): still offer tree when token rows carry deps + s_id.
			if (!out && cid && hasDepsInTokens) {
				out = { cid, sid: sidResolved || '' };
			}
			if (cache) cache.deptree = out;
			return out;
		},

		/** Shared token uid for parallel alignment (TEITOK depalign). */
		getHitAlignmentTuidForDepalign(hit) {
			if (!hit || typeof hit !== 'object') return '';
			this._logAlignmentTuidDebug(hit, 'depalign:entry', {});
			if (hit.tuid != null && String(hit.tuid).trim()) {
				const v = String(hit.tuid).trim();
				this._logAlignmentTuidDebug(hit, 'depalign:hit.tuid', { value: v });
				return v;
			}
			const scopeFromXml = this.getHitEnclosingScopeTuidFromXmlContext(hit);
			if (scopeFromXml) {
				this._logAlignmentTuidDebug(hit, 'depalign:scopeFromXml', { value: scopeFromXml });
				return scopeFromXml;
			}
			const matchIds = this.getHitMatchIds(hit);
			const want = new Set((Array.isArray(matchIds) ? matchIds : []).map((x) => String(x)));
			if (Array.isArray(hit.tokens)) {
				for (let i = 0; i < hit.tokens.length; i += 1) {
					const tok = hit.tokens[i];
					if (!tok || typeof tok !== 'object') continue;
					const tid = this.hitTokenIdString(tok);
					if (!want.size || (tid && want.has(tid))) {
						const tu = tok.tuid != null ? String(tok.tuid).trim() : '';
						if (tu) {
							this._logAlignmentTuidDebug(hit, 'depalign:tokens.matchedRow', { index: i, tid, tuid: tu });
							return tu;
						}
					}
				}
				for (let j = 0; j < hit.tokens.length; j += 1) {
					const tok = hit.tokens[j];
					if (!tok || typeof tok !== 'object') continue;
					const tu = tok.tuid != null ? String(tok.tuid).trim() : '';
					if (tu) {
						this._logAlignmentTuidDebug(hit, 'depalign:tokens.anyRow', { index: j, tuid: tu });
						return tu;
					}
				}
			}
			this._logAlignmentTuidDebug(hit, 'depalign:noTokenJsonTuid', { tokensLen: Array.isArray(hit.tokens) ? hit.tokens.length : 0 });
			if (this.isXmlContext() && this.hitHasStructuredContext(hit)) {
				try {
					const assembled = this._assembledKwicXmlContextForHit(hit);
					const rawCtx = this.getHitContext(hit) || '';
					const candidates = [];
					if (assembled.trim()) candidates.push(assembled);
					if (rawCtx.trim()) candidates.push(rawCtx);
					this._logAlignmentTuidDebug(hit, 'depalign:domTokAttr', {
						assembledLen: assembled.length,
						rawCtxLen: rawCtx.length,
						candidateCount: candidates.length,
					});
					const seen = new Set();
					for (let ci = 0; ci < candidates.length; ci += 1) {
						const ctx = candidates[ci];
						if (!ctx || seen.has(ctx)) continue;
						seen.add(ctx);
						const container = this.buildContextContainer(hit, ctx);
						if (!container || container.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback'))
							continue;
						const mids = this.getHitMatchIds(hit);
						const idSet = this._expandMatchIdSet(new Set(Array.isArray(mids) ? mids : []));
						const nodes = this.xmlContextTokenElements(container).filter(
							(n) => n.getAttribute && String(n.getAttribute('tuid') || '').trim()
						);
						this._logAlignmentTuidDebug(hit, 'depalign:domTokAttr.nodes', {
							candidateIndex: ci,
							nodesWithTuid: nodes.length,
							idSetSize: idSet.size,
						});
						for (let i = 0; i < nodes.length; i += 1) {
							const n = nodes[i];
							const stable = this.xmlElementStableIds(n);
							const tuid = String(n.getAttribute('tuid') || '').trim();
							if (!tuid) continue;
							if (!idSet.size || stable.some((id) => idSet.has(id))) {
								this._logAlignmentTuidDebug(hit, 'depalign:domTokAttr.pick', { stable, tuid });
								return tuid;
							}
						}
						const any = nodes[0] || null;
						if (any && any.getAttribute) {
							const tuid = String(any.getAttribute('tuid') || '').trim();
							if (tuid) {
								this._logAlignmentTuidDebug(hit, 'depalign:domTokAttr.firstAny', { tuid });
								return tuid;
							}
						}
					}
				} catch (err) {
					this._logAlignmentTuidDebug(hit, 'depalign:domTokAttr.catch', {
						err: err && err.message ? err.message : String(err),
					});
				}
			} else {
				this._logAlignmentTuidDebug(hit, 'depalign:skipDomTokAttr', {
					reason: !this.isXmlContext() ? '!isXmlContext' : '!hitHasStructuredContext',
				});
			}
			this._logAlignmentTuidDebug(hit, 'depalign:empty', { result: '' });
			return '';
		},

		/**
		 * Depalign expects sentence-level alignment id on both sides; token offsets like ".w13" are stripped.
		 * Optional TEITOK jump= passes the full token tuid for highlighting after navigation.
		 */
		_flexicorpDepalignSentenceTuidFromTokenTuid(fullTokenTuid) {
			const s = String(fullTokenTuid || '').trim();
			if (!s) return '';
			const m = s.match(/^(.+)\.[wt]\d+$/i);
			return m && m[1] ? String(m[1]).trim() : s;
		},

		/**
		 * Parallel corpus: open TEITOK depalign with both document ids and sentence-level alignment id.
		 * See index.php?action=depalign&ids=…&tuid=<sentence>&jump=<token> (optional).
		 */
		getHitParallelDepalignInfo(hit) {
			if (!hit || typeof hit !== 'object') return null;
			if (!this.searchHasAlignedHits()) return null;
			const companion = this.getAlignedCompanionHit(hit);
			if (!companion || typeof companion !== 'object') return null;
			const depA = this.getHitDependencyTreeInfo(hit);
			const depB = this.getHitDependencyTreeInfo(companion);
			if (!depA || !depA.cid || !depB || !depB.cid) return null;
			const cidA = String(depA.cid).trim();
			const cidB = String(depB.cid).trim();
			if (!cidA || !cidB || cidA === cidB) return null;
			const side = this.getHitOwnAlignedSide(hit);
			let srcCid = cidA;
			let tgtCid = cidB;
			if (side === 'target') {
				srcCid = cidB;
				tgtCid = cidA;
			} else if (side === 'source') {
				srcCid = cidA;
				tgtCid = cidB;
			}
			const tokenTuid =
				this.getHitAlignmentTuidForDepalign(hit) ||
				this.getHitAlignmentTuidForDepalign(companion);
			if (!tokenTuid) return null;
			const sentenceTuid = this._flexicorpDepalignSentenceTuidFromTokenTuid(tokenTuid);
			if (!sentenceTuid) return null;
			const out = { ids: `${srcCid},${tgtCid}`, tuid: sentenceTuid };
			if (tokenTuid !== sentenceTuid) {
				out.jump = tokenTuid;
			}
			return out;
		},

		getParallelDepalignInfoForPair(sourceHit, companionHit) {
			if (!sourceHit || typeof sourceHit !== 'object' || !companionHit || typeof companionHit !== 'object') return null;
			const srcCid = String(this.getHitDocCid(sourceHit) || sourceHit.doc_id || sourceHit.text_id || '').trim();
			const tgtCid = String(this.getHitDocCid(companionHit) || companionHit.doc_id || companionHit.text_id || '').trim();
			if (!srcCid || !tgtCid || srcCid === tgtCid) return null;
			const tokenTuid =
				this.getHitAlignmentTuidForDepalign(sourceHit) ||
				this.getHitAlignmentTuidForDepalign(companionHit);
			if (!tokenTuid) return null;
			const sentenceTuid = this._flexicorpDepalignSentenceTuidFromTokenTuid(tokenTuid);
			if (!sentenceTuid) return null;
			const out = { ids: `${srcCid},${tgtCid}`, tuid: sentenceTuid };
			if (tokenTuid !== sentenceTuid) out.jump = tokenTuid;
			return out;
		},

		/** flexicorp-pando may attach `teitok_tuview` on aligned pairs (group vs pair docs/tuid for `action=tuview`). */
		getHitTeitokTuviewPayload(hit) {
			if (!hit || typeof hit !== 'object') return null;
			if (hit.teitok_tuview && typeof hit.teitok_tuview === 'object') return hit.teitok_tuview;
			const raw = hit.aligned_payload_raw;
			if (raw && typeof raw === 'object' && raw.teitok_tuview && typeof raw.teitok_tuview === 'object') {
				return raw.teitok_tuview;
			}
			return null;
		},

		teitokTuviewUrlFromDocsTuidSet(docs, tuid, set) {
			const d = String(docs || '').trim();
			const t = String(tuid || '').trim();
			if (!d || !t) return '';
			const u = new URL('index.php', window.location.href);
			u.searchParams.set('action', 'tuview');
			u.searchParams.set('docs', d);
			u.searchParams.set('tuid', t);
			const s = String(set || '').trim();
			if (s) u.searchParams.set('set', s);
			return u.toString();
		},

		/** All aligned documents / tuids for this source row (same source position, multiple targets). */
		teitokTuviewGroupUrl(sourceHit) {
			const tv = this.getHitTeitokTuviewPayload(sourceHit);
			if (!tv || !tv.group || typeof tv.group !== 'object') return '';
			const g = tv.group;
			return this.teitokTuviewUrlFromDocsTuidSet(g.docs, g.tuid, tv.set);
		},

		/** This source row together with one aligned target only. */
		teitokTuviewPairUrl(companionHit) {
			if (!companionHit || typeof companionHit !== 'object') return '';
			const tv = this.getHitTeitokTuviewPayload(companionHit);
			if (!tv || !tv.pair || typeof tv.pair !== 'object') return '';
			const p = tv.pair;
			return this.teitokTuviewUrlFromDocsTuidSet(p.docs, p.tuid, tv.set);
		},

		getEyeCandyHtmlForAlignedPair(sourceHit, companionHit) {
			const depAlign = this.getParallelDepalignInfoForPair(sourceHit, companionHit);
			if (!depAlign || !depAlign.ids || !depAlign.tuid) return '';
			const urlAlign = new URL('index.php', window.location.href);
			urlAlign.searchParams.set('action', 'depalign');
			urlAlign.searchParams.set('ids', depAlign.ids);
			urlAlign.searchParams.set('tuid', depAlign.tuid);
			if (depAlign.jump) {
				urlAlign.searchParams.set('jump', depAlign.jump);
			}
			return `<a class="flexicorp-eye-candy-btn" href="${this.escapeHtmlForAttr(urlAlign.toString())}" target="_blank" rel="noopener noreferrer" title="Open aligned dependency trees (sentence scope); jump highlights the match token">trees</a>`;
		},

		/** All parallel depalign targets for a source hit (one per grouped aligned companion). */
		getHitParallelDepalignInfos(hit) {
			if (!hit || typeof hit !== 'object') return [];
			const alignSide = this.getHitOwnAlignedSide(hit);
			if (alignSide === 'target') return [];
			const depA = this.getHitDependencyTreeInfo(hit);
			const srcCid = String((depA && depA.cid) || this.getHitDocCid(hit) || hit.doc_id || hit.text_id || '').trim();
			if (!srcCid) return [];
			const companions = this.getGroupedAlignedCompanions(hit);
			if (!Array.isArray(companions) || !companions.length) return [];
			const out = [];
			const seen = new Set();
			for (let i = 0; i < companions.length; i += 1) {
				const companion = companions[i];
				if (!companion || typeof companion !== 'object') continue;
				const depB = this.getHitDependencyTreeInfo(companion);
				const tgtCid = String((depB && depB.cid) || this.getHitDocCid(companion) || companion.doc_id || companion.text_id || '').trim();
				if (!tgtCid || tgtCid === srcCid) continue;
				const tokenTuid =
					this.getHitAlignmentTuidForDepalign(hit) ||
					this.getHitAlignmentTuidForDepalign(companion);
				if (!tokenTuid) continue;
				const sentenceTuid = this._flexicorpDepalignSentenceTuidFromTokenTuid(tokenTuid);
				if (!sentenceTuid) continue;
				const info = { ids: `${srcCid},${tgtCid}`, tuid: sentenceTuid };
				if (tokenTuid !== sentenceTuid) info.jump = tokenTuid;
				const langHint = this.getAlignedDistinguishingValue(companion);
				if (langHint) info.label = this.formatAlignedDistinguishingValue(langHint);
				const key = `${info.ids}::${info.tuid}::${info.jump || ''}`;
				if (seen.has(key)) continue;
				seen.add(key);
				out.push(info);
			}
			return out;
		},

		/** TEI line / line-break geometry from the context fragment (verse line), when @facs is only on pb. */
		_parseBBox(raw) {
			const nums = String(raw == null ? '' : raw).trim().split(/\s+/).map(Number).filter((n) => Number.isFinite(n));
			if (nums.length < 4) return null;
			const [x1, y1, x2, y2] = nums.slice(0, 4);
			return (x2 > x1 && y2 > y1) ? [x1, y1, x2, y2] : null;
		},

		_unionBBox(a, b) {
			return [Math.min(a[0], b[0]), Math.min(a[1], b[1]), Math.max(a[2], b[2]), Math.max(a[3], b[3])];
		},

		/**
		 * Line box from the fragment: the <l bbox> around the first matched token, else
		 * the last <lb bbox> before it (not simply the first line in the fragment, which
		 * may be a later line: Litoměřice "bogu" is on the line before the sentence's
		 * only <lb>); without matched ids, the first line.
		 */
		_lineBBoxFromFragmentContainer(container, idSet) {
			if (!container || !container.querySelector) return null;
			let line = null;
			if (idSet && idSet.size) {
				const ref = this._refTokenForPrecedingPb(container, idSet);
				if (ref) {
					line = ref.closest ? ref.closest('l[bbox]') : null;
					if (!line) {
						const before = Array.from(container.querySelectorAll('lb[bbox]')).filter(
							(lb) => lb.compareDocumentPosition(ref) & Node.DOCUMENT_POSITION_FOLLOWING
						);
						if (before.length) line = before[before.length - 1];
						else if (container.querySelector('lb[bbox]')) return null; // its line starts before the fragment
					}
				}
			}
			if (!line) line = container.querySelector('l[bbox], lb[bbox]');
			if (!line || !line.getAttribute) return null;
			const raw = (line.getAttribute('bbox') || '').trim();
			if (!raw) return null;
			const nums = raw.split(/\s+/).map(Number).filter((n) => Number.isFinite(n));
			if (nums.length < 4) return null;
			const [x1, y1, x2, y2] = nums.slice(0, 4);
			if (!(x2 > x1 && y2 > y1)) return null;
			return [x1, y1, x2, y2];
		},

		/**
		 * First matched token in the fragment (for ./preceding::pb[1]/@facs-style resolution in XML).
		 */
		_refTokenForPrecedingPb(container, idSet) {
			if (!container || !container.querySelector) return null;
			if (idSet && idSet.size) {
				const expanded = this._expandMatchIdSet(idSet instanceof Set ? idSet : new Set(idSet));
				for (const el of container.querySelectorAll('tok, dtok, mtok')) {
					const id = this.xmlElementId(el);
					if (id && expanded.has(id)) return el;
				}
			}
			return container.querySelector('tok, dtok, mtok');
		},

		/**
		 * Nearest preceding pb/@facs before refEl (same idea as CQP xpath ./preceding::pb[1]/@facs).
		 */
		_facsFromPrecedingPb(container, refEl) {
			if (!container || !refEl || !container.querySelectorAll) return '';
			const pbs = Array.from(container.querySelectorAll('pb[facs]'));
			if (!pbs.length) return '';
			const before = pbs.filter(
				(pb) => pb.compareDocumentPosition(refEl) & Node.DOCUMENT_POSITION_FOLLOWING
			);
			if (!before.length) return '';
			let best = before[0];
			for (let i = 1; i < before.length; i++) {
				const pb = before[i];
				if (best.compareDocumentPosition(pb) & Node.DOCUMENT_POSITION_FOLLOWING) best = pb;
			}
			return (best.getAttribute('facs') || '').trim();
		},

		/**
		 * Union bbox of matched tokens only (not every node with @bbox in the fragment).
		 */
		_unionBBoxFromMatchedTokens(container, idSet) {
			if (!container || !container.querySelectorAll || !idSet || !idSet.size) return null;
			const expanded = this._expandMatchIdSet(idSet instanceof Set ? idSet : new Set(idSet));
			let lx = Infinity;
			let ly = Infinity;
			let ux = -Infinity;
			let uy = -Infinity;
			let any = false;
			for (const node of container.querySelectorAll('tok[bbox], dtok[bbox], mtok[bbox]')) {
				if (!node || !node.getAttribute) continue;
				const id = this.xmlElementId(node);
				if (!id || !expanded.has(id)) continue;
				const bboxRaw = (node.getAttribute('bbox') || '').trim();
				const parts = bboxRaw.split(/\s+/).map(Number).filter((n) => Number.isFinite(n));
				if (parts.length < 4) continue;
				const [x1, y1, x2, y2] = parts.slice(0, 4);
				lx = Math.min(lx, x1);
				ly = Math.min(ly, y1);
				ux = Math.max(ux, x2);
				uy = Math.max(uy, y2);
				any = true;
			}
			if (!any || ![lx, ly, ux, uy].every((n) => Number.isFinite(n)) || !(ux > lx && uy > ly)) return null;
			return [lx, ly, ux, uy];
		},

		getHitFacsimileInfo(hit) {
			const cache = this._getHitEyeCandyCache(hit);
			if (cache && cache.facs !== undefined) return cache.facs;
			let out = null;
			let resolvedScope = '';
			if (this.isXmlContext() && this.hitHasStructuredContext(hit)) {
				resolvedScope = (hit && hit.context && (hit.context.resolved_scope || hit.context.scope) ? String(hit.context.resolved_scope || hit.context.scope) : '')
					.trim()
					.toLowerCase();
				const container = this.buildContextContainer(hit);
				if (container && !container.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback')) {
					// Prefer bbox spanning matched tokens only; facs may be absent on <tok> in XML
					// (CQP defines it via xpath e.g. ./preceding::pb[1]/@facs) and is supplied as hit.facs.
					const matchIds = this.getHitMatchIds(hit);
					const idSet = this._expandMatchIdSet(new Set(matchIds));

					// Do not use *[bbox][facs] — <pb bbox facs> is page-sized and breaks line crops; CQP
					// facs for the match comes from hit.facs (xpath), not from whichever pb appears in the slice.
					const candidates = Array.from(container.querySelectorAll(
						'l[bbox][facs], lb[bbox][facs], tok[bbox][facs], dtok[bbox][facs], mtok[bbox][facs]'
					));

					const filtered = idSet.size
						? candidates.filter((n) => {
							const id = n && n.getAttribute ? this.xmlElementId(n) : '';
							return id && idSet.has(id);
						})
						: candidates;

					const parseFromNodes = (nodes) => {
						// Parse bbox & keep union in the first observed facs image.
						let chosen = '';
						let lx = Infinity;
						let ly = Infinity;
						let ux = -Infinity;
						let uy = -Infinity;
						for (const node of nodes) {
							if (!node || !node.getAttribute) continue;
							const bboxRaw = (node.getAttribute('bbox') || '').trim();
							const facsRaw = (node.getAttribute('facs') || '').trim();
							if (!facsRaw || !bboxRaw) continue;
							const parts = bboxRaw.split(/\s+/).map(Number).filter(n => Number.isFinite(n));
							if (parts.length < 4) continue;

							if (!chosen) chosen = facsRaw;
							// If tokens span different facsimile pages, fall back to the first.
							if (chosen !== facsRaw) continue;

							const [x1, y1, x2, y2] = parts.slice(0, 4);
							lx = Math.min(lx, x1);
							ly = Math.min(ly, y1);
							ux = Math.max(ux, x2);
							uy = Math.max(uy, y2);
						}

						if (!chosen) return null;
						if (![lx, ly, ux, uy].every((n) => Number.isFinite(n))) return null;
						return { facs: chosen, bbox: [lx, ly, ux, uy] };
					};

					out = parseFromNodes(filtered);
					// Indexed facs (CQP tabulate / Pando hit row) matches cqpsettings xpath; fragment may
					// still carry a different pb — always use backend path for the image when provided.
					if (out && hit && typeof hit === 'object' && hit.facs) {
						const hf = String(hit.facs).trim();
						if (hf) out.facs = hf;
					}

					// Corpus often has @facs only on CQP side (derived from preceding pb), not on tokens.
					if (!out && idSet.size) {
						const unionTok = this._unionBBoxFromMatchedTokens(container, idSet);
						const facsFromHit = hit && typeof hit === 'object' && hit.facs ? String(hit.facs).trim() : '';
						if (unionTok && facsFromHit) {
							out = { facs: facsFromHit, bbox: unionTok };
						} else if (unionTok && !facsFromHit) {
							const refTok = this._refTokenForPrecedingPb(container, idSet);
							const facsPb = refTok ? this._facsFromPrecedingPb(container, refTok) : '';
							if (facsPb) out = { facs: facsPb, bbox: unionTok };
						}
					}

				}
			}

			// Fallback: the XML fragment might contain bbox but *not* facs (e.g. facs
			// can live on <pb/> outside the returned XML fragment). In that case we
			// take facs from the backend (hit.facs) and build bbox from XML (union),
			// instead of requiring @facs inside the fragment.
			if (!out && hit && typeof hit === 'object' && hit.facs) {
				const facsRaw = String(hit.facs).trim();
				let bboxArr = hit.bbox;
				let unionBBox = null;

				// If the XML fragment has @bbox but not @facs, we can still build a bbox
				// spanning all matched token ids, then reuse hit.facs for the image.
				let fallbackContainer = null;
				if (this.isXmlContext() && this.hitHasStructuredContext(hit)) {
					fallbackContainer = this.buildContextContainer(hit);
					if (fallbackContainer && !fallbackContainer.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback')) {
						const matchIds = this.getHitMatchIds(hit);
						const idSet = new Set(matchIds);
						// Do not union every *[bbox] (line/page boxes skew the crop); matched tokens only.
						unionBBox = idSet.size ? this._unionBBoxFromMatchedTokens(fallbackContainer, idSet) : null;
					}
				}

				// Prefer <l>/<lb> line bbox, then token union; use CQP tabulate bbox only as last resort
				// (it is often the full page from pb, not the verse line).
				let lineBBox = null;
				if (fallbackContainer && !fallbackContainer.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback')) {
					lineBBox = this._parseBBox(hit.line_bbox)
						|| this._lineBBoxFromFragmentContainer(fallbackContainer, new Set(this.getHitMatchIds(hit)));
				}
				if (bboxArr !== undefined && bboxArr !== null && !Array.isArray(bboxArr)) {
					const bboxStr = String(bboxArr).trim();
					if (bboxStr) {
						bboxArr = bboxStr
							.split(/\s+/)
							.map((s) => Number(s))
							.filter((n) => Number.isFinite(n));
					}
				}
				const backendBBox = Array.isArray(bboxArr) && bboxArr.length >= 4 ? bboxArr.slice(0, 4) : null;
				const chosenBBox = lineBBox || unionBBox || backendBBox;
				if (facsRaw && chosenBBox) out = { facs: facsRaw, bbox: chosenBBox };
			}

			// Line bbox + token highlights: use fragment <l>/<lb> whenever present (scope may still say "s").
			if (out && this.isXmlContext() && this.hitHasStructuredContext(hit)) {
				const container = this.buildContextContainer(hit);
				if (container && !container.querySelector('pre.flexicorp-hit-ridx-fallback, pre.flexicorp-hit-xml-fallback')) {
					// the line the match is on: indexed per token (hit.line_bbox), else the
					// line break before the match in the fragment
					const lineBBox = this._parseBBox(hit && hit.line_bbox)
						|| this._lineBBoxFromFragmentContainer(container, new Set(this.getHitMatchIds(hit)));
					if (lineBBox) {
						const word = Array.isArray(out.bbox) ? out.bbox : null;
						out.bbox = word ? this._unionBBox(lineBBox, word) : lineBBox;
						if (word && !out.highlights) out.highlights = [word];
					}
					else if (resolvedScope === 'l' || resolvedScope === 'lb') {
						const outer = container.querySelector(`${resolvedScope}[bbox]`);
						if (outer && outer.getAttribute) {
							const bboxRaw = (outer.getAttribute('bbox') || '').trim();
							const nums = bboxRaw.split(/\s+/).map(Number).filter((n) => Number.isFinite(n));
							if (nums.length >= 4) out.bbox = nums.slice(0, 4);
						}
					}
					const wantHl = lineBBox || resolvedScope === 'l' || resolvedScope === 'lb' || container.querySelector('l[bbox], lb[bbox]');
					if (wantHl) {
						const matchIds = this.getHitMatchIds(hit);
						const idSet = this._expandMatchIdSet(new Set(matchIds));
						const rects = [];
						const nodes = Array.from(container.querySelectorAll('tok[bbox], dtok[bbox], mtok[bbox]'));
						for (const node of nodes) {
							if (!node || !node.getAttribute) continue;
							const id = this.xmlElementId(node);
							if (!idSet.size || !idSet.has(id)) continue;
							const bboxRaw = (node.getAttribute('bbox') || '').trim();
							const nums = bboxRaw.split(/\s+/).map(Number).filter((n) => Number.isFinite(n));
							if (nums.length >= 4) rects.push(nums.slice(0, 4));
						}
						if (rects.length) out.highlights = rects;
					}
				}
			}
			if (cache) cache.facs = out;
			return out;
		},

		searchHasAnyEyeCandy() {
			if (!this.search || !this.search.ran) return false;
			if (!this.isSearchViewMode('table')) return false;
			const hits = this.getMergedSearchHits();
			return Array.isArray(hits) && hits.some((hit) => this.hasEyeCandy(hit));
		},

		searchHasAnyEyeCandyAlignedSide(side) {
			if (!this.search || !this.search.ran) return false;
			if (!this.isSearchViewMode('table')) return false;
			if (!this.searchHasAlignedHits()) return false;
			const sideKey = String(side || '').toLowerCase();
			const hits = this.getMergedSearchHits();
			if (!Array.isArray(hits) || !hits.length) return false;
			if (sideKey === 'source' || sideKey === 'src') {
				return hits.some((hit) => this.hasEyeCandy(hit));
			}
			if (sideKey === 'target' || sideKey === 'tgt') {
				return hits.some((hit) => {
					const companion = this.getAlignedCompanionHit(hit);
					return companion && this.hasEyeCandy(companion);
				});
			}
			return false;
		},

		hasEyeCandy(hit) {
			const audio = this.getHitAudioInfo(hit);
			const dep = this.getHitDependencyTreeInfo(hit);
			const depAlign = this.getHitParallelDepalignInfo(hit);
			const facs = this.getHitFacsimileInfo(hit);
			return !!(audio || dep || depAlign || facs);
		},

		/** Merged search hits that can show a facsimile cutout (same rule as the facs button). */
		getFacsNavigableHits() {
			return this.getMergedSearchHits().filter((h) => {
				const f = this.getHitFacsimileInfo(h);
				return !!(f && f.facs && Array.isArray(f.bbox));
			});
		},

		_findFacsNavIndexByArgs(navHits, cid, facs, bboxCsv, jumpTok) {
			if (!Array.isArray(navHits) || !navHits.length) return -1;
			const c = String(cid || '').trim();
			const facsStr = String(facs || '').trim();
			const bb = String(bboxCsv || '').trim();
			const j = String(jumpTok || '').trim();
			return navHits.findIndex((h) => {
				const fi = this.getHitFacsimileInfo(h);
				if (!fi || !fi.facs || !Array.isArray(fi.bbox)) return false;
				const hc = h.doc_id ? String(h.doc_id) : '';
				if (hc !== c) return false;
				if (String(fi.facs).trim() !== facsStr) return false;
				const bb2 = fi.bbox.slice(0, 4).join(',');
				if (bb2 !== bb) return false;
				const mids = this.getHitMatchIds(h);
				const j2 = mids.length ? String(mids[0]) : '';
				return j === j2;
			});
		},

		_removeFacsCutoutKeyListener() {
			if (this._facsCutoutKeydownBound) {
				document.removeEventListener('keydown', this._facsCutoutKeydownBound, true);
				this._facsCutoutKeydownBound = null;
			}
		},

		_setupFacsCutoutKeyboard() {
			this._removeFacsCutoutKeyListener();
			const handler = (e) => {
				const overlay = document.getElementById('flexicorp-facs-cutout-overlay');
				if (!overlay || !overlay.classList.contains('flexicorp-facs-cutout-overlay--open')) return;
				const nav = this._facsCutoutNav;
				if (e.key === 'Escape') {
					e.preventDefault();
					e.stopPropagation();
					this._closeFacsCutoutModal();
					return;
				}
				if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
				if (!nav || !Array.isArray(nav.hits) || !nav.hits.length) return;
				e.preventDefault();
				e.stopPropagation();
				if (nav.hits.length < 2) return;
				const delta = e.key === 'ArrowDown' ? 1 : -1;
				const idx = (nav.index + delta + nav.hits.length) % nav.hits.length;
				const nextHit = nav.hits[idx];
				if (!nextHit) return;
				const f = this.getHitFacsimileInfo(nextHit);
				if (!f || !f.facs || !Array.isArray(f.bbox)) return;
				const cid = nextHit.doc_id ? String(nextHit.doc_id) : '';
				const bboxCsv = f.bbox.slice(0, 4).join(',');
				const highlights = Array.isArray(f.highlights) ? f.highlights : [];
				const highlightsJson = JSON.stringify(highlights);
				const matchIds = this.getHitMatchIds(nextHit);
				const jumpTok = matchIds.length ? String(matchIds[0]) : '';
				this.openHitFacsimileCutout(cid, f.facs, bboxCsv, highlightsJson, jumpTok, String(idx));
			};
			this._facsCutoutKeydownBound = handler;
			document.addEventListener('keydown', handler, true);
		},

		getEyeCandyHtml(hit) {
			if (!this.hasEyeCandy(hit)) return '';
			const bits = [];

			const depAlign = this.getHitParallelDepalignInfo(hit);
			const alignSide = this.getHitOwnAlignedSide(hit);
			const showParallelDepTrees = !!(depAlign && depAlign.ids && depAlign.tuid && alignSide !== 'target');
			if (showParallelDepTrees) {
				const urlAlign = new URL('index.php', window.location.href);
				urlAlign.searchParams.set('action', 'depalign');
				urlAlign.searchParams.set('ids', depAlign.ids);
				urlAlign.searchParams.set('tuid', depAlign.tuid);
				if (depAlign.jump) {
					urlAlign.searchParams.set('jump', depAlign.jump);
				}
				bits.push(
					`<a class="flexicorp-eye-candy-btn" href="${this.escapeHtmlForAttr(urlAlign.toString())}" target="_blank" rel="noopener noreferrer" title="Open aligned dependency trees (sentence scope); jump highlights the match token">trees</a>`,
				);
			}

			const dep = this.getHitDependencyTreeInfo(hit);
			if (dep && dep.cid && !showParallelDepTrees) {
				const url = new URL('index.php', window.location.href);
				url.searchParams.set('action', 'deptree');
				url.searchParams.set('cid', dep.cid);
				if (dep.sid) {
					// TEITOK variants accept sid and/or s_id for sentence-scoped deptree.
					url.searchParams.set('sid', dep.sid);
					url.searchParams.set('s_id', dep.sid);
				}
				bits.push(`<a class="flexicorp-eye-candy-btn" href="${this.escapeHtmlForAttr(url.toString())}" target="_blank" rel="noopener noreferrer" title="dependency tree">tree</a>`);
			}

			const audio = this.getHitAudioInfo(hit);
			if (audio) {
				const mediaUrl = (audio.url || '').trim();
				const start = audio.start != null ? String(audio.start) : '';
				const end = audio.end != null ? String(audio.end) : '';
				const hasTimes =
					audio.start != null &&
					audio.end != null &&
					Number.isFinite(Number(audio.start)) &&
					Number.isFinite(Number(audio.end)) &&
					Number(audio.end) >= Number(audio.start);
				if (mediaUrl && hasTimes) {
					const safeUrl = this.escapeHtmlForAttr(encodeURIComponent(mediaUrl));
					const safeStart = this.escapeHtmlForAttr(start);
					const safeEnd = this.escapeHtmlForAttr(end);
					bits.push(
						`<button type="button" class="flexicorp-eye-candy-btn" title="play audio" ` +
							`onclick="(window.flexicorpAppInstance && window.flexicorpAppInstance.playHitAudio) ? window.flexicorpAppInstance.playHitAudio(decodeURIComponent('${safeUrl}'),'${safeStart}','${safeEnd}', this) : null">sound</button>`
					);
				} else if (mediaUrl && !hasTimes) {
					const safeUrl = this.escapeHtmlForAttr(encodeURIComponent(mediaUrl));
					bits.push(
						`<button type="button" class="flexicorp-eye-candy-btn" title="play audio" ` +
							`onclick="(window.flexicorpAppInstance && window.flexicorpAppInstance.playHitAudio) ? window.flexicorpAppInstance.playHitAudio(decodeURIComponent('${safeUrl}'),'','', this) : null">sound</button>`
					);
				} else if (!mediaUrl && hasTimes && hit && hit.doc_id) {
					const safeCid = this.escapeHtmlForAttr(String(hit.doc_id));
					const safeStart = this.escapeHtmlForAttr(start);
					const safeEnd = this.escapeHtmlForAttr(end);
					bits.push(
						`<button type="button" class="flexicorp-eye-candy-btn" title="play audio" ` +
							`onclick="(window.flexicorpAppInstance && window.flexicorpAppInstance.playHitAudio) ? window.flexicorpAppInstance.playHitAudio('','${safeStart}','${safeEnd}', this, '${safeCid}') : null">sound</button>`
					);
				}
			}

			const facs = this.getHitFacsimileInfo(hit);
			if (facs && facs.facs && Array.isArray(facs.bbox)) {
				const cid = hit && hit.doc_id ? String(hit.doc_id) : '';
				const safeCid = this.escapeHtmlForAttr(cid);
				const safeFacs = this.escapeHtmlForAttr(String(facs.facs));
				const bboxCsv = (Array.isArray(facs.bbox) ? facs.bbox.slice(0, 4) : []).join(',');
				const safeBbox = this.escapeHtmlForAttr(bboxCsv);
				const highlights = Array.isArray(facs.highlights) ? facs.highlights : [];
				const highlightsJson = JSON.stringify(highlights);
				const safeHighlights = this.escapeHtmlForAttr(highlightsJson);
				const matchIds = this.getHitMatchIds(hit);
				const jumpTok = Array.isArray(matchIds) && matchIds.length ? String(matchIds[0]) : '';
				const safeJump = this.escapeHtmlForAttr(jumpTok);
				const navHits = this.getFacsNavigableHits();
				const navIdx = navHits.findIndex((h) => h === hit);
				const safeNavIdx = this.escapeHtmlForAttr(String(navIdx >= 0 ? navIdx : -1));
				const facsTitle =
					navHits.length > 1
						? 'facsimile view — ↑↓ browse results, Esc close'
						: 'facsimile view — Esc close';
				const safeFacsTitle = this.escapeHtmlForAttr(facsTitle);
				bits.push(
					`<button type="button" class="flexicorp-eye-candy-btn" title="${safeFacsTitle}" ` +
					`onclick="(window.flexicorpAppInstance && window.flexicorpAppInstance.openHitFacsimileCutout) ? window.flexicorpAppInstance.openHitFacsimileCutout('${safeCid}','${safeFacs}','${safeBbox}','${safeHighlights}','${safeJump}','${safeNavIdx}') : (window.flexicorpAppInstance && window.flexicorpAppInstance.openHitFacsimile) ? window.flexicorpAppInstance.openHitFacsimile('${safeCid}','${safeJump}') : null">facs</button>`
				);
			}

			return bits.join('<br>');
		},

		_resolveMaybeRelativeMediaUrl(url) {
			if (!url || typeof url !== 'string') return '';
			const t = url.trim();
			if (!t) return '';
			if (/^https?:\/\//i.test(t)) return t;
			if (t.startsWith('//')) return window.location.protocol + t;
			try {
				return new URL(t, window.location.href).href;
			} catch (_) {
				return t;
			}
		},

		_extractRecordingUrlFromXmlOrHtmlText(txt) {
			if (!txt || typeof txt !== 'string') return '';
			const s = txt;
			const tryPatterns = () => {
				const patterns = [
					/<recording[^>]*\surl\s*=\s*["']([^"']+)["']/i,
					/<recording[^>]*\starget\s*=\s*["']([^"']+)["']/i,
					/<media[^>]*\surl\s*=\s*["']([^"']+)["']/i,
					/<audio[^>]*\ssrc\s*=\s*["']([^"']+)["']/i,
					/<video[^>]*\ssrc\s*=\s*["']([^"']+)["']/i,
				];
				for (const p of patterns) {
					const m = s.match(p);
					if (m && m[1]) return m[1].trim();
				}
				return '';
			};
			const trimmed = s.trim();
			if (trimmed.startsWith('<?xml') || /^<TEI[\s>]/i.test(trimmed) || /^<tei[\s>]/i.test(trimmed)) {
				try {
					const doc = new DOMParser().parseFromString(trimmed, 'application/xml');
					if (!doc.querySelector('parsererror')) {
						const pick = (el) => {
							if (!el) return '';
							return (
								(el.getAttribute && el.getAttribute('url')) ||
								(el.getAttribute && el.getAttribute('target')) ||
								(el.getAttribute && el.getAttribute('src')) ||
								''
							).trim();
						};
						const rec = doc.querySelector('recording') || doc.getElementsByTagNameNS('*', 'recording')[0];
						let u = pick(rec);
						if (!u) {
							const media = doc.querySelector('media') || doc.getElementsByTagNameNS('*', 'media')[0];
							u = pick(media);
						}
						if (u) return u;
					}
				} catch (_) {
					// fall through
				}
			}
			let out = tryPatterns();
			if (out) return out;
			const loose = s.match(/https?:\/\/[^\s"'<>]+\.(?:mp3|wav|ogg|m4a|webm|aac)(?:\?[^\s"'<>]*)?/i);
			return loose ? loose[0].trim() : '';
		},

		async resolveDocAudioUrlFromTeitok(cid) {
			const k = String(cid || '').trim();
			if (!k) return '';
			if (!this._docAudioUrlCache) this._docAudioUrlCache = Object.create(null);
			if (this._docAudioUrlCache[k] !== undefined) return this._docAudioUrlCache[k];
			const buildUrls = () => {
				const list = [];
				const a = new URL('index.php', window.location.href);
				a.searchParams.set('action', 'file');
				a.searchParams.set('cid', k);
				list.push(a.toString());
				const b = new URL('index.php', window.location.href);
				b.searchParams.set('action', 'file');
				b.searchParams.set('cid', k);
				b.searchParams.set('raw', '1');
				list.push(b.toString());
				const c = new URL('index.php', window.location.href);
				c.searchParams.set('action', 'file');
				c.searchParams.set('cid', k);
				c.searchParams.set('format', 'xml');
				list.push(c.toString());
				return list;
			};
			for (const u of buildUrls()) {
				try {
					const r = await fetch(u, { credentials: 'same-origin' });
					if (!r.ok) continue;
					const txt = await r.text();
					const raw = this._extractRecordingUrlFromXmlOrHtmlText(txt);
					if (raw) {
						const resolved = this._resolveMaybeRelativeMediaUrl(raw);
						this._docAudioUrlCache[k] = resolved;
						return resolved;
					}
				} catch (_) {
					// try next candidate URL
				}
			}
			this._docAudioUrlCache[k] = '';
			return '';
		},

		_clearAudioSegmentEndGuard() {
			if (typeof this._audioSegmentEndCleanup === 'function') {
				try {
					this._audioSegmentEndCleanup();
				} catch (_) {
					// ignore
				}
				this._audioSegmentEndCleanup = null;
			}
		},

		_attachAudioSegmentEndGuard(audioEl, startSec, endSec) {
			this._clearAudioSegmentEndGuard();
			if (!audioEl || !Number.isFinite(endSec) || !Number.isFinite(startSec) || endSec <= startSec) return;
			const endBound = endSec;
			const EPS = 0.05;
			const onTimeUpdate = () => {
				if (audioEl.currentTime >= endBound - EPS) {
					audioEl.pause();
					try {
						audioEl.currentTime = Math.min(endBound, Number.isFinite(audioEl.duration) ? audioEl.duration : endBound);
					} catch (_) {
						// ignore
					}
					this._clearAudioSegmentEndGuard();
				}
			};
			audioEl.addEventListener('timeupdate', onTimeUpdate);
			this._audioSegmentEndCleanup = () => {
				audioEl.removeEventListener('timeupdate', onTimeUpdate);
			};
		},

		_scheduleAudioSegmentEndGuard(start, end) {
			this._clearAudioSegmentEndGuard();
			if (!Number.isFinite(end) || !Number.isFinite(start) || end <= start) return;
			let bound = false;
			const tryBind = () => {
				if (bound) return;
				const el = document.getElementById('track');
				if (!el) return;
				bound = true;
				this._attachAudioSegmentEndGuard(el, start, end);
			};
			const el = document.getElementById('track');
			if (el) {
				el.addEventListener('playing', tryBind, { once: true });
			}
			setTimeout(tryBind, 250);
		},

		/**
		 * For a video (…/Video/<name>.mp4), an audio-only Audio/<name>.mp3 next to it
		 * is used for snippet playback when it exists: every browser decodes mp3, while
		 * whether an mp4 decodes in <audio> or <video> differs per browser, and the clip
		 * needs a few hundred KB instead of the whole video. Checked once per URL.
		 */
		async _preferAudioTrackFor(url) {
			const m = String(url).match(/^(.*\/)Video\/+([^/?#]+)\.(mp4|m4v|webm|ogv|mov)([?#].*)?$/i);
			if (!m) return url;
			if (!this._audioTrackForVideo) this._audioTrackForVideo = Object.create(null);
			if (this._audioTrackForVideo[url] !== undefined) return this._audioTrackForVideo[url] || url;
			const candidate = `${m[1]}Audio/${m[2]}.mp3`;
			let found = '';
			try {
				const r = await fetch(candidate, { method: 'HEAD', credentials: 'same-origin' });
				if (r.ok && /audio|mpeg/i.test(r.headers.get('content-type') || 'audio')) found = candidate;
			} catch (_) { /* keep the video */ }
			this._audioTrackForVideo[url] = found;
			return found || url;
		},

		/**
		 * TEITOK's playpart() plays through whatever element has id="track". For a
		 * video file that must be a <video> element: some browsers load an mp4 video
		 * in an <audio> element but then fail to decode it (MEDIA_ERR_DECODE), while
		 * the same file plays as video. Swap the hidden #track element to match.
		 */
		_ensureTrackElementFor(url) {
			const isVideo = /\.(mp4|m4v|webm|ogv|mov)(\?|#|$)/i.test(String(url || ''));
			const want = isVideo ? 'VIDEO' : 'AUDIO';
			const cur = document.getElementById('track');
			if (cur && cur.tagName === want) return;
			// keep a track element that belongs to the page itself (e.g. TEITOK's own player)
			if (cur && !cur.dataset.flexicorpTrack && cur.parentElement && cur.parentElement.style.display !== 'none') return;
			const el = document.createElement(want.toLowerCase());
			el.id = 'track';
			el.dataset.flexicorpTrack = '1';
			el.setAttribute('preload', 'auto');
			if (isVideo) {
				el.setAttribute('playsinline', '');
				// not display:none: some browsers pause invisible videos
				el.style.cssText = 'position:absolute;width:1px;height:1px;opacity:0;pointer-events:none';
			} else {
				el.controls = true;
			}
			if (cur) {
				try { cur.pause(); } catch (_) { /* ignore */ }
				cur.replaceWith(el);
				if (el.parentElement && el.parentElement !== document.body) {
					el.parentElement.style.display = isVideo ? '' : 'none';
				}
			} else {
				const wrap = document.createElement('div');
				wrap.style.display = isVideo ? '' : 'none';
				wrap.appendChild(el);
				document.body.appendChild(wrap);
			}
		},

		async playHitAudio(url, startRaw, endRaw, btn, docIdForResolve) {
			this._clearAudioSegmentEndGuard();
			const ok = await this.ensureAudioControlLoaded();
			const start = startRaw !== '' ? Number(startRaw) : null;
			const end = endRaw !== '' ? Number(endRaw) : null;
			let resolvedUrl = (typeof url === 'string' && url.trim()) ? url.trim() : '';
			if (!resolvedUrl && docIdForResolve) {
				resolvedUrl = await this.resolveDocAudioUrlFromTeitok(String(docIdForResolve).trim());
			}
			if (resolvedUrl && !/^https?:\/\//i.test(resolvedUrl)) {
				resolvedUrl = this._resolveMaybeRelativeMediaUrl(resolvedUrl) || resolvedUrl;
			}
			if (resolvedUrl) resolvedUrl = await this._preferAudioTrackFor(resolvedUrl);
			if (resolvedUrl) this._ensureTrackElementFor(resolvedUrl);
			if (ok && typeof window.playpart === 'function' && resolvedUrl) {
				window.playpart(resolvedUrl, Number.isFinite(start) ? start : 0, Number.isFinite(end) ? end : 0, btn || null);
				// TEITOK audiocontrol often seeks to start but does not stop at end; enforce segment end on #track.
				if (Number.isFinite(start) && Number.isFinite(end) && end > start) {
					this._scheduleAudioSegmentEndGuard(start, end);
				}
				return;
			}
			// Fallback: if we have a URL, try native audio playback.
			const audioEl = document.getElementById('track');
			if (!audioEl || !resolvedUrl) return;
			try {
				audioEl.src = resolvedUrl;
				if (Number.isFinite(start)) audioEl.currentTime = Math.max(0, start);
				await audioEl.play();
				if (Number.isFinite(start) && Number.isFinite(end) && end > start) {
					this._attachAudioSegmentEndGuard(audioEl, start, end);
				}
			} catch (_) {
				// Ignore: user gesture / browser restrictions.
			}
		},

		openHitFacsimile(cid, jumpTokId) {
			const safeCid = String(cid || '').trim();
			if (!safeCid) return;
			const url = new URL('index.php', window.location.href);
			// Prefer facsimile view; for line-based corpora this can still show something,
			// and TEITOK will decide whether the view is enabled.
			url.searchParams.set('action', 'facsview');
			url.searchParams.set('cid', safeCid);
			const jmp = String(jumpTokId !== undefined && jumpTokId !== null ? jumpTokId : '').trim();
			if (jmp) url.searchParams.set('jmp', jmp);
			window.open(url.toString(), '_blank', 'noopener,noreferrer');
		},

		openHitFacsimileCutout(cid, facs, bboxCsv, highlightsJson, jumpTokId, facsNavIndexStr) {
			try {
				// eslint-disable-next-line no-console
				console.log('[flexicorp] facs cutout clicked', {
					cid: cid,
					facs: facs,
					bboxCsv: bboxCsv,
					highlightsJsonType: typeof highlightsJson,
				});

				const facsRaw = String(facs || '').trim();
				const cidTrim = String(cid || '').trim();
				const jumpTok = String(jumpTokId !== undefined && jumpTokId !== null ? jumpTokId : '').trim();
				if (!facsRaw) {
					// eslint-disable-next-line no-console
					console.warn('[flexicorp] facs cutout: missing facs, falling back to facsview', { cid: cidTrim });
					this.openHitFacsimile(cidTrim, jumpTok);
					return;
				}

			const bboxNums = String(bboxCsv || '')
				.split(',')
				.map((s) => Number(s.trim()))
				.filter((n) => Number.isFinite(n));
			if (bboxNums.length < 4) {
				// eslint-disable-next-line no-console
				console.warn('[flexicorp] facs cutout invalid bboxCsv', { bboxCsv, bboxNums });
				this.openHitFacsimile(cidTrim, jumpTok);
				return;
			}
			const bbox = bboxNums.slice(0, 4);
			const [x1, y1, x2, y2] = bbox;
			const bw = x2 - x1;
			const bh = y2 - y1;
			if (!Number.isFinite(bw) || !Number.isFinite(bh) || bw <= 0 || bh <= 0) {
				// eslint-disable-next-line no-console
				console.warn('[flexicorp] facs cutout invalid bbox geometry', { bbox: bboxNums, bw, bh, cid: cidTrim });
				this.openHitFacsimile(cidTrim, jumpTok);
				return;
			}

			const overlayId = 'flexicorp-facs-cutout-overlay';
			let overlay = document.getElementById(overlayId);
			if (!overlay) {
				overlay = document.createElement('div');
				overlay.id = overlayId;
				overlay.classList.add('flexicorp-facs-cutout-overlay');
				overlay.setAttribute('role', 'dialog');
				overlay.setAttribute('aria-modal', 'true');
				overlay.innerHTML = `
					<div class="flexicorp-facs-cutout-modal">
						<div class="flexicorp-facs-cutout-head">
							<div class="flexicorp-facs-cutout-head-text">
								<span class="flexicorp-facs-cutout-title">Facsimile cutout</span>
								<span class="flexicorp-facs-cutout-hint">↑↓ browse · Esc close</span>
							</div>
							<button type="button" class="flexicorp-facs-cutout-close" aria-label="Close">×</button>
						</div>
						<div class="flexicorp-facs-cutout-body">
							<div class="flexicorp-facs-cutout-stage" aria-label="Facsimile crop"></div>
							<div class="flexicorp-facs-cutout-meta">
								<a class="flexicorp-facs-cutout-fullview" href="#" target="_blank" rel="noopener noreferrer">Open full facsimile view</a>
							</div>
						</div>
					</div>
				`;
				document.body.appendChild(overlay);

				overlay.addEventListener('click', (e) => {
					if (e && e.target === overlay) this._closeFacsCutoutModal();
				});
				const closeBtn = overlay.querySelector('.flexicorp-facs-cutout-close');
				if (closeBtn) closeBtn.addEventListener('click', () => this._closeFacsCutoutModal());
			}

			// Set full view link (still TEITOK-managed).
			const fullLink = overlay.querySelector('.flexicorp-facs-cutout-fullview');
			if (fullLink) {
				fullLink.href = (() => {
					if (!cidTrim) return '#';
					const url = new URL('index.php', window.location.href);
					url.searchParams.set('action', 'facsview');
					url.searchParams.set('cid', cidTrim);
					if (jumpTok) url.searchParams.set('jmp', jumpTok);
					return url.toString();
				})();
			}

			const navHits = this.getFacsNavigableHits();
			let navIdx = Number.parseInt(String(facsNavIndexStr !== undefined && facsNavIndexStr !== null ? facsNavIndexStr : ''), 10);
			if (!Number.isFinite(navIdx) || navIdx < 0 || navIdx >= navHits.length) {
				navIdx = this._findFacsNavIndexByArgs(navHits, cidTrim, facsRaw, String(bboxCsv || ''), jumpTok);
			}
			if (navHits.length) {
				if (navIdx < 0) navIdx = 0;
				else if (navIdx >= navHits.length) navIdx = navHits.length - 1;
			} else {
				navIdx = 0;
			}
			this._facsCutoutNav = { hits: navHits, index: navIdx };

			const titleEl = overlay.querySelector('.flexicorp-facs-cutout-title');
			const hintEl = overlay.querySelector('.flexicorp-facs-cutout-hint');
			if (titleEl) {
				titleEl.textContent =
					navHits.length > 1 ? `Facsimile cutout (${navIdx + 1} / ${navHits.length})` : 'Facsimile cutout';
			}
			if (hintEl) {
				if (navHits.length > 1) {
					hintEl.style.display = '';
					hintEl.textContent = '↑↓ previous / next · Esc close';
				} else {
					hintEl.style.display = '';
					hintEl.textContent = 'Esc close';
				}
			}

			// Show overlay immediately, but fill crop after image load.
			// Base class must be present: it supplies position:fixed + full-viewport backdrop.
			overlay.classList.add('flexicorp-facs-cutout-overlay');
			overlay.classList.add('flexicorp-facs-cutout-overlay--open');
				// Make visibility robust even if CSS didn't load for some reason.
				overlay.style.display = 'flex';
				overlay.style.position = 'fixed';
				overlay.style.inset = '0';
				overlay.style.alignItems = 'center';
				overlay.style.justifyContent = 'center';
				overlay.style.background = 'rgba(0, 0, 0, 0.65)';
				overlay.style.zIndex = '10000';

			this._setupFacsCutoutKeyboard();
			const stage = overlay.querySelector('.flexicorp-facs-cutout-stage');
				if (stage) stage.textContent = 'Loading image...';
				// eslint-disable-next-line no-console
				console.log('[flexicorp] facs cutout overlay opened', {
					overlayFound: !!overlay,
					stageFound: !!stage,
				});

			// TEITOK's ttxml.php treats pb/@facs like:
			// - if it starts with http or '/', use as-is
			// - otherwise assume it's a filename under `Facsimile/`
			// We mirror that so cutouts also work with externally hosted facsimiles.
			let imgUrl;
			const facsPath = String(facsRaw || '').trim();
			if (/^(https?:\/\/|\/)/i.test(facsPath)) {
				imgUrl = facsPath;
			} else if (/^Facsimile\//i.test(facsPath)) {
				imgUrl = new URL(facsPath, window.location.href).toString();
			} else {
				imgUrl = new URL(`Facsimile/${facsPath}`, window.location.href).toString();
			}
			// eslint-disable-next-line no-console
			console.log('[flexicorp] facs cutout image URL', imgUrl);

			const img = new Image();
			img.onload = () => {
				// eslint-disable-next-line no-console
				console.log('[flexicorp] facs cutout image loaded');
				// Choose a scale that fits bbox into the modal while keeping bbox framing correct.
				const maxW = 720;
				const maxH = 560;
				const imgScaleW = maxW / bw;
				const imgScaleH = maxH / bh;
				const imgscale = Math.max(0.05, Math.min(imgScaleW, imgScaleH));

				const cutW = bw * imgscale;
				const cutH = bh * imgscale;
				const bgW = img.naturalWidth * imgscale;
				const bgH = img.naturalHeight * imgscale;

				const cutEl = overlay.querySelector('.flexicorp-facs-cutout-stage');
				if (!cutEl) return;
				cutEl.innerHTML = '';
				cutEl.style.position = 'relative';
				cutEl.style.overflow = 'hidden';
				cutEl.style.backgroundImage = `url(${imgUrl})`;
				cutEl.style.backgroundRepeat = 'no-repeat';
				cutEl.style.backgroundSize = `${bgW}px ${bgH}px`;
				cutEl.style.backgroundPosition = `-${x1 * imgscale}px -${y1 * imgscale}px`;
				cutEl.style.width = `${cutW}px`;
				cutEl.style.height = `${cutH}px`;

				// Center the crop stage.
				cutEl.style.margin = '0 auto';
				cutEl.style.display = 'block';

				// Optional: highlight matched tokens inside the line crop.
				let rects = [];
				if (typeof highlightsJson === 'string' && highlightsJson.trim()) {
					try {
						const parsed = JSON.parse(highlightsJson);
						if (Array.isArray(parsed)) rects = parsed;
					} catch (_) {
						rects = [];
					}
				}
				if (rects.length) {
					for (const rect of rects) {
						if (!Array.isArray(rect) || rect.length < 4) continue;
						const rx1 = Number(rect[0]);
						const ry1 = Number(rect[1]);
						const rx2 = Number(rect[2]);
						const ry2 = Number(rect[3]);
						if (![rx1, ry1, rx2, ry2].every((n) => Number.isFinite(n))) continue;
						const rhw = rx2 - rx1;
						const rhh = ry2 - ry1;
						if (rhw <= 0 || rhh <= 0) continue;

						const left = (rx1 - x1) * imgscale;
						const top = (ry1 - y1) * imgscale;
						const w = rhw * imgscale;
						const h = rhh * imgscale;

						const hl = document.createElement('div');
						hl.className = 'flexicorp-facs-cutout-highlight';
						hl.style.left = `${left}px`;
						hl.style.top = `${top}px`;
						hl.style.width = `${w}px`;
						hl.style.height = `${h}px`;
						cutEl.appendChild(hl);
					}
				}
			};
			img.onerror = () => {
				// On failure, still show the regular facsview so user is not blocked.
				// eslint-disable-next-line no-console
				console.error('[flexicorp] facs cutout image failed', { imgUrl, cid: cidTrim });
				this._closeFacsCutoutModal();
				this.openHitFacsimile(cidTrim, jumpTok);
			};
			img.src = imgUrl;
			} catch (e) {
				// eslint-disable-next-line no-console
				console.error('[flexicorp] facs cutout handler threw', e);
				// Don't rethrow: keep UI responsive.
			}
		},

		_closeFacsCutoutModal() {
			this._removeFacsCutoutKeyListener();
			this._facsCutoutNav = null;
			const overlay = document.getElementById('flexicorp-facs-cutout-overlay');
			if (!overlay) return;
			overlay.classList.remove('flexicorp-facs-cutout-overlay--open');
			overlay.style.display = 'none';
		},
	};
};
