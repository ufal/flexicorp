/**
 * flexicorp TEITOK UI: context and dependency tree for a project without TEITOK XML files
 * (a corpus that lives only in its index, e.g. Pando built straight from CoNLL-U). There is
 * no document to open (action=file, showdocinfo, action=deptree all need one), so the
 * Context link opens a popup with the wider context from the engine (FQS /context through
 * action=fqs_query, as KonText's widectx; when FQS does not serve the corpus, the project's own
 * index through action=flexicorp&act=context, fc_context.php), and the tree link draws the hit's sentence from
 * its own fragment. Methods of the flexicorpRoot Alpine component, mixed in by
 * flexicorpApp() (flexicorp.js) through window.ttFlexicorpCoreParts; `this` is the component.
 */
window.ttFlexicorpCoreParts = window.ttFlexicorpCoreParts || {};
window.ttFlexicorpCoreParts.corpuscontext = function () {
	/** Tokens added on each side per "more" click (fqs_query.php allows up to 500). */
	const STEP = 40;
	const MAX_SIDE = 500;

	const el = (tag, cls, text) => {
		const n = document.createElement(tag);
		if (cls) n.className = cls;
		if (text != null) n.textContent = String(text);
		return n;
	};

	return {
		/** False when the project has no XML files (from PHP: tt_flexicorp_project_has_xml). */
		projectHasXml: true,

		/** The hit has a corpus position to ask the engine for its context. */
		canShowCorpusContext(hit) {
			return !this.projectHasXml && !!hit && Number.isFinite(Number(hit.match_start));
		},

		_corpusContextHitAt(pos) {
			const hits = (this.search && Array.isArray(this.search.hits)) ? this.search.hits : [];
			return hits.find((h) => h && Number(h.match_start) === Number(pos)) || null;
		},

		/** The popup frame (created once; same look as the facsimile cutout). */
		_corpusContextOverlay() {
			let ov = document.getElementById('flexicorp-corpus-context-overlay');
			if (ov) return ov;
			ov = el('div', 'flexicorp-facs-cutout-overlay flexicorp-corpus-context-overlay');
			ov.id = 'flexicorp-corpus-context-overlay';
			// resizable (drag the lower right corner); the size stays for the next popup
			const modal = el('div', 'flexicorp-facs-cutout-modal flexicorp-corpus-context-modal');
			modal.setAttribute('role', 'dialog');
			modal.setAttribute('aria-modal', 'true');
			const head = el('div', 'flexicorp-facs-cutout-head');
			const title = el('div', 'flexicorp-facs-cutout-title');
			const close = el('button', 'flexicorp-facs-cutout-close', '×');
			close.type = 'button';
			close.title = 'Close';
			head.append(title, close);
			modal.append(head, el('div', 'flexicorp-facs-cutout-body'));
			ov.append(modal);
			const hide = () => {
				ov.classList.remove('flexicorp-facs-cutout-overlay--open');
				ov.style.display = 'none';
			};
			close.addEventListener('click', hide);
			// close on the backdrop only when the click also began there (not at the end of a resize)
			let downOnBackdrop = false;
			ov.addEventListener('mousedown', (e) => { downOnBackdrop = e.target === ov; });
			ov.addEventListener('click', (e) => { if (e.target === ov && downOnBackdrop) hide(); });
			document.addEventListener('keydown', (e) => {
				if (e.key === 'Escape' && ov.classList.contains('flexicorp-facs-cutout-overlay--open')) hide();
			});
			document.body.append(ov);
			return ov;
		},

		_corpusContextShow(titleText, body) {
			const ov = this._corpusContextOverlay();
			ov.querySelector('.flexicorp-facs-cutout-title').textContent = titleText;
			const slot = ov.querySelector('.flexicorp-facs-cutout-body');
			slot.replaceChildren(body);
			ov.style.display = '';
			ov.classList.add('flexicorp-facs-cutout-overlay--open');
		},

		/** Context popup for a hit: the engine's window around the match, widened on request. */
		openCorpusContext(hit) {
			if (!this.canShowCorpusContext(hit)) return;
			const start = Number(hit.match_start);
			const end = Number.isFinite(Number(hit.match_end)) ? Math.max(start, Number(hit.match_end)) : start;
			const state = { left: STEP, right: STEP };
			const box = el('div', 'flexicorp-corpus-context');
			const text = el('p', 'flexicorp-corpus-context-text');
			const bar = el('p', 'flexicorp-corpus-context-bar');
			const moreLeft = el('button', 'flexicorp-eye-candy-btn', '◀ more');
			const moreRight = el('button', 'flexicorp-eye-candy-btn', 'more ▶');
			moreLeft.type = moreRight.type = 'button';
			bar.append(moreLeft, ' ', moreRight);
			box.append(text, bar);
			const doc = this.getHitDocLabel(hit);
			this._corpusContextShow(doc ? `Context · ${doc}` : 'Context', box);

			const render = (left, match, right) => {
				text.replaceChildren(
					left ? `… ${left} ` : '',
					el('b', 'flexicorp-corpus-context-match', match),
					right ? ` ${right} …` : '',
				);
				moreLeft.disabled = state.left >= MAX_SIDE;
				moreRight.disabled = state.right >= MAX_SIDE;
			};
			// the hit's own context, until (or when not) the engine answers
			const own = hit.context && typeof hit.context === 'object' ? hit.context : (hit.__flexicorpKwicParts || {});
			render(own.left || '', own.match || this.manateeHitPlainLine(hit), own.right || '');

			const load = () => {
				// /context marks one position: ask from the first match token and take the rest
				// of a longer match from the start of the right side
				const extra = end - start;
				const ask = (local) => {
					const url = new URL('index.php', window.location.href);
					if (local) {
						url.searchParams.set('action', 'flexicorp');
						url.searchParams.set('act', 'context');
					} else {
						url.searchParams.set('action', 'fqs_query');
						url.searchParams.set('op', 'context');
					}
					url.searchParams.set('pos', String(start));
					url.searchParams.set('left', String(state.left));
					url.searchParams.set('right', String(Math.min(MAX_SIDE, state.right + extra)));
					return fetch(url.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
						.then((r) => r.json())
						.then((j) => {
							const res = j && j.ok && j.result ? j.result : null;
							if (!res) throw new Error((j && j.error) || 'no context');
							return res;
						});
				};
				// FQS first; when it does not serve the corpus, the local index for the rest of the page
				(this._corpusContextLocal
					? ask(true)
					: ask(false).catch(() => ask(true).then((res) => { this._corpusContextLocal = true; return res; })))
					.then((res) => {
						const right = String(res.right || '').split(' ').filter(Boolean);
						const match = [String(res.match || '')].concat(right.slice(0, extra)).join(' ');
						render(String(res.left || ''), match, right.slice(extra).join(' '));
					})
					.catch((err) => {
						bar.hidden = true;
						if (this.isAdmin) console.warn('[flexicorp] corpus context:', err && err.message ? err.message : err);
					});
			};
			moreLeft.addEventListener('click', () => { state.left = Math.min(MAX_SIDE, state.left + STEP); load(); });
			moreRight.addEventListener('click', () => { state.right = Math.min(MAX_SIDE, state.right + STEP); load(); });
			load();
		},

		/** The tree button in the hit's tools, for a hit (by its corpus position). */
		corpusTreeButtonHtml(hit) {
			if (!this.canShowCorpusContext(hit) || !this._corpusTreeTokens(hit)) return '';
			const pos = String(Number(hit.match_start));
			return `<button type="button" class="flexicorp-eye-candy-btn" title="dependency tree" `
				+ `onclick="window.flexicorpAppInstance && window.flexicorpAppInstance.openCorpusTree(${pos})">tree</button>`;
		},

		/** The sentence of a hit as tokens with head and deprel, from its fragment; null without. */
		_corpusTreeTokens(hit) {
			const xml = String((hit && (hit.fragment || hit.context_xml)) || '').trim();
			if (!xml || !/\shead="/.test(xml)) return null;
			const dom = new DOMParser().parseFromString(`<r>${xml}</r>`, 'application/xml');
			if (dom.getElementsByTagName('parsererror').length) return null;
			const toks = Array.from(dom.getElementsByTagName('tok')).map((t) => ({
				id: t.getAttribute('id') || '',
				form: t.textContent || '',
				head: t.getAttribute('head') || '',
				deprel: t.getAttribute('deprel') || '',
			}));
			return toks.length ? toks : null;
		},

		/** Dependency arcs over the sentence of a hit (as UD's own pages draw them). */
		openCorpusTree(pos) {
			const hit = this._corpusContextHitAt(pos);
			const toks = hit ? this._corpusTreeTokens(hit) : null;
			if (!toks) return;
			const NS = 'http://www.w3.org/2000/svg';
			const svgEl = (tag, attrs, text) => {
				const n = document.createElementNS(NS, tag);
				Object.keys(attrs || {}).forEach((k) => n.setAttribute(k, attrs[k]));
				if (text != null) n.textContent = String(text);
				return n;
			};
			const matchIds = new Set(this.getHitMatchIds(hit).map(String));
			const index = new Map(toks.map((t, i) => [t.id, i]));
			// word positions: measure each form once in a hidden SVG
			const svg = svgEl('svg', { class: 'flexicorp-corpus-tree' });
			const probe = svgEl('text', { 'font-size': '14' });
			svg.append(probe);
			const holder = el('div');
			holder.style.cssText = 'position:absolute;visibility:hidden';
			holder.append(svg);
			document.body.append(holder);
			const gap = 18;
			let x = 10;
			const xs = toks.map((t) => {
				probe.textContent = t.form;
				const w = Math.max(probe.getComputedTextLength(), 20);
				const mid = x + w / 2;
				x += w + gap;
				return mid;
			});
			probe.remove();
			holder.remove();
			const arcs = toks
				.map((t, i) => ({ dep: i, head: index.has(t.head) ? index.get(t.head) : -1, rel: t.deprel }))
				.filter((a) => a.head >= 0 && a.head !== a.dep);
			const span = (a) => Math.abs(a.head - a.dep);
			const maxSpan = arcs.reduce((m, a) => Math.max(m, span(a)), 1);
			const unit = 16;
			const base = 30 + maxSpan * unit;
			svg.setAttribute('width', String(Math.ceil(x)));
			svg.setAttribute('height', String(base + 30));
			svg.setAttribute('viewBox', `0 0 ${Math.ceil(x)} ${base + 30}`);
			arcs.sort((a, b) => span(b) - span(a)).forEach((a) => {
				const x1 = xs[a.head];
				const x2 = xs[a.dep];
				const top = base - 8 - span(a) * unit;
				svg.append(svgEl('path', {
					d: `M${x1},${base - 6} C${x1},${top} ${x2},${top} ${x2},${base - 6}`,
					fill: 'none', stroke: '#57606a', 'stroke-width': '1.2', 'marker-end': 'url(#fc-tree-arrow)',
				}));
				svg.append(svgEl('text', { x: String((x1 + x2) / 2), y: String(top + span(a) * unit * 0.25 - 2), 'text-anchor': 'middle', 'font-size': '11', fill: '#0969da' }, a.rel));
			});
			toks.forEach((t, i) => {
				if (t.head === '0' || (t.head && !index.has(t.head) && t.deprel === 'root')) {
					svg.append(svgEl('text', { x: String(xs[i]), y: '14', 'text-anchor': 'middle', 'font-size': '11', fill: '#0969da' }, 'root'));
					svg.append(svgEl('line', { x1: String(xs[i]), y1: '18', x2: String(xs[i]), y2: String(base - 6), stroke: '#57606a', 'stroke-width': '1.2', 'marker-end': 'url(#fc-tree-arrow)' }));
				}
				svg.append(svgEl('text', {
					x: String(xs[i]), y: String(base + 12), 'text-anchor': 'middle', 'font-size': '14',
					'font-weight': matchIds.has(t.id) ? '700' : '400', fill: matchIds.has(t.id) ? '#cf222e' : '#1f2328',
				}, t.form));
			});
			const defs = svgEl('defs');
			const marker = svgEl('marker', { id: 'fc-tree-arrow', viewBox: '0 0 8 8', refX: '7', refY: '4', markerWidth: '7', markerHeight: '7', orient: 'auto' });
			marker.append(svgEl('path', { d: 'M0,0 L8,4 L0,8 z', fill: '#57606a' }));
			defs.append(marker);
			svg.prepend(defs);
			const box = el('div', 'flexicorp-corpus-tree-box');
			box.append(svg);
			const doc = this.getHitDocLabel(hit);
			this._corpusContextShow(doc ? `Dependency tree · ${doc}` : 'Dependency tree', box);
		},
	};
};
