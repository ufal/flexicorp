/*
 * Shared helper utilities for FlexiCorp UI modules.
 *
 * Keep these helpers side-effect free where possible so they can be reused
 * by both legacy flexicorp and advanced_freqs apps.
 */

window.ttFlexicorpFns = window.ttFlexicorpFns || {};

/**
 * Normalize operation id from backend payloads.
 */
window.ttFlexicorpFns.normalizeOperation = function normalizeOperation(op) {
	const v = String(op || '').trim().toLowerCase();
	const allowed = new Set(['query', 'freq', 'count', 'dist', 'coll', 'dcoll', 'keyness', 'info', 'status', 'list-docs', 'reindex']);
	return allowed.has(v) ? v : '';
};

/**
 * User-facing operation labels.
 */
window.ttFlexicorpFns.operationLabel = function operationLabel(op) {
	const key = window.ttFlexicorpFns.normalizeOperation(op);
	const labels = {
		query: 'Query',
		freq: 'Frequency',
		count: 'Count',
		dist: 'Distribution',
		coll: 'Collocations',
		dcoll: 'Dependency collocations',
		keyness: 'Keyness',
		info: 'Info',
		status: 'Status',
		'list-docs': 'Documents',
		reindex: 'Reindex',
	};
	return labels[key] || '';
};

/**
 * Normalize named query records from mixed providers.
 */
window.ttFlexicorpFns.normalizeNamedQueryRecord = function normalizeNamedQueryRecord(rec) {
	const r = rec && typeof rec === 'object' ? rec : {};
	const name = String(r.name || r.id || r.label || '').trim();
	const query = String(r.query || r.cql || '').trim();
	const source = String(r.source || 'stored').trim() || 'stored';
	const timestamp = String(r.timestamp || r.updated_at || '').trim();
	return { name, query, source, timestamp };
};

/**
 * Fix TEITOK/easycorp paths where "Scripts" and "flexicorp.*" were concatenated without a slash.
 */
window.ttFlexicorpFns.normalizeFlexicorpEndpointUrl = function normalizeFlexicorpEndpointUrl(url) {
	let s = String(url || '').trim();
	if (!s) return s;
	s = s.replace(/Scriptsflexicorp\.php/gi, 'Scripts/flexicorp.php');
	s = s.replace(/Scriptsflexicorp\.js/gi, 'Scripts/flexicorp.js');
	return s;
};

/**
 * POST target for FlexiCorp JSON (`ajax=1`). TEITOK entry is **index.php** in the current browser path
 * (`new URL('index.php', window.location.href)`), same idea as other flexicorp.js helpers — not Scripts/flexicorp.php.
 * Prefer `#flexicorp-advanced-freqs-root[data-af-json-endpoint]` when PHP bootstrapped it.
 */
window.ttFlexicorpFns.flexicorpAjaxPostUrl = function flexicorpAjaxPostUrl(rootEl, actionOverride) {
	const root = rootEl || (typeof document !== 'undefined' ? document.getElementById('flexicorp-advanced-freqs-root') : null);
	let resolved = '';
	const explicit = root && root.getAttribute('data-af-json-endpoint');
	if (explicit && String(explicit).trim()) {
		resolved = window.ttFlexicorpFns.normalizeFlexicorpEndpointUrl(String(explicit).trim());
	} else {
		try {
			const cur = new URL(window.location.href);
			let actionName = '';
			if (actionOverride !== undefined && actionOverride !== null && String(actionOverride).trim() !== '') {
				actionName = String(actionOverride).trim();
			} else if (root && root.getAttribute('data-af-action')) {
				actionName = String(root.getAttribute('data-af-action')).trim();
			}
			if (!actionName) actionName = cur.searchParams.get('action') || 'flexicorp';
			const url = new URL('index.php', window.location.href);
			url.searchParams.set('action', actionName);
			const debug = cur.searchParams.get('debug');
			if (debug !== null && debug !== '') url.searchParams.set('debug', debug);
			const subc = cur.searchParams.get('subc');
			if (subc !== null && subc !== '') url.searchParams.set('subc', subc);
			resolved = url.toString();
		} catch (_) {
			resolved = window.location.href;
		}
	}
	return resolved;
};

/**
 * POST FormData to flexicorp JSON API (browser). FQS vs local adapters stay inside flexicorp.php.
 * @returns {{ response: Response, raw: string, state: object|null, parseError: Error|null }}
 */
