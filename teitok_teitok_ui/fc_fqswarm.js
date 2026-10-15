/**
 * flexicorp TEITOK UI: FQS warm-up. When the page opens, it tells the server once, in the
 * background, that this corpus is about to be searched (run=warm, fc_fqs_warm.php); when the
 * corpus goes through FQS, FQS reads its main index files into its file cache while the user
 * types the query, as choosing a corpus in KonText warms it. Nothing is shown and nothing waits
 * for it. Methods of the flexicorpRoot Alpine component, mixed in by flexicorpApp() (flexicorp.js)
 * through window.ttFlexicorpCoreParts; `this` is the component.
 */
window.ttFlexicorpCoreParts = window.ttFlexicorpCoreParts || {};
window.ttFlexicorpCoreParts.fqswarm = function () {
	return {
		_fqsWarmSent: false,
		/** Ask for the warm-up once per page, after the page has loaded (its own requests first). */
		fqsWarmCorpus() {
			if (this._fqsWarmSent || typeof fetch !== 'function') return;
			this._fqsWarmSent = true;
			const send = () => {
				const fd = new FormData();
				fd.set('action', this.action || 'flexicorp');
				fd.set('run', 'warm');
				const s = this.settings || {};
				fd.set('backend', s.backend || 'cqp');
				if (s.queryEngine) fd.set('query_engine', s.queryEngine);
				if (s.queryLanguage) fd.set('query_language', s.queryLanguage);
				if (s.corpusFormat) fd.set('corpus_format', s.corpusFormat);
				fetch(this.getRequestUrl(), { method: 'POST', body: fd, credentials: 'same-origin' }).catch(() => {});
			};
			if (document.readyState === 'complete') setTimeout(send, 0);
			else window.addEventListener('load', () => setTimeout(send, 0), { once: true });
		},
	};
};
