/**
 * flexicorp TEITOK UI: Stats: "Other" results (generic tables) and their chart.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpFreqsExtend() (flexicorp_freqs.js)
 * through window.ttFlexicorpFreqsParts; `this` is the component.
 */
window.ttFlexicorpFreqsParts = window.ttFlexicorpFreqsParts || {};

/**
 * A grouped count (count / group by several fields) as nested rows. `tree` is fc_result.php's
 * (docs/Result-Reading.md): {fields[], total_matches, groups, top_groups, groups_returned,
 * child_limit, hidden, nodes[]}, a node {field, value, count, pct, pct_of_parent, groups, hidden,
 * children[]}. Pure functions, so tests run them without the page.
 */
window.ttFlexicorpTree = {
	/**
	 * The visible rows: every top-level node, under an open one (open[id]) its children, and where a
	 * cut left values out a "more" row. A row's id is its path of indexes ("/0/2"), unique in one answer.
	 */
	rows(tree, open) {
		const out = [];
		if (!tree || !Array.isArray(tree.nodes)) return out;
		const fields = Array.isArray(tree.fields) ? tree.fields.map(String) : [];
		const walk = (nodes, depth, parent) => {
			nodes.forEach((n, i) => {
				const id = `${parent}/${i}`;
				const kids = Array.isArray(n.children) ? n.children : [];
				const isOpen = !!(open && open[id]) && kids.length > 0;
				out.push({
					id, depth, more: false,
					field: String(n.field != null ? n.field : (fields[depth] || '')),
					value: n.value != null ? String(n.value) : '',
					count: Number(n.count) || 0,
					pct: n.pct != null ? Number(n.pct) : null,
					pctOfParent: n.pct_of_parent != null ? Number(n.pct_of_parent) : null,
					groups: n.groups != null ? Number(n.groups) : null,
					hidden: Number(n.hidden) || 0,
					nextField: fields[depth + 1] || '',
					hasChildren: kids.length > 0,
					open: isOpen,
				});
				if (!isOpen) return;
				walk(kids, depth + 1, id);
				if (Number(n.hidden) > 0) out.push({ id: `${id}/more`, depth: depth + 1, more: true, hidden: Number(n.hidden), field: fields[depth + 1] || '' });
			});
		};
		walk(tree.nodes, 0, '');
		if (Number(tree.hidden) > 0) out.push({ id: '/more', depth: 0, more: true, hidden: Number(tree.hidden), field: fields[0] || '' });
		return out;
	},

	/** The ids of all nodes with children (to open the whole tree). */
	parentIds(tree) {
		const ids = [];
		const walk = (nodes, parent) => nodes.forEach((n, i) => {
			const id = `${parent}/${i}`;
			if (Array.isArray(n.children) && n.children.length) { ids.push(id); walk(n.children, id); }
		});
		if (tree && Array.isArray(tree.nodes)) walk(tree.nodes, '');
		return ids;
	},

	/** What identifies one answer's tree: a new one starts closed. */
	signature(tree) {
		if (!tree || !Array.isArray(tree.nodes)) return '';
		const first = tree.nodes[0] || {};
		return JSON.stringify([tree.fields, tree.total_matches, tree.groups, tree.groups_returned, first.value, first.count]);
	},

	/** A percentage as shown: two decimals at most. */
	pct(v) {
		if (v === null || v === undefined || !Number.isFinite(Number(v))) return '';
		return String(Math.round(Number(v) * 100) / 100);
	},
};

