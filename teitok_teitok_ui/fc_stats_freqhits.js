/**
 * Frequency table → hits: the values of each row of a frequency distribution link to the search for
 * the hits the row counts — the query the table was computed from, restricted to the row's values: a token field
 * (form, b.lemma) as a condition on the matched token (named a: when the query does not name it),
 * a metadata field (text_id, u_speaker, b.text_lang) after ::. A part of the flexicorp component,
 * through window.ttFlexicorpFreqsParts; `this` is the component.
 */
window.ttFlexicorpFreqsParts = window.ttFlexicorpFreqsParts || {};

window.ttFlexicorpFreqsParts.stats_freqhits = function () {
	/** A value as a regular expression matching just itself, inside a CQL string. */
	const literal = (s) => String(s).replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace(/"/g, '\\"');
	const base64url = (text) => {
		let bin = '';
		for (const b of new TextEncoder().encode(text)) bin += String.fromCharCode(b);
		return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	};

	return {
		/** A row's values as the engine gave them (not truncated for display), one per field. */
		frequencyRowRawParts(row) {
			if (row && Array.isArray(row.values)) return row.values.map(String);
			return this.frequencyRowRawValue(row).split('\t');
		},

		/**
		 * The query for the hits a row counts, or '' when there is none to make: a program of several
		 * statements, compared queries, or a token field without a token to put it on.
		 */
		frequencyRowHitsQuery(row) {
			if (this.frequencyHasCompareQueries()) return '';
			let q = (typeof this.statsBaseQueryText === 'function' ? String(this.statsBaseQueryText() || '') : '').trim().replace(/;\s*$/, '');
			if (!q || q.includes(';')) return '';
			// the result's fields, else the ones asked for (a result need not repeat them)
			let fields = this.frequencyRawFields();
			if (!fields.length && this.frequency && Array.isArray(this.frequency.formFields)) fields = this.frequency.formFields.map(String).filter(Boolean);
			const parts = this.frequencyRowRawParts(row);
			if (!fields.length || parts.length < fields.length) return '';
			let name = (q.match(/^([A-Za-z_]\w*):\[/) || [])[1] || '';
			const conds = [];
			for (let i = 0; i < fields.length; i += 1) {
				let f = String(fields[i]).trim();
				const v = `"${literal(parts[i])}"`;
				if (!this.frequencyFieldIsTokenAttr(f)) {
					conds.push(`${f.includes('.') ? f : `match.${f}`}=${v}`);   // match.text_id, b.text_lang
					continue;
				}
				const dot = f.indexOf('.');
				const owner = dot > 0 ? f.slice(0, dot) : '';
				if (owner && owner !== 'match' && !/^(feats)$/.test(owner)) {
					conds.push(`${f}=${v}`);                              // b.form: a token the query names
					continue;
				}
				if (owner === 'match') f = f.slice(dot + 1);
				if (!name) {
					if (!q.startsWith('[')) return '';
					name = 'a';
					q = `a:${q}`;
				}
				conds.push(`${name}.${f}=${v}`);                          // form → a.form
			}
			return /::/.test(q) ? `${q} & ${conds.join(' & ')}` : `${q} :: ${conds.join(' & ')}`;
		},

		/**
		 * A link that opens the hits of a row in the search tab: flexicorp's own share format (viz=,
		 * fc_viewstate.js) with this page's backend and context settings, the row's query, and the search tab.
		 */
		frequencyRowHitsUrl(row) {
			const q = this.frequencyRowHitsQuery(row);
			if (!q) return '';
			let now = {};
			try { now = this._compactVisualizationSnapshotPayload(this.buildVisualizationSnapshotPayload()) || {}; } catch (_) { now = {}; }
			const cur = now.q || {};
			const st = { s: 'fvs1', b: now.b, q: { q, cf: cur.cf, cs: cur.cs, w: cur.w, l: cur.l }, u: { t: 'search' } };
			return `${window.location.pathname}?action=flexicorp&viz=${base64url(JSON.stringify(st))}`;
		},
	};
};
