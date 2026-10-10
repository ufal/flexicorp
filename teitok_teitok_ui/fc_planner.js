/**
 * flexicorp TEITOK UI: the planner — where an answer lands.
 *
 * One pure function decides the tab and the Stats subtab from an answer, instead of the
 * routing that was spread over applyState, the server's promotion and an operation-driven
 * second pass. Input (built by fcPlanFromState in fc_statsrouting.js):
 *
 *   preserveTab   the answer must not move the user (corpus-info probe, late answers)
 *   serverTab     the tab the server proposes (activeTab)
 *   currentTab, currentSubtab
 *   hint          the server's statsSubTabHint (from the result's operation)
 *   slotOp        operation of a Stats slot the server filled (other / frequency / collocation)
 *   ran           { other, coll, freq }: which Stats slots carry a result
 *   searchRan, hasHits
 *   searchResult  { kind, operation } of the search answer (kind from fc_result.php:
 *                 hits / table / compare_table / collocates / empty)
 *   modules       [{ id, operations }]: Stats modules and the operations they show
 *
 * Output: { tab, subtab, reason } — null keeps the current value; the router then applies
 * its availability guard (ensureStatsSubTabAllowed).
 */
(function () {
	/** The Stats subtab showing an operation's result: built-in panels, then modules, else Other. */
	function subtabForOperation(op, modules) {
		const o = String(op || '').trim().toLowerCase();
		if (!o) return '';
		if (o === 'freq' || o === 'group') return 'freq';
		if (o === 'coll') return 'coll';
		const mods = Array.isArray(modules) ? modules : [];
		for (const m of mods) {
			if (m && m.id && Array.isArray(m.operations) && m.operations.includes(o)) return m.id;
		}
		return 'other';
	}

	/** A search answer that is an aggregation (a typed `freq … by`, coll, count, …): its kind says so. */
	function isAggregationResult(r) {
		const k = r && r.kind ? String(r.kind) : '';
		return k === 'table' || k === 'compare_table' || k === 'collocates';
	}

	function plan(input) {
		const i = input && typeof input === 'object' ? input : {};
		const ran = i.ran && typeof i.ran === 'object' ? i.ran : {};
		const sr = i.searchResult && typeof i.searchResult === 'object' ? i.searchResult : null;
		let subtab = null;
		let reason = '';
		const slotOp = String(i.slotOp || '').trim().toLowerCase();
		const resultOp = isAggregationResult(sr) ? String(sr.operation || '').trim().toLowerCase() : '';
		const op = slotOp || resultOp;
		if (op && op !== 'query') {
			subtab = subtabForOperation(op, i.modules);
			reason = 'operation ' + op;
		} else if (i.hint) {
			subtab = String(i.hint);
			reason = 'server hint';
		} else if (ran.other) {
			subtab = 'other';
			reason = 'other result';
		} else if (ran.coll) {
			subtab = 'coll';
			reason = 'collocation result';
		} else if (ran.freq) {
			subtab = 'freq';
			reason = 'frequency result';
		} else if (i.searchRan && i.hasHits && (!i.currentSubtab || i.currentSubtab === 'corpus')) {
			// after a search with hits, a Stats click lands on the query-scoped view
			subtab = 'freq';
			reason = 'hits: frequency as default';
		}
		let tab = null;
		if (!i.preserveTab) {
			tab = String(i.serverTab || i.currentTab || 'search');
			// an aggregation typed in the search box shows in Stats, whatever the request asked for
			if (sr && isAggregationResult(sr)) tab = 'frequency';
		}
		return { tab, subtab, reason };
	}

	window.ttFlexicorpPlanner = { plan, subtabForOperation, isAggregationResult };
	if (typeof module !== 'undefined' && module.exports) module.exports = window.ttFlexicorpPlanner;
})();