window.ttFlexicorpFns.runFlexicorpAjaxFetch = async function runFlexicorpAjaxFetch(formData, rootEl, resolvedUrl) {
	const url =
		resolvedUrl && String(resolvedUrl).trim()
			? String(resolvedUrl).trim()
			: window.ttFlexicorpFns.flexicorpAjaxPostUrl(rootEl);
	if (typeof console !== 'undefined' && typeof console.log === 'function') {
		let rawQuery = '';
		let postVars = {};
		let effectiveQuery = '';
		try {
			rawQuery = formData && typeof formData.get === 'function'
				? String(formData.get('query') || '')
				: '';
		} catch (_) {
			rawQuery = '';
		}
		try {
			if (formData && typeof formData.entries === 'function') {
				const bag = {};
				for (const [k, v] of formData.entries()) {
					const key = String(k);
					const val = typeof v === 'string' ? v : String(v);
					if (Object.prototype.hasOwnProperty.call(bag, key)) {
						if (Array.isArray(bag[key])) bag[key].push(val);
						else bag[key] = [bag[key], val];
					} else {
						bag[key] = val;
					}
				}
				postVars = bag;
			}
		} catch (_) {
			postVars = {};
		}
		try {
			const run = String((postVars && postVars.run) || '').trim().toLowerCase();
			const base = String(rawQuery || '').trim().replace(/\s*;+\s*$/, '');
			if (run === 'coll') {
				const collField = String((postVars && postVars.coll_field) || '').trim() || 'lemma';
				effectiveQuery = base ? `${base}; coll by ${collField}` : `coll by ${collField}`;
			} else if (run === 'freq') {
				const ff = postVars && postVars.freq_field;
				let freqField = '';
				if (Array.isArray(ff)) freqField = ff.map((x) => String(x).trim()).filter(Boolean).join(', ');
				else freqField = String(ff || '').trim();
				if (!freqField) freqField = 'lemma';
				effectiveQuery = base ? `${base}; freq by ${freqField}` : `freq by ${freqField}`;
			} else if (run === 'query') {
				effectiveQuery = base;
			}
		} catch (_) {
			effectiveQuery = '';
		}
		console.log('running centralized query', { query: rawQuery, effectiveQuery, post: postVars });
	}
	const response = await fetch(url, {
		method: 'POST',
		body: formData,
		credentials: 'same-origin',
		headers: {
			Accept: 'application/json, text/javascript, */*;q=0.01',
			'X-Requested-With': 'XMLHttpRequest',
		},
	});
	const raw = await response.text();
	let state = null;
	let parseError = null;
	try {
		state = raw ? JSON.parse(raw) : null;
	} catch (e) {
		parseError = e instanceof Error ? e : new Error(String(e));
	}
	return { response, raw, state, parseError };
};

/**
 * Canonical selectable frequency field keys from the rendered fieldset.
 * Shared consumer helper for Frequency/Contrast/other stats modules.
 */
window.ttFlexicorpFns.frequencySelectableFieldKeys = function frequencySelectableFieldKeys(rootEl) {
	try {
		const root =
			rootEl
			|| (typeof document !== 'undefined' ? document.getElementById('flexicorp-content') : null)
			|| (typeof document !== 'undefined' ? document : null);
		if (!root || typeof root.querySelector !== 'function') return [];
		const fs = root.querySelector('fieldset.flexicorp-freq-field-checkboxes[data-field-keys]');
		if (!fs) return [];
		const raw = String(fs.getAttribute('data-field-keys') || '').trim();
		if (!raw) return [];
		const parsed = JSON.parse(raw);
		if (!Array.isArray(parsed)) return [];
		return parsed.map((k) => String(k || '').trim()).filter(Boolean);
	} catch (_) {
		return [];
	}
};

/**
 * Canonical label map for selectable/searchable fields in UI controls.
 * Priority:
 * 1) state.attributeCatalog.searchable_display_labels (server canonical)
 * 2) state.frequency.fieldLabels filtered by frequencySelectableFieldKeys()
 */
window.ttFlexicorpFns.frequencySelectableFieldLabelMap = function frequencySelectableFieldLabelMap(appLike, rootEl) {
	const app = appLike && typeof appLike === 'object' ? appLike : {};
	const attrCatalog = app.attributeCatalog && typeof app.attributeCatalog === 'object'
		? app.attributeCatalog
		: {};
	const fromCatalogDisplay = attrCatalog.searchable_display_labels && typeof attrCatalog.searchable_display_labels === 'object'
		? attrCatalog.searchable_display_labels
		: null;
	const fromCatalogAny = attrCatalog.searchable_labels && typeof attrCatalog.searchable_labels === 'object'
		? attrCatalog.searchable_labels
		: null;
	if ((fromCatalogDisplay && Object.keys(fromCatalogDisplay).length) || (fromCatalogAny && Object.keys(fromCatalogAny).length)) {
		return {
			...(fromCatalogAny || {}),
			...(fromCatalogDisplay || {}),
		};
	}
	const labels = app.frequency && app.frequency.fieldLabels && typeof app.frequency.fieldLabels === 'object'
		? app.frequency.fieldLabels
		: {};
	const allow = new Set(window.ttFlexicorpFns.frequencySelectableFieldKeys(rootEl));
	if (!allow.size) return labels;
	const out = {};
	Object.keys(labels).forEach((k) => {
		const key = String(k || '').trim();
		if (!key || !allow.has(key)) return;
		out[key] = labels[k];
	});
	return out;
};

