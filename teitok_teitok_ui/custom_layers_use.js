/**
 * TEITOK action `custom_layers`: using the labels — making them searchable (a pando overlay,
 * custom_layers_overlay.php) and links that search and count by them in flexicorp. Mixed into the
 * page's component (custom_layers.js); load this file first.
 */
(function () {
	const PANDO = { b: 'pando', e: 'pando', l: 'pando-cql', f: 'pando' };

	/** The query name of one of the user's own label attributes: my-<key> (flexicorp rewrites it). */
	function overlayName(key) {
		return `my-${key}`;
	}

	/** The name others type for an attribute of a layer published as `pub` (as custom_layers_publish.php). */
	function publicQueryName(pub, key) {
		return `${pub}-${key}`;
	}

	/** URL-safe base64 of UTF-8 text, without padding (flexicorp's viz= share state). */
	function base64url(text) {
		const bytes = new TextEncoder().encode(text);
		let bin = '';
		for (const b of bytes) bin += String.fromCharCode(b);
		return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}

	/**
	 * A flexicorp link (always on pando: only pando reads overlays): a search, or with `fields` the
	 * frequencies of the query by those fields shown as `vm` (bar, sankey, …).
	 */
	function flexicorpLink(query, fields, vm) {
		const st = { s: 'fvs1', b: PANDO, q: { q: query } };
		if (fields && fields.length) {
			st.u = { t: 'frequency', s: 'freq' };
			st.f = { f: fields[0], ff: fields, l: 100, vm: vm || 'bar', cs: 'relative', co: 'size', to: { asc: true }, m: ['count'] };
		} else {
			st.u = { t: 'search' };
		}
		return `index.php?action=flexicorp&viz=${base64url(JSON.stringify(st))}`;
	}

	/** How often each value of `key` occurs in the user's labels (docs: doc → corresp → values). */
	function valueCounts(docs, key) {
		const out = {};
		for (const spans of Object.values(docs || {})) {
			for (const vals of Object.values(spans || {})) {
				const v = vals && vals[key];
				if (v) out[v] = (out[v] || 0) + 1;
			}
		}
		return out;
	}

	window.ttCustomLayersUseMixin = function () {
		return {
			layer: null,         // {current, built, reason, struct} from the server
			layerNote: '',
			publicName: '',      // published under this name ('' = private)
			publicSuggestions: [],
			publishOwn: '',

			/** The query name of one of the user's attributes (my-<attribute>). */
			layerName(key) {
				return overlayName(key);
			},
			layerCounts(key) {
				return valueCounts(this.docs, key);
			},
			layerStatus() {
				const l = this.layer;
				if (!l || !l.available) return '';
				if (l.current) return `Searchable in flexicorp (updated ${l.built.replace('T', ' ').replace('Z', ' UTC')}).`;
				if (l.reason === 'labels changed') return 'You changed labels since they were made searchable.';
				if (l.reason === 'corpus reindexed') return 'The corpus was reindexed: make your labels searchable again.';
				return 'Your labels are not searchable yet.';
			},
			async buildLayer() {
				this.busy = true;
				this.error = '';
				this.layerNote = '';
				try {
					const j = await this.api('build_layer', {}, true);
					this.layer = j.layer || this.layer;
					this.layerNote = j.missing
						? `${j.labels} labels searchable; ${j.missing} could not be found in the corpus (reindexed documents or tokens).`
						: `${j.labels} labels searchable.`;
				} catch (e) {
					this.error = e.message;
				} finally {
					this.busy = false;
				}
			},
			/** After a label changed: the searchable copy is out of date. */
			layerChanged() {
				if (this.layer && this.layer.current) this.layer = { ...this.layer, current: false, reason: 'labels changed' };
			},

			/** A click on a link to flexicorp: bring the searchable copy up to date first. */
			async openLayerLink(e) {
				const a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
				if (!a || (this.layer && this.layer.current)) return;
				e.preventDefault();
				const w = window.open('', '_blank');   // now, while the click still counts (pop-up blockers)
				await this.buildLayer();
				if (w) w.location.href = a.href;
				else window.location.href = a.href;
			},

			/** The user's own attributes that are shared (only those are published). */
			sharedKeys() {
				return this.attributes.filter((a) => !a.owner && a.shared).map((a) => a.key);
			},
			/** What others type for the published attributes. */
			publishedNames() {
				return this.publicName ? this.sharedKeys().map((k) => publicQueryName(this.publicName, k)) : [];
			},
			async publish(name) {
				await this.write('publish', { name: String(name || '').trim() });
				if (!this.error) this.publishOwn = '';
			},

			searchLink(key, value) {
				return flexicorpLink(`[${this.layerName(key)}="${value}"]`);
			},
			countLink(key) {
				return flexicorpLink(`[${this.layerName(key)}!="_"]`, [this.layerName(key)], 'bar');
			},
			/** Two attributes against each other (sense × transitivity). */
			crossLink(a, b) {
				const na = this.layerName(a), nb = this.layerName(b);
				return flexicorpLink(`[${na}!="_" & ${nb}!="_"]`, [na, nb], 'sankey');
			},
		};
	};

	// for tests (node)
	window.ttCustomLayersUseLib = { overlayName, publicQueryName, base64url, flexicorpLink, valueCounts };
})();
