/**
 * flexicorp TEITOK UI: the link to multiedit (index.php?action=multiedit): changing the tokens a query found,
 * after looking at them. Offered to logged-in users for a pando query of one token, or one with labelled
 * tokens (`subj:[…] < buy:[…]`; multiedit asks which one to edit when it is not `edit:`). Methods of the
 * flexicorpRoot Alpine component, mixed in by flexicorpApp() (flexicorp.js) through
 * window.ttFlexicorpCoreParts; `this` is the component.
 */
window.ttFlexicorpCoreParts = window.ttFlexicorpCoreParts || {};
window.ttFlexicorpCoreParts.multiedit = function () {
	return {
		/** The query with its string literals blanked out, so that brackets and labels inside quotes do not count. */
		_multieditUnquoted(q) {
			return String(q || '').replace(/"(?:[^"\\]|\\.)*"|'(?:[^'\\]|\\.)*'/g, (m) => '_'.repeat(m.length));
		},

		/** Does multiedit take this query: one token, or labelled tokens, and no program (;)? */
		multieditQueryFits(q) {
			const s = this._multieditUnquoted(q).trim().replace(/;\s*$/, '');
			if (!s || s.indexOf(';') >= 0) return false;
			if (/(^|[^A-Za-z0-9_.])[A-Za-z_][A-Za-z0-9_]*\s*:\s*\[/.test(s)) return true;
			let depth = 0;
			let tokens = 0;
			for (const ch of s) {
				if (ch === '[') { if (depth === 0) tokens++; depth++; } else if (ch === ']' && depth > 0) depth--;
			}
			return tokens === 1;
		},

		/** The multiedit link for the search that ran, or '' when it does not apply. */
		multieditUrl() {
			if (this.activeTab !== 'search' || !this.search || !this.search.ran) return '';
			if (typeof this.teitokUsername === 'function' && !this.teitokUsername()) return '';
			const engine = String((this.settings && (this.settings.queryEngine || this.settings.backend)) || '').toLowerCase();
			if (engine !== 'pando' && engine !== 'flexicorp-pando') return '';
			const q = String(this.search.query || '').trim();
			if (!this.multieditQueryFits(q)) return '';
			return 'index.php?action=multiedit&query=' + encodeURIComponent(q);
		},
	};
};
