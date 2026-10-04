/**
 * CWB/CQP-only UI hooks. Loaded only when backend is cqp or flexi+cwb (see flexicorp.php).
 * Shared flexicorp.js must not assume this file exists.
 */
(function (global) {
	'use strict';

	var FlexicorpCwb = {
		/**
		 * Adjust the query string sent to the server for CWB paths only.
		 * @param {string} q raw query from the search box
		 * @param {object} [settings] current flexicorp settings (backend, queryLanguage, corpusFormat, …)
		 * @returns {string}
		 */
		normalizeOutgoingQuery: function (q, settings) {
			if (typeof q !== 'string') {
				return q == null ? '' : String(q);
			}
			// Example: CQP scripts often wrap bare patterns as `Matches = …;` server-side; add
			// client-side transforms here only when they must not apply to other backends.
			return q;
		},
	};

	global.FlexicorpCwb = FlexicorpCwb;
})(typeof window !== 'undefined' ? window : this);
