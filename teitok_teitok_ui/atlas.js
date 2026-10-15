/**
 * TEITOK action `atlas` (atlas.php): linguistic maps, an Alpine component on a Leaflet map.
 * A variable is a query, the attribute that varies and its variants (name + a pattern for the whole
 * value), as in pando_geo's atlas files; the server counts the hits per place and value
 * (atlas_engine.php), the page groups them into variants and shows them in one of the views of
 * atlas_views.js (points, pies, Voronoi areas, merged dialect areas, regions; geometry in atlas_geo.js),
 * and writes GeoJSON.
 */
(function () {
	const PALETTE = ['#4e79a7', '#f28e2b', '#e15759', '#76b7b2', '#59a14f', '#edc948', '#b07aa1', '#ff9da7', '#9c755f'];
	const OTHER = '#bab0ac';
	const G = window.ttAtlasGeo;   // atlas_geo.js: cells and outlines

	// ── variants ──
	const escapeLiteral = (s) => String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
	function patternOf(pattern) {
		try { return new RegExp(`^(?:${pattern})$`, 'u'); } catch (_) { return null; }
	}
	/** The variant a value belongs to (the first whose pattern matches the whole value), or ''. */
	function variantOf(variants, value) {
		for (const [name, pattern] of variants) {
			const re = pattern ? patternOf(pattern) : null;
			if (re && re.test(value)) return name;
		}
		return '';
	}
	/** A pattern with one more exact value. */
	function addLiteral(pattern, value) {
		const lit = escapeLiteral(value);
		if (!pattern) return lit;
		return pattern.split('|').includes(lit) ? pattern : `${pattern}|${lit}`;
	}
	/** A pattern without an exact value it lists (patterns that are not lists stay as they are). */
	function removeLiteral(pattern, value) {
		const lit = escapeLiteral(value);
		return String(pattern || '').split('|').filter((p) => p !== lit).join('|');
	}
	/** Per place: { place, lat, lon, counts: {variant|other: n}, n, dominant, share } for places with ≥ minN hits. */
	function aggregate(counts, places, variants, minN) {
		const out = [];
		for (const [place, values] of Object.entries(counts || {})) {
			const loc = places[place];
			if (!loc) continue;
			const c = {};
			for (const [value, n] of Object.entries(values)) {
				const v = variantOf(variants, value) || 'other';
				c[v] = (c[v] || 0) + Number(n);
			}
			const s = summarize(c);
			if (s.n >= minN) out.push({ place, lat: loc[0], lon: loc[1], ...s });
		}
		return out.sort((a, b) => (a.place < b.place ? -1 : 1));
	}
	/** Counts per variant summed up: { counts, n, dominant (the most frequent; 'other' loses ties), share }. */
	function summarize(c) {
		const n = Object.values(c).reduce((s, x) => s + x, 0);
		const dominant = Object.keys(c).sort((a, b) => c[b] - c[a] || (a === 'other') - (b === 'other'))[0] || '';
		return { counts: c, n, dominant, share: n ? c[dominant] / n : 0 };
	}
	/** A place's (or region's) GeoJSON properties: its counts and shares per variant. */
	function properties(r, variantNames) {
		const p = { place: r.place, n: r.n, dominant: r.dominant, dominant_share: Math.round(r.share * 1000) / 1000 };
		for (const v of variantNames.concat(['other'])) {
			p[`count_${v}`] = r.counts[v] || 0;
			p[`share_${v}`] = Math.round(((r.counts[v] || 0) / r.n) * 1000) / 1000;
		}
		return p;
	}

	window.ttAtlasApp = function (boot) {
		return {
			boot: boot || {},
			settings: {},
			places: {},
			corpusMaps: [],
			myMaps: [],
			mapKey: '',
			v: { name: '', query: '', field: 'form', description: '', variants: [] },
			values: [],
			valuesTotal: 0,
			counts: null,
			mapped: [],
			minN: 10,
			view: 'cells',       // atlas_views.js: points, pies, cells, areas, regions:<key>
			colourBy: '',        // '': the most frequent variant; a variant's name: its share
			showValues: false,
			valuesFor: '',       // the query and attribute the values were counted for
			shareLink: '',
			shareNote: '',
			regionSets: {},      // regions:<key> → [{ name, polygons }]
			note: '',
			outline: null,      // the corpus's outline (settings: atlas/@clip), a GeoJSON MultiPolygon's coordinates
			clip: true,         // cut the cells at the outline
			busy: false,
			error: '',
			_map: null,
			_layer: null,
			_legend: null,
			_outline: null,

			async init() {
				try {
					const s = await this.api('state');
					this.settings = s.settings;
					this.places = s.places || {};
					this.corpusMaps = s.corpus_maps || [];
					this.myMaps = s.my_maps || [];
					this.minN = Number(s.settings.min_n) || 10;
				} catch (e) { this.error = e.message; }
				this.$nextTick(() => this.initMap());
				if (this.settings.clip) this.loadOutline();
				const shared = this.boot.s ? window.ttAtlasShare.decodeState(this.boot.s) : null;
				if (this.boot.s && !shared) this.error = 'This link is damaged: ask for it again, or make the map here.';
				const k = this.boot.map;
				if (shared) await this.applyState(shared);
				else if (k) {
					const scope = this.corpusMaps.some((m) => m.key === k) ? 'corpus' : 'mine';
					this.mapKey = `${scope}:${k}`;
					await this.openMap(this.mapKey);
				}
			},

			/** The outline to cut the cells at; without it the cells are drawn as they are. */
			async loadOutline() {
				try {
					const j = await this.api('outline');
					this.outline = Array.isArray(j.outline) && j.outline.length ? j.outline : null;
					if (this.outline && this.counts) this.draw();
				} catch (e) {
					if (this.boot.admin) this.error = `Admin note: the outline (${this.settings.clip}) could not be loaded: ${e.message}`;
				}
			},
			canClip() { return !!(this.outline && window.polygonClipping); },
			/** Each mapped place's cell, cut at the outline when that is on. */
			shapes() {
				const cut = this.clip && this.canClip();
				const cells = G.voronoiCells(this.mapped.map((r) => [r.lat, r.lon]), 15, cut ? G.outlineExtent(this.outline) : null);
				return G.cellShapes(cells, cut ? this.outline : null, window.polygonClipping);
			},

			async api(op, body, write) {
				const headers = { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
				if (write) headers['X-Atlas-Token'] = this.boot.token || '';
				const r = await fetch(`index.php?action=atlas&op=${encodeURIComponent(op)}`, { method: 'POST', headers, body: JSON.stringify(body || {}) });
				let j = null;
				try { j = await r.json(); } catch (_) { j = { ok: false, error: `the server answered ${r.status}` }; }
				if (!j.ok) throw new Error(j.error || 'request failed');
				return j;
			},

			initMap() {
				const el = document.getElementById('tt-at-map');
				if (!el || !window.L || this._map) return;
				this._map = L.map(el);
				L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
					maxZoom: 18, attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
				}).addTo(this._map);
				const pts = Object.values(this.places);
				const start = String(this.settings.startpos || '').split(/[\s,]+/).map(Number);
				if (start.length === 2 && start.every(Number.isFinite)) this._map.setView(start, Number(this.settings.zoom) || 8);
				else if (pts.length) this._map.fitBounds(pts.map((p) => [p[0], p[1]]), { padding: [20, 20] });
				else this._map.setView([48, 10], 4);
				this.drawPlaces();
			},
			/** Before a map: the places, as small grey points. */
			drawPlaces() {
				if (!this._map) return;
				if (this._layer) this._layer.remove();
				this._layer = L.layerGroup(Object.entries(this.places).map(([name, p]) =>
					L.circleMarker([p[0], p[1]], { radius: 3, color: '#555', weight: 1, fillColor: '#999', fillOpacity: 0.8 })
						.bindTooltip(`${name} (${Number(p[2]).toLocaleString()} tokens)`))).addTo(this._map);
			},

			colour(name) {
				const i = this.v.variants.findIndex((vv) => vv[0] === name);
				return i < 0 ? OTHER : PALETTE[i % PALETTE.length];
			},
			variantOf(value) { return variantOf(this.v.variants, value); },
			assign(value, target) {
				if (!target) return;
				if (target === '__new') {
					const name = String(value).slice(0, 40);
					this.v.variants = this.v.variants.map(([n, p]) => [n, removeLiteral(p, value)]);
					if (!this.v.variants.some((vv) => vv[0] === name)) this.v.variants.push([name, escapeLiteral(value)]);
					return;
				}
				this.v.variants = this.v.variants.map(([n, p]) => [n, n === target ? addLiteral(p, value) : removeLiteral(p, value)]);
			},

			async openMap(key) {
				this.error = '';
				this.values = [];
				this.mapped = [];
				if (!key) {
					this.v = { name: '', query: '', field: 'form', description: '', variants: [] };
					this.drawPlaces();
					return;
				}
				const [scope, k] = key.split(':');
				const m = (scope === 'corpus' ? this.corpusMaps : this.myMaps).find((x) => x.key === k);
				if (!m) return;
				this.v = { name: m.name, query: m.query, field: m.field, description: m.description, variants: m.variants.map((x) => [...x]) };
				await this.makeMap();
			},

			async loadValues() {
				this.busy = true;
				this.error = '';
				try {
					const j = await this.api('values', { query: this.v.query, field: this.v.field || 'form' });
					this.values = j.values || [];
					this.valuesTotal = j.total || 0;
					this.showValues = true;
					this.valuesFor = this.valuesKey();
					if (!this.v.variants.length) {   // a start: the most frequent values as variants
						this.v.variants = this.values.slice(0, 4).map(([val]) => [String(val).slice(0, 40), escapeLiteral(val)]);
					}
				} catch (e) { this.error = e.message; } finally { this.busy = false; }
			},
			valuesKey() { return `${this.v.query}\u0001${this.v.field || 'form'}`; },
			/** Are the values counted for the query and attribute as they are now? */
			valuesFresh() { return this.valuesFor !== '' && this.valuesFor === this.valuesKey(); },
			valuesLabel() {
				if (!this.valuesFresh()) return 'Calculate the values';
				return this.showValues ? 'Hide the values' : 'Show the values';
			},
			async valuesAction() {
				if (!this.valuesFresh()) return this.loadValues();
				this.showValues = !this.showValues;
			},
			/** A map from a share link (atlas_share.js): fill in the form and draw it. */
			async applyState(st) {
				this.v = { name: st.name, query: st.query, field: st.field, description: '', variants: st.variants.map((x) => [...x]) };
				this.view = this.viewOptions().some((o) => o.value === st.view) ? st.view : 'cells';
				this.colourBy = st.colourBy;
				this.minN = st.minN;
				this.clip = st.clip;
				await this.makeMap();
				if (st.at && this._map) this._map.setView([st.at.lat, st.at.lon], st.at.zoom);
			},
			/** A link to this map as it is now (also where it is zoomed to): in the address bar, and copied when the browser allows. */
			async share() {
				const params = new URLSearchParams(window.location.search);
				params.delete('map');
				params.set('action', 'atlas');
				const c = this._map ? this._map.getCenter() : null;
				const at = c ? { lat: c.lat, lon: c.lng, zoom: this._map.getZoom() } : null;
				params.set('s', window.ttAtlasShare.encodeState({ ...this.v, view: this.view, colourBy: this.colourBy, minN: this.minN, clip: this.clip, at }));
				this.shareLink = `${window.location.origin}${window.location.pathname}?${params}`;
				try { window.history.replaceState(null, '', this.shareLink); } catch (_) { /* the link below still works */ }
				try {
					await navigator.clipboard.writeText(this.shareLink);
					this.shareNote = 'Link copied: opening it shows this map.';
				} catch (_) {
					this.shareNote = 'Copy this link: opening it shows this map.';
				}
			},
			valuesNote() {
				return this.values.length ? `(${this.values.length} most frequent; ${this.valuesTotal.toLocaleString()} hits)` : '';
			},

			async makeMap() {
				this.busy = true;
				this.error = '';
				try {
					const j = await this.api('counts', { query: this.v.query, field: this.v.field || 'form' });
					this.counts = j.counts || {};
					await this.changeView();
				} catch (e) { this.error = e.message; } finally { this.busy = false; }
			},
			esc: escapeHtml,
			/** Fill colour and opacity: by the most frequent variant (stronger as it dominates more) or by one variant's share. */
			fill(r, solid) {
				if (this.colourBy && this.v.variants.some((vv) => vv[0] === this.colourBy)) {
					return { fillColor: this.colour(this.colourBy), fillOpacity: 0.05 + 0.8 * ((r.counts[this.colourBy] || 0) / r.n) };
				}
				return { fillColor: this.colour(r.dominant), fillOpacity: solid || 0.15 + 0.55 * Math.max(0, (r.share - 0.3) / 0.7) };
			},
			popup(r) {
				return `<b>${escapeHtml(r.place)}</b> (${r.n.toLocaleString()} hits)<table>` + this.v.variants.map((vv) => vv[0]).concat(['other']).map((v) =>
					`<tr><td><span class="tt-at-sw" style="background:${this.colour(v)}"></span>${escapeHtml(v)}</td><td class="n">${r.counts[v] || 0}</td>`
					+ `<td class="n">${Math.round(100 * (r.counts[v] || 0) / r.n)}%</td></tr>`).join('') + '</table>';
			},
			viewOptions() { return window.ttAtlasViews ? window.ttAtlasViews.options(this.settings) : []; },
			current() { return window.ttAtlasViews.view(this.view); },
			viewColours() { return !!this.current().view.colours; },
			viewCuts() { return !!this.current().view.cuts; },
			async changeView() {
				const { arg } = this.current();
				if (this.view.startsWith('regions:') && !this.regionSets[arg]) {
					this.busy = true;
					try {
						this.regionSets = { ...this.regionSets, [arg]: (await this.api('regions', { key: arg })).regions || [] };
					} catch (e) { this.error = e.message; } finally { this.busy = false; }
				}
				this.draw();
			},
			draw() {
				if (!this.counts || !this._map) return;
				this.mapped = aggregate(this.counts, this.places, this.v.variants, Math.max(1, Number(this.minN) || 1));
				for (const k of ['_layer', '_legend', '_outline']) if (this[k]) { this[k].remove(); this[k] = null; }
				const { view, arg } = this.current();
				const out = view.layers(this, arg);
				this.note = out.note;
				this._layer = L.featureGroup(out.layers).addTo(this._map);
				if (view.cuts && this.clip && this.canClip()) {
					this._outline = L.polygon(this.outline.map((poly) => poly.map((ring) => ring.map(([lon, lat]) => [lat, lon]))),
						{ color: '#555', weight: 1, fill: false, interactive: false }).addTo(this._map);
				}
				if (out.layers.length) this._map.fitBounds(this._layer.getBounds(), { padding: [10, 10] });
				this._legend = L.control({ position: 'bottomleft' });
				this._legend.onAdd = () => {
					const d = L.DomUtil.create('div', 'tt-at-legend');
					d.innerHTML = this.legendHtml(view);
					return d;
				};
				this._legend.addTo(this._map);
			},
			legendHtml(view) {
				const title = `<b>${escapeHtml(this.v.name || this.v.query)}</b><br>`;
				const by = view.colours && this.colourBy && this.v.variants.some((vv) => vv[0] === this.colourBy) ? this.colourBy : '';
				if (by) {
					return title + `share of ${escapeHtml(by)}<br>` + [0, 0.25, 0.5, 0.75, 1].map((x) =>
						`<span class="tt-at-sw" style="background:${this.colour(by)};opacity:${0.05 + 0.8 * x}"></span>${Math.round(100 * x)}%`).join('<br>');
				}
				return title + this.v.variants.map((vv) => vv[0]).concat(['other']).map((v) =>
					`<span class="tt-at-sw" style="background:${this.colour(v)}"></span>${escapeHtml(v)}`).join('<br>');
			},
			mapNote() { return this.note; },
			placesNote() {
				const n = Object.keys(this.places).length;
				return n ? `${n} places with coordinates (${this.settings.place}, ${this.settings.places || this.settings.geo}). Map tiles © OpenStreetMap contributors.`
					: 'No places with coordinates: the atlas needs a place attribute and its coordinates per text (see the atlas settings).';
			},

			/** The map as shown, as GeoJSON (EPSG:4326). */
			download() {
				const { view, arg } = this.current();
				const name = `${(this.v.name || 'map').replace(/[^A-Za-z0-9]+/g, '_')}_${this.view.replace(/[^A-Za-z0-9]+/g, '_')}`;
				const gj = { type: 'FeatureCollection', name, features: view.features(this, arg) };
				const a = document.createElement('a');
				a.href = URL.createObjectURL(new Blob([JSON.stringify(gj)], { type: 'application/geo+json' }));
				a.download = `${name}.geojson`;
				document.body.appendChild(a);
				a.click();
				setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
			},

			async save(scope) {
				this.busy = true;
				this.error = '';
				try {
					const j = await this.api('save', { scope, ...this.v }, true);
					this.corpusMaps = j.corpus_maps || [];
					this.myMaps = j.my_maps || [];
					const key = String(this.v.name).replace(/[^A-Za-z0-9]/g, '').toLowerCase().slice(0, 32);
					this.mapKey = `${scope === 'corpus' ? 'corpus' : 'mine'}:${key}`;
				} catch (e) { this.error = e.message; } finally { this.busy = false; }
			},
			async remove() {
				const [scope, key] = this.mapKey.split(':');
				if (!key || !window.confirm('Delete this map? (the corpus is not changed)')) return;
				try {
					const j = await this.api('delete', { scope: scope === 'corpus' ? 'corpus' : 'mine', key }, true);
					this.corpusMaps = j.corpus_maps || [];
					this.myMaps = j.my_maps || [];
					this.mapKey = '';
					await this.openMap('');
				} catch (e) { this.error = e.message; }
			},
		};
	};

	function escapeHtml(s) {
		return String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	}

	// for tests (node)
	window.ttAtlasLib = { voronoiCells: G.voronoiCells, localPlane: G.localPlane, variantOf, addLiteral, removeLiteral, escapeLiteral, aggregate, summarize, properties };
})();
