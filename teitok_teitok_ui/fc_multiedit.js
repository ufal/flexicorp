/**
 * flexicorp TEITOK UI: the link to multiedit (index.php?action=multiedit): changing the tokens a query found,
 * after looking at them. Shown to logged-in users after a pando search; active for a query of one token or one
 * that labels the token to edit `edit:`, greyed out (with a hint) when the query does not say which token to
 * edit. Methods of the flexicorpRoot Alpine component, mixed in by flexicorpApp() (flexicorp.js) through
 * window.ttFlexicorpCoreParts; `this` is the component.
 */
window.ttFlexicorpCoreParts = window.ttFlexicorpCoreParts || {};
window.ttFlexicorpCoreParts.multiedit = function () {
	return {
		/** The query with its string literals blanked out, so that brackets and labels inside quotes do not count. */
		_multieditUnquoted(q) {
			return String(q || '').replace(/"(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*'/g, (m) => '_'.repeat(m.length));
		},

		/**
		 * What multiedit can do with this query: 'unique' (one token, or a token labelled edit:), 'unclear' (labelled
		 * tokens without edit:, or several tokens: which one to edit is not said), '' (no query, or a program with ;).
		 */
		multieditTarget(q) {
			const s = this._multieditUnquoted(q).trim().replace(/;\s*$/, '');
			if (!s || s.indexOf(';') >= 0) return '';
			if (/(^|[^A-Za-z0-9_.])edit\s*:\s*\[/.test(s)) return 'unique';
			let depth = 0;
			let tokens = 0;
			for (const ch of s) {
				if (ch === '[') { if (depth === 0) tokens++; depth++; } else if (ch === ']' && depth > 0) depth--;
			}
			return tokens === 1 ? 'unique' : (tokens > 1 ? 'unclear' : '');
		},

		/** Is the link shown at all: a pando search that ran, a logged-in user, a query (not a program)? */
		multieditShown() {
			if (this.activeTab !== 'search' || !this.search || !this.search.ran) return false;
			// multiedit writes the XML files
			if (!this.projectHasXml) return false;
			if (typeof this.teitokUsername === 'function' && !this.teitokUsername()) return false;
			const engine = String((this.settings && (this.settings.queryEngine || this.settings.backend)) || '').toLowerCase();
			if (engine !== 'pando' && engine !== 'flexicorp-pando') return false;
			return this.multieditTarget(String(this.search.query || '')) !== '';
		},

		/** The multiedit link, or '' when the query does not say which token to edit (the link is then greyed out). */
		multieditUrl() {
			if (!this.multieditShown()) return '';
			const q = String(this.search.query || '').trim();
			if (this.multieditTarget(q) !== 'unique') return '';
			return 'index.php?action=multiedit&query=' + encodeURIComponent(q);
		},

		multieditHint() {
			return this.multieditUrl()
				? 'Change these tokens: look at the matches and change the ones you tick'
				: 'To change tokens from this query, label the one to edit edit: (for example subj:[…] < edit:[lemma="buy"])';
		},
	};
};