window.ttFlexicorpFreqsParts.stats_other = function () {
	return {
		otherColumnsList() {
			const other = this.other && typeof this.other === 'object' ? this.other : {};
			const rows = Array.isArray(other.rows) ? other.rows : [];
			const cols = Array.isArray(other.columns) ? other.columns : [];
			if (cols.length) return cols.map((c) => String(c));
			if (rows.length && rows[0] && typeof rows[0] === 'object' && !Array.isArray(rows[0])) {
				return Object.keys(rows[0]);
			}
			if (rows.length && Array.isArray(rows[0])) {
				return rows[0].map((_, i) => `col_${i + 1}`);
			}
			return [];
		},

		/** The grouped-count tree of the current result (count / group by several fields), or null. */
		otherTree() {
			const t = this.other && this.other.tree;
			return t && typeof t === 'object' && Array.isArray(t.nodes) ? t : null;
		},

		otherTreeRows() {
			return window.ttFlexicorpTree.rows(this.otherTree(), this.otherTreeOpen);
		},

		/** The tree's column heading: its fields, outermost first. */
		otherTreeHeading() {
			const t = this.otherTree();
			return t && Array.isArray(t.fields) ? t.fields.join(' › ') : '';
		},

		toggleOtherTreeNode(id) {
			const open = Object.assign({}, this.otherTreeOpen || {});
			open[id] = !open[id];
			this.otherTreeOpen = open;
		},

		/** Open (true) or close every node. */
		otherTreeOpenAll(on) {
			const open = {};
			if (on) window.ttFlexicorpTree.parentIds(this.otherTree()).forEach((id) => { open[id] = true; });
			this.otherTreeOpen = open;
		},

		otherTreePct(v) {
			return window.ttFlexicorpTree.pct(v);
		},

		/**
		 * After new `other` data: a tree is shown nested (all closed) unless a chart view was chosen; a
		 * table without a tree is not left in the tree view. The same tree again keeps what was opened.
		 */
		otherApplyViewForResult() {
			const tree = this.otherTree();
			const sig = window.ttFlexicorpTree.signature(tree);
			if (sig !== this._otherTreeSig) this.otherTreeOpen = {};
			if (tree && sig !== this._otherTreeSig && this.otherVizMode === 'table') this.otherVizMode = 'tree';
			if (!tree && this.otherVizMode === 'tree') this.otherVizMode = 'table';
			this._otherTreeSig = sig;
		},

		otherRowsList() {
			const other = this.other && typeof this.other === 'object' ? this.other : {};
			return Array.isArray(other.rows) ? other.rows : [];
		},

		/** The sentence of table row `ri` (pando tabulate row_contexts), or null. */
		otherRowContext(ri) {
			const other = this.other && typeof this.other === 'object' ? this.other : {};
			const list = Array.isArray(other.row_contexts) ? other.row_contexts : [];
			const c = list[ri];
			return c && Array.isArray(c.tokens) && c.tokens.length ? c : null;
		},

		otherHasRowContexts() {
			const other = this.other && typeof this.other === 'object' ? this.other : {};
			return Array.isArray(other.row_contexts) && other.row_contexts.some((c) => c && Array.isArray(c.tokens));
		},

		/** Sentence tokens as {text, hit}: the hit's own tokens marked (a subtree's gaps stay plain). */
		otherRowContextTokens(ri) {
			const c = this.otherRowContext(ri);
			if (!c) return [];
			const hit = new Set(Array.isArray(c.hit) ? c.hit.map(Number) : []);
			return c.tokens.map((t, i) => ({ text: String(t), hit: hit.has(i) }));
		},

		otherRowDocUrl(ri) {
			const c = this.otherRowContext(ri);
			if (!c || !c.doc_id) return '';
			return this.getHitDocUrl({ doc_id: c.doc_id, toks: c.tok_id ? [c.tok_id] : [], tokens: c.tok_id ? [{ id: c.tok_id }] : [] });
		},

		toggleOtherRowContext(ri) {
			const open = Object.assign({}, this.otherOpenRows || {});
			open[ri] = !open[ri];
			this.otherOpenRows = open;
		},

		otherCellValue(row, col, colIndex) {
			if (Array.isArray(row)) return row[colIndex];
			if (row && typeof row === 'object') return row[col];
			return row;
		},

		otherToNumber(value) {
			if (typeof value === 'number' && Number.isFinite(value)) return value;
			if (typeof value === 'string') {
				const cleaned = value.replace(/,/g, '').trim();
				if (!cleaned) return NaN;
				const n = Number(cleaned);
				return Number.isFinite(n) ? n : NaN;
			}
			if (typeof value === 'boolean') return value ? 1 : 0;
			return NaN;
		},

		otherNumericColumns() {
			const cols = this.otherColumnsList();
			const rows = this.otherRowsList();
			if (!cols.length || !rows.length) return [];
			const out = [];
			for (let i = 0; i < cols.length; i += 1) {
				const col = cols[i];
				let seen = 0;
				let numeric = 0;
				for (let r = 0; r < rows.length && seen < 120; r += 1) {
					const v = this.otherCellValue(rows[r], col, i);
					if (v == null || v === '') continue;
					seen += 1;
					if (Number.isFinite(this.otherToNumber(v))) numeric += 1;
				}
				if (seen > 0 && numeric / seen >= 0.7) out.push(col);
			}
			return out;
		},

		otherEnsureChartSelection() {
			const cols = this.otherColumnsList();
			if (!cols.length) {
				this.otherChartXColumn = '';
				this.otherChartYColumn = '';
				return;
			}
			const xNow = String(this.otherChartXColumn || '').trim();
			if (!xNow || !cols.includes(xNow)) this.otherChartXColumn = cols[0];
			const numeric = this.otherNumericColumns();
			const x = String(this.otherChartXColumn || '').trim();
			const preferredY = numeric.find((c) => c !== x) || numeric[0] || '';
			const yNow = String(this.otherChartYColumn || '').trim();
			if (!yNow || !numeric.includes(yNow) || yNow === x) this.otherChartYColumn = preferredY;
			if (!this.otherChartYColumn && (this.otherVizMode === 'bar' || this.otherVizMode === 'line')) this.otherVizMode = this.otherTree() ? 'tree' : 'table';
		},

		otherChartData() {
			this.otherEnsureChartSelection();
			const rows = this.otherRowsList();
			const cols = this.otherColumnsList();
			const xCol = String(this.otherChartXColumn || '').trim();
			const yCol = String(this.otherChartYColumn || '').trim();
			const xi = cols.indexOf(xCol);
			const yi = cols.indexOf(yCol);
			if (xi < 0 || yi < 0 || !rows.length) return { labels: [], values: [], yLabel: yCol || 'Value' };
			const labels = [];
			const values = [];
			for (let i = 0; i < rows.length && labels.length < 120; i += 1) {
				const row = rows[i];
				const xv = this.otherCellValue(row, xCol, xi);
				const yv = this.otherCellValue(row, yCol, yi);
				const n = this.otherToNumber(yv);
				if (!Number.isFinite(n)) continue;
				const lbl = String(xv == null ? '' : xv).trim() || `(row ${i + 1})`;
				labels.push(this.truncateFrequencyChartLabel(lbl));
				values.push(n);
			}
			return { labels, values, yLabel: yCol || 'Value' };
		},

		destroyOtherChart() {
			this._otherChartEpoch = (this._otherChartEpoch || 0) + 1;
			const ch = this.otherChartInstance;
			if (ch) {
				try { if (typeof ch.stop === 'function') ch.stop(); } catch (_) {}
				try { if (typeof ch.destroy === 'function') ch.destroy(); } catch (_) {}
			}
			this.otherChartInstance = null;
		},

		renderOtherChart() {
			const Chart = typeof window !== 'undefined' ? window.Chart : null;
			if (!Chart || !this.frequencyChartLibraryAvailable()) return;
			const mode = this.otherVizMode;
			if (mode === 'table') return;
			const canvas = document.getElementById('flexicorp-other-chart-canvas');
			if (!canvas || !canvas.getContext || !canvas.isConnected) return;
			const { labels, values, yLabel } = this.otherChartData();
			if (!labels.length) {
				this.destroyOtherChart();
				return;
			}
			this.destroyOtherChart();
			const ctx = canvas.getContext('2d');
			if (!ctx) return;
			const palette = this.frequencyChartPalette(labels.length);
			try {
				this.otherChartInstance = new Chart(ctx, {
					type: mode === 'line' ? 'line' : 'bar',
					data: {
						labels,
						datasets: [{
							label: yLabel,
							data: values,
							backgroundColor: mode === 'line' ? 'rgba(59,130,246,0.18)' : palette,
							borderColor: mode === 'line' ? 'rgb(37,99,235)' : 'rgba(0,0,0,0.08)',
							borderWidth: mode === 'line' ? 2 : 1,
							fill: mode === 'line',
							tension: mode === 'line' ? 0.2 : 0,
						}],
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						indexAxis: mode === 'bar' ? 'y' : 'x',
						plugins: { legend: { display: false } },
						scales: {
							x: { ticks: { maxRotation: 55, minRotation: 35, autoSkip: true } },
							y: { beginAtZero: false },
						},
					},
				});
			} catch (_) {
				this.destroyOtherChart();
			}
		},

		scheduleOtherChartRender() {
			this.otherEnsureChartSelection();
			if (!this.frequencyChartLibraryAvailable()) return;
			if (this.statsSubTab && this.statsSubTab !== 'other') {
				this.destroyOtherChart();
				return;
			}
			if (this.otherVizMode === 'table' || this.otherVizMode === 'tree') {
				this.destroyOtherChart();
				return;
			}
			const epoch = this._otherChartEpoch;
			requestAnimationFrame(() => {
				requestAnimationFrame(() => {
					if (epoch !== this._otherChartEpoch) return;
					if (!this.other || !this.other.ran || !this.otherRowsList().length) {
						this.destroyOtherChart();
						return;
					}
					if (this.statsSubTab && this.statsSubTab !== 'other') return;
					if (this.activeTab && this.activeTab !== 'frequency') return;
					this.renderOtherChart();
				});
			});
		},

		setOtherVizMode(mode) {
			const allowed = new Set(['table', 'bar', 'line'].concat(this.otherTree() ? ['tree'] : []));
			const next = allowed.has(mode) ? mode : 'table';
			const chart = next === 'bar' || next === 'line';
			if (chart && !this.frequencyChartLibraryAvailable()) return;
			this.otherVizMode = next;
			if (!chart) this.destroyOtherChart();
			else this.scheduleOtherChartRender();
		},

		onOtherChartColumnsChanged() {
			this.otherEnsureChartSelection();
			if (this.otherVizMode === 'bar' || this.otherVizMode === 'line') this.scheduleOtherChartRender();
		},
	};
};