/**
 * Human-readable numeric formatter (similar intent to TEITOK hrnum).
 * precision = significant digits for compact mode, or max fraction digits for plain mode.
 */
window.ttFlexicorpFns.hrnum = function hrnum(value, precision, opts) {
	const n = Number(value);
	if (!Number.isFinite(n)) return '';
	const pRaw = Number(precision);
	const p = Number.isFinite(pRaw) ? Math.max(1, Math.min(8, Math.floor(pRaw))) : 2;
	const o = opts && typeof opts === 'object' ? opts : {};
	const compact = o.compact === true;
	if (!compact) {
		const d = Math.max(0, Math.min(6, p));
		return n.toLocaleString(undefined, {
			minimumFractionDigits: 0,
			maximumFractionDigits: d,
		});
	}
	const abs = Math.abs(n);
	let scale = 1;
	let suffix = '';
	const billionFrom = Number.isFinite(Number(o.billionFrom)) ? Number(o.billionFrom) : 1e9;
	const millionFrom = Number.isFinite(Number(o.millionFrom)) ? Number(o.millionFrom) : 1e6;
	const thousandFrom = Number.isFinite(Number(o.thousandFrom)) ? Number(o.thousandFrom) : 1e3;
	if (abs >= billionFrom) { scale = 1e9; suffix = 'B'; }
	else if (abs >= millionFrom) { scale = 1e6; suffix = 'M'; }
	else if (abs >= thousandFrom) { scale = 1e3; suffix = 'K'; }
	const scaled = n / scale;
	const scaledAbs = Math.abs(scaled);
	const order = scaledAbs > 0 ? Math.floor(Math.log10(scaledAbs)) : 0;
	const decimals = Math.max(0, Math.min(6, p - 1 - order));
	const rounded = Number(scaled.toFixed(decimals));
	const txt = rounded.toLocaleString(undefined, {
		minimumFractionDigits: decimals,
		maximumFractionDigits: decimals,
	});
	return suffix ? `${txt}${suffix}` : txt;
};

/**
 * Format an IPM / rate for display without fake precision (e.g. 10143.00 → 10,143).
 * When `opts.count` is the underlying hit/token count, significant digits follow ~1/√n.
 */
window.ttFlexicorpFns.formatIpmDisplay = function formatIpmDisplay(value, opts) {
	const n = Number(value);
	if (!Number.isFinite(n)) return '';
	const o = opts && typeof opts === 'object' ? opts : {};
	const count = Number(o.count);
	let sig = 2;
	if (Number.isFinite(count) && count > 0) {
		const relErr = 1 / Math.sqrt(count);
		const implied = Math.floor(-Math.log10(relErr)) + 1;
		sig = Math.max(1, Math.min(4, implied));
	} else {
		// No count: still drop trailing .00 for large rates; keep light decimals for rare events.
		const abs = Math.abs(n);
		if (abs >= 100) sig = 3;
		else if (abs >= 10) sig = 3;
		else if (abs >= 1) sig = 3;
		else sig = 2;
	}
	if (typeof window.ttFlexicorpFns.hrnum === 'function') {
		return window.ttFlexicorpFns.hrnum(n, sig, { compact: true, millionFrom: 1e5 });
	}
	if (Math.abs(n) >= 100) return String(Math.round(n));
	return String(Number(n.toFixed(Math.abs(n) >= 10 ? 1 : 2)));
};

/**
 * Parse capabilities JSON from a root element data attribute.
 */
window.ttFlexicorpFns.readCapabilitiesFromDataAttr = function readCapabilitiesFromDataAttr(root, attrName) {
	const caps = { hasGeo: false };
	if (!root || typeof root.getAttribute !== 'function') return caps;
	const attr = String(attrName || 'data-capabilities').trim() || 'data-capabilities';
	const raw = String(root.getAttribute(attr) || '').trim();
	if (!raw) return caps;
	try {
		const parsed = JSON.parse(raw);
		if (parsed && typeof parsed === 'object') {
			caps.hasGeo = !!parsed.hasGeo;
		}
	} catch (_) {
		// Ignore malformed payload and keep defaults.
	}
	return caps;
};

/**
 * Backend display labels (subset of flexicorp.js backendOptions; keep in sync when adding backends).
 */
