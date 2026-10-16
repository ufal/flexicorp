/**
 * typology.php's math (no page code: tests run it on fixed data): a feature's value for a language
 * from its counts, languages' distances over the features both have, hierarchical clustering
 * (UPGMA), a similarity space (classical MDS), nearest languages and what differs between two.
 * window.ttTypologyMath.
 */
(function () {
	'use strict';

	const INVENTORY_MIN_SHARE = 0.005;   // a feature value counts when at least 0.5% of the tokens have it
	const BUNDLE_MIN_SHARE = 0.001;
	const UNANNOTATED = 0.99;            // this share of tokens without the feature: not annotated (or absent)

	const sum = (o) => Object.values(o || {}).reduce((a, b) => a + (Number(b) || 0), 0);
	const pct = (x) => `${Math.round(x * 100)}%`;

	/**
	 * A feature's value for one language: { dims: [numbers 0..1], n, label, detail, missing }.
	 * dims are what distances use; label / detail what tables show; missing a reason when there is no
	 * value (too few hits, not annotated).
	 */
	function featureValue(feat, counts, minN) {
		const c = counts || {};
		const n = sum(c);
		const none = (why) => ({ dims: null, n, label: '', detail: '', missing: why });
		if (feat.kind === 'order' || feat.kind === 'share') {
			if (n < minN) return none(n ? `only ${n} hits` : 'no hits');
			const names = feat.variants.map((v) => v.name);
			const shares = names.map((nm) => (Number(c[nm]) || 0) / n);
			const ranked = names.map((nm, i) => [nm, shares[i]]).sort((a, b) => b[1] - a[1]);
			let label;
			if (feat.kind === 'order') {
				// Dryer's criterion, as WALS: dominant when at least twice as frequent as the next
				label = ranked.length < 2 || ranked[0][1] >= 2 * ranked[1][1] ? ranked[0][0] : 'no dominant order';
			} else {
				label = `${ranked[0][0]} ${pct(ranked[0][1])}`;
			}
			return { dims: shares, n, label, detail: ranked.map(([nm, s]) => `${nm} ${pct(s)}`).join(', '), missing: '', top: ranked[0][0] };
		}
		if (feat.kind === 'inventory') {
			if (n < minN) return none(n ? `only ${n} tokens` : 'no tokens');
			const unmarked = (Number(c['']) || 0) / n;
			if (unmarked >= UNANNOTATED) return none(`not annotated (${feat.feat} on ${pct(1 - unmarked)} of the tokens)`);
			const used = Object.entries(c).filter(([k, v]) => k !== '' && v / n >= INVENTORY_MIN_SHARE).sort((a, b) => b[1] - a[1]);
			return { dims: [Math.min(used.length, 12) / 12], n, label: String(used.length),
				detail: used.map(([k]) => k).join(', ') + (unmarked > 0.05 ? ` (${pct(unmarked)} unmarked)` : ''), missing: '', top: String(used.length) };
		}
		if (feat.kind === 'bundles') {
			if (n < minN) return none(n ? `only ${n} tokens` : 'no tokens');
			const marked = Object.entries(c).filter(([k]) => k !== '');
			if (sum(Object.fromEntries(marked)) / n < 1 - UNANNOTATED) return none('no features annotated');
			const bundles = marked.filter(([, v]) => v / n >= BUNDLE_MIN_SHARE).length;
			const perToken = marked.reduce((a, [k, v]) => a + v * k.split('|').length, 0) / n;
			return { dims: [Math.min(1, Math.log(bundles + 1) / Math.log(301)), Math.min(1, perToken / 8)], n,
				label: `${bundles} · ${perToken.toFixed(1)}`, detail: `${bundles} feature bundles; ${perToken.toFixed(1)} features per token`, missing: '', top: String(bundles) };
		}
		return none('unknown kind');
	}

	/** lang → featureId → value, for the given features (data: featureId → lang → counts). */
	function profiles(catalogue, data, langs, featIds, minN) {
		const out = {};
		for (const l of langs) {
			out[l] = {};
			for (const id of featIds) {
				if (!catalogue[id] || !data[id]) continue;
				out[l][id] = featureValue(catalogue[id], data[id][l], minN);
			}
		}
		return out;
	}

	/** Per feature the distance of two values (0..1): the root mean square of their dims' differences. */
	function featureDistance(a, b) {
		if (!a || !b || !a.dims || !b.dims || a.dims.length !== b.dims.length) return null;
		let s = 0;
		for (let i = 0; i < a.dims.length; i += 1) s += (a.dims[i] - b.dims[i]) ** 2;
		return Math.sqrt(s / a.dims.length);
	}

	/** The distance of two languages: the mean over the features both have (null below minShared). */
	function languageDistance(pa, pb, featIds, minShared) {
		let s = 0, k = 0;
		for (const id of featIds) {
			const d = featureDistance(pa[id], pb[id]);
			if (d === null) continue;
			s += d;
			k += 1;
		}
		return k >= (minShared || 1) ? { d: s / k, shared: k } : { d: null, shared: k };
	}

	/** The distance matrix: { langs, D: [[d|null]], shared: [[k]] }. */
	function distances(prof, langs, featIds, minShared) {
		const n = langs.length;
		const D = Array.from({ length: n }, () => new Array(n).fill(0));
		const S = Array.from({ length: n }, () => new Array(n).fill(0));
		for (let i = 0; i < n; i += 1) {
			for (let j = i + 1; j < n; j += 1) {
				const r = languageDistance(prof[langs[i]], prof[langs[j]], featIds, minShared);
				D[i][j] = D[j][i] = r.d;
				S[i][j] = S[j][i] = r.shared;
			}
		}
		return { langs, D, shared: S };
	}

	/** Missing distances replaced by the largest known one (so that clustering and MDS can run). */
	function filled(D) {
		let max = 0;
		for (const row of D) for (const d of row) if (d !== null && d > max) max = d;
		return D.map((row) => row.map((d) => (d === null ? max || 1 : d)));
	}

	/**
	 * UPGMA (average linkage): a binary tree { left, right, height, size } with leaves { leaf: index }.
	 */
	function upgma(D) {
		const M = filled(D);
		let clusters = M.map((_, i) => ({ node: { leaf: i, height: 0, size: 1 }, members: [i] }));
		if (clusters.length === 0) return null;
		while (clusters.length > 1) {
			let bi = 0, bj = 1, best = Infinity;
			for (let i = 0; i < clusters.length; i += 1) {
				for (let j = i + 1; j < clusters.length; j += 1) {
					let s = 0;
					for (const a of clusters[i].members) for (const b of clusters[j].members) s += M[a][b];
					const d = s / (clusters[i].members.length * clusters[j].members.length);
					if (d < best) { best = d; bi = i; bj = j; }
				}
			}
			const a = clusters[bi], b = clusters[bj];
			const node = { left: a.node, right: b.node, height: best / 2, size: a.node.size + b.node.size };
			clusters = clusters.filter((_, k) => k !== bi && k !== bj);
			clusters.push({ node, members: a.members.concat(b.members) });
		}
		return clusters[0].node;
	}

	/** The leaves of a tree in drawing order. */
	function leafOrder(tree) {
		const out = [];
		const walk = (t) => { if (!t) return; if (t.leaf !== undefined) out.push(t.leaf); else { walk(t.left); walk(t.right); } };
		walk(tree);
		return out;
	}

	/** The tree cut into k groups: index → group number (the k−1 highest joins undone). */
	function cutTree(tree, k) {
		let groups = [tree];
		while (groups.length < k) {
			let hi = -1, at = -1;
			groups.forEach((g, i) => { if (g.leaf === undefined && g.height > hi) { hi = g.height; at = i; } });
			if (at < 0) break;
			const g = groups[at];
			groups.splice(at, 1, g.left, g.right);
		}
		const out = {};
		groups.forEach((g, gi) => leafOrder(g).forEach((leaf) => { out[leaf] = gi; }));
		return out;
	}

	/** Classical MDS to 2D: [[x, y], …] (power iteration on the double-centred squared distances). */
	function mds(D) {
		const M = filled(D);
		const n = M.length;
		if (n === 0) return [];
		if (n === 1) return [[0, 0]];
		const sq = M.map((row) => row.map((d) => d * d));
		const rowMean = sq.map((row) => row.reduce((a, b) => a + b, 0) / n);
		const all = rowMean.reduce((a, b) => a + b, 0) / n;
		const B = sq.map((row, i) => row.map((d, j) => -0.5 * (d - rowMean[i] - rowMean[j] + all)));
		const vecs = [];
		const vals = [];
		for (let k = 0; k < 2; k += 1) {
			let v = Array.from({ length: n }, (_, i) => Math.sin(i + 1 + k));   // deterministic start
			let lambda = 0;
			for (let it = 0; it < 300; it += 1) {
				let w = B.map((row) => row.reduce((a, b, j) => a + b * v[j], 0));
				for (const u of vecs) {   // deflation: away from the earlier axes
					const p = w.reduce((a, b, i) => a + b * u[i], 0);
					w = w.map((x, i) => x - p * u[i]);
				}
				const norm = Math.sqrt(w.reduce((a, b) => a + b * b, 0)) || 1;
				lambda = norm;
				const next = w.map((x) => x / norm);
				const delta = next.reduce((a, b, i) => a + Math.abs(b - v[i]), 0);
				v = next;
				if (delta < 1e-10) break;
			}
			vecs.push(v);
			vals.push(lambda);
		}
		return Array.from({ length: n }, (_, i) => [vecs[0][i] * Math.sqrt(vals[0]), vecs[1][i] * Math.sqrt(vals[1])]);
	}

	/** The languages closest to langs[i]: [[index, distance, shared], …], nearest first. */
	function nearest(dist, i, k) {
		if (!dist || !dist.D[i]) return [];
		return dist.D[i]
			.map((d, j) => [j, d, dist.shared[i][j]])
			.filter(([j, d]) => j !== i && d !== null)
			.sort((a, b) => a[1] - b[1])
			.slice(0, k || 8);
	}

	/** What differs between two languages: [[featureId, distance], …] for the features both have, most first. */
	function differences(pa, pb, featIds) {
		return featIds
			.map((id) => [id, featureDistance(pa[id], pb[id])])
			.filter(([, d]) => d !== null)
			.sort((a, b) => b[1] - a[1]);
	}

	window.ttTypologyMath = { featureValue, profiles, featureDistance, languageDistance, distances, upgma, leafOrder, cutTree, mds, nearest, differences };
})();