window.ttFlexicorpFns.flexicorpBackendOptions = {
	flexi: { label: 'flexi', description: '' },
	cqp: { label: 'Corpus WorkBench', description: '' },
	manatee: { label: 'Manatee', description: '' },
	pando: { label: 'Pando', description: '' },
	'flexicorp-pando': { label: 'flexicorp-pando', description: '' },
	blacklab: { label: 'BlackLab (BCQL)', description: '' },
	clickhouse: { label: 'ClickHouse (SQL)', description: '' },
	clickql: { label: 'ClickQL (ClickHouse)', description: '' },
	teitokxml: { label: 'TEITOK XML documents', description: '' },
};

/**
 * Human-readable backend label (matches flexicorp getBackendLabel).
 */
window.ttFlexicorpFns.getBackendLabel = function getBackendLabel(backend) {
	const key = String(backend || '').trim();
	const item = window.ttFlexicorpFns.flexicorpBackendOptions[key];
	return item ? item.label : key;
};

/**
 * Main toolbar title for the active backend + dialect (matches flexicorp currentToolTitle()).
 */
window.ttFlexicorpFns.flexicorpToolbarMainTitle = function flexicorpToolbarMainTitle(backend, queryLanguage) {
	const b = String(backend || '').trim().toLowerCase();
	const ql = String(queryLanguage || '').trim().toLowerCase();

	if (b === 'cqp' || (b === 'flexi' && (ql === 'cwb-cql' || ql === 'cwb' || ql === 'cql'))) {
		return 'Corpus WorkBench (CWB/CQP)';
	}
	if (b === 'flexi' && (ql === 'manatee-cql' || ql === 'manatee')) {
		return 'Manatee (via flexi)';
	}
	if (b === 'manatee') {
		return 'Manatee';
	}
	if (b === 'blacklab') {
		if (ql === 'corpusql') return 'BlackLab (CorpusQL)';
		return 'BlackLab (BCQL)';
	}
	if (b === 'clickql') {
		if (ql === 'clickcql' || ql === 'clickql') return 'ClickQL (ClickHouse)';
		if (ql === 'pmltq' || ql === 'clickpmltq') return 'PML-TQ (via ClickQL)';
		if (ql === 'sql') return 'ClickHouse (SQL)';
	}
	if (b === 'clickhouse') {
		return 'ClickHouse (SQL)';
	}
	if (b === 'teitokxml') {
		return 'TEITOK XML documents';
	}
	if (b === 'pando' || b === 'flexicorp-pando') {
		return 'Pando';
	}
	const label = window.ttFlexicorpFns.getBackendLabel(backend);
	return label || 'Corpus search';
};

/**
 * @deprecated Use flexicorpToolbarMainTitle + dialect line instead.
 */
window.ttFlexicorpFns.currentToolTitle = function currentToolTitle(backend, fallback) {
	const main = window.ttFlexicorpFns.flexicorpToolbarMainTitle(backend, '');
	if (main && main !== 'Corpus search') return main;
	const f = String(fallback || '').trim();
	const b = String(backend || '').trim();
	return b || f || 'Advanced statistics';
};

/**
 * Backend label with stable fallback (short id when unknown).
 */
window.ttFlexicorpFns.backendLabel = function backendLabel(backend) {
	const b = String(backend || '').trim();
	if (!b) return 'unknown';
	const mapped = window.ttFlexicorpFns.getBackendLabel(b);
	return mapped || b;
};

/**
 * Compact backend metadata line.
 */
window.ttFlexicorpFns.backendMetaLine = function backendMetaLine(meta) {
	const m = meta && typeof meta === 'object' ? meta : {};
	const parts = [];
	if (m.backend) parts.push('backend: ' + String(m.backend));
	if (m.queryLanguage) parts.push('query language: ' + String(m.queryLanguage));
	if (m.corpusFormat) parts.push('corpus format: ' + String(m.corpusFormat));
	return parts.length ? parts.join(' · ') : 'backend metadata unavailable';
};


/**
 * A "simple" search, as in TEITOK's cqp.php: text without CQL syntax is a list of words,
 * each one token on the word attribute, `*` a wildcard (`un*` → [word="un.*"]); everything
 * else in a word is literal. Returns '' when the text is CQL (brackets, quotes) or looks
 * like a program or pattern (; = / < > ( ) { } | & :), so it is sent as written.
 */
window.ttFlexicorpFns.simpleQueryToCql = function simpleQueryToCql(text, field) {
	const t = String(text || '').trim();
	if (!t || /[\[\]"\/<>;=(){}|&:]/.test(t)) return '';
	const attr = String(field || 'word');
	const words = t.split(/\s+/).filter(Boolean);
	if (!words.length) return '';
	return words.map((w) => {
		const parts = w.split('*').map((p) => p.replace(/[.?+^$\\]/g, '\\$&'));
		return '[' + attr + '="' + parts.join('.*') + '"]';
	}).join(' ');
};
