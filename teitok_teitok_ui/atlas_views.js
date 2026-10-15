/**
 * atlas.php's ways to show a map (the page: atlas.js; geometry: atlas_geo.js). A view is an entry in
 * VIEWS: a label, whether the colour choice applies (colours), whether it can be cut at the border
 * (cuts), when it is offered (available), its Leaflet layers (layers → { layers, note }) and its
 * GeoJSON features (features). Regions are one view per regions="…" setting ('regions:ADM1', …).
 */
(function () {
	const G = () => window.ttAtlasGeo;
	const LIB = () => window.ttAtlasLib;
	const flip = (polys) => polys.map((poly) => poly.map((ring) => ring.map(([a, b]) => [b, a])));
	const geometry = (shape) => (shape.length === 1 ? { type: 'Polygon', coordinates: flip(shape)[0] } : { type: 'MultiPolygon', coordinates: flip(shape) });
	const point = (r) => ({ type: 'Point', coordinates: [r.lon, r.lat] });
	const names = (app) => app.v.variants.map((vv) => vv[0]);
	const feature = (app, r, geom) => ({ type: 'Feature', properties: LIB().properties(r, names(app)), geometry: geom });
	const placeNote = (app) => `${app.mapped.length} places with at least ${app.minN} hits, ${app.mapped.reduce((s, r) => s + r.n, 0).toLocaleString()} hits`;

	/** The places as small dots on top of areas. */
	function dots(app) {
		return app.mapped.map((r) => L.circleMarker([r.lat, r.lon], { radius: 2.5, color: '#222', weight: 1, fillColor: '#fff', fillOpacity: 0.9 })
			.bindPopup(app.popup(r)).bindTooltip(r.place));
	}

	/** A pie of the variants' counts as an SVG of diameter d. */
	function pie(app, r, d) {
		const rad = d / 2;
		let a0 = -Math.PI / 2, out = '';
		const parts = names(app).concat(['other']).map((v) => [v, r.counts[v] || 0]).filter(([, n]) => n > 0);
		for (const [v, n] of parts) {
			const a1 = a0 + (2 * Math.PI * n) / r.n;
			if (parts.length === 1) out += `<circle cx="${rad}" cy="${rad}" r="${rad - 1}" fill="${app.colour(v)}"/>`;
			else {
				const p = (a) => `${(rad + (rad - 1) * Math.cos(a)).toFixed(2)},${(rad + (rad - 1) * Math.sin(a)).toFixed(2)}`;
				out += `<path d="M${rad},${rad} L${p(a0)} A${rad - 1},${rad - 1} 0 ${a1 - a0 > Math.PI ? 1 : 0} 1 ${p(a1)} Z" fill="${app.colour(v)}"/>`;
			}
			a0 = a1;
		}
		return `<svg width="${d}" height="${d}" viewBox="0 0 ${d} ${d}">${out}<circle cx="${rad}" cy="${rad}" r="${rad - 1}" fill="none" stroke="#222" stroke-width="1"/></svg>`;
	}

	/** Per region: the counts of the places inside it (all places, also those under the minimum), for regions with enough hits. */
	function regionRows(app, regions) {
		const acc = regions.map(() => ({ counts: {}, places: [] }));
		for (const r of LIB().aggregate(app.counts, app.places, app.v.variants, 1)) {
			const i = regions.findIndex((g) => G().inPolygons(g.polygons, r.lat, r.lon));
			if (i < 0) continue;
			acc[i].places.push(r.place);
			for (const [v, n] of Object.entries(r.counts)) acc[i].counts[v] = (acc[i].counts[v] || 0) + n;
		}
		return acc.map((a, i) => {
			const s = LIB().summarize(a.counts);
			return s.n >= app.minN ? { place: regions[i].name, places: a.places, ...s, index: i } : null;
		});
	}

	const VIEWS = {
		points: {
			label: 'points',
			colours: true,
			layers(app) {
				const maxn = Math.max(1, ...app.mapped.map((r) => r.n));
				return {
					layers: app.mapped.map((r) => L.circleMarker([r.lat, r.lon], {
						radius: 3 + 8 * Math.sqrt(r.n / maxn), color: '#222', weight: 1, ...app.fill(r, 0.95),
					}).bindPopup(app.popup(r)).bindTooltip(r.place)),
					note: placeNote(app),
				};
			},
			features: (app) => app.mapped.map((r) => feature(app, r, point(r))),
		},
		pies: {
			label: 'pies (all variants per place)',
			layers(app) {
				const maxn = Math.max(1, ...app.mapped.map((r) => r.n));
				return {
					layers: app.mapped.map((r) => {
						const d = Math.round(14 + 22 * Math.sqrt(r.n / maxn));
						return L.marker([r.lat, r.lon], { icon: L.divIcon({ html: pie(app, r, d), className: 'tt-at-pie', iconSize: [d, d] }) })
							.bindPopup(app.popup(r)).bindTooltip(r.place);
					}),
					note: placeNote(app),
				};
			},
			features: (app) => app.mapped.map((r) => feature(app, r, point(r))),
		},
		cells: {
			label: 'areas (Voronoi)',
			colours: true,
			cuts: true,
			layers(app) {
				const shapes = app.shapes();
				const layers = [];
				app.mapped.forEach((r, i) => {
					if (shapes[i].length) layers.push(L.polygon(shapes[i], { color: '#fff', weight: 1.5, ...app.fill(r) }).bindPopup(app.popup(r)));
				});
				return { layers: layers.concat(dots(app)), note: placeNote(app) };
			},
			features(app) {
				const shapes = app.shapes();
				return app.mapped.map((r, i) => (shapes[i].length ? feature(app, r, geometry(shapes[i])) : null)).filter(Boolean);
			},
		},
		areas: {
			label: 'dialect areas (merged areas; borders are isoglosses)',
			cuts: true,
			available: () => !!window.polygonClipping,
			groups(app) {
				const shapes = app.shapes();
				const by = {};
				app.mapped.forEach((r, i) => { (by[r.dominant] = by[r.dominant] || { places: [], shapes: [] }).places.push(r); by[r.dominant].shapes.push(shapes[i]); });
				return Object.entries(by).map(([v, g]) => ({ variant: v, places: g.places, shape: G().mergeShapes(g.shapes, window.polygonClipping) }));
			},
			layers(app) {
				const layers = this.groups(app).filter((g) => g.shape.length).map((g) => L.polygon(g.shape, {
					color: '#222', weight: 2, fillColor: app.colour(g.variant), fillOpacity: 0.5,
				}).bindPopup(`<b>${app.esc(g.variant)}</b>: most frequent in ${g.places.length} places<br><small>${g.places.map((r) => app.esc(r.place)).join(', ')}</small>`));
				return { layers: layers.concat(dots(app)), note: placeNote(app) };
			},
			features(app) {
				return this.groups(app).filter((g) => g.shape.length).map((g) => ({
					type: 'Feature', properties: { variant: g.variant, places: g.places.map((r) => r.place).join('; '), n: g.places.reduce((s, r) => s + r.n, 0) },
					geometry: geometry(g.shape),
				}));
			},
		},
		regions: {
			colours: true,
			layers(app, key) {
				const regions = app.regionSets[key] || [];
				const rows = regionRows(app, regions);
				const layers = regions.map((g, i) => {
					const r = rows[i];
					const shape = flip(g.polygons);
					if (!r) return L.polygon(shape, { color: '#888', weight: 1, fillColor: '#ccc', fillOpacity: 0.1 }).bindTooltip(`${g.name}: fewer than ${app.minN} hits`);
					return L.polygon(shape, { color: '#fff', weight: 1.5, ...app.fill(r) })
						.bindPopup(app.popup(r) + `<small>${r.places.map(app.esc).join(', ')}</small>`).bindTooltip(g.name);
				});
				const used = rows.filter(Boolean);
				return { layers: layers.concat(dots(app)), note: `${used.length} of ${regions.length} regions with at least ${app.minN} hits, ${used.reduce((s, r) => s + r.n, 0).toLocaleString()} hits` };
			},
			features(app, key) {
				const regions = app.regionSets[key] || [];
				return regionRows(app, regions).filter(Boolean).map((r) => feature(app, r, geometry(flip(regions[r.index].polygons))));
			},
		},
	};

	/** The views on offer: [{ value, label }], regions from the settings. */
	function options(settings) {
		const out = Object.entries(VIEWS).filter(([k, v]) => k !== 'regions' && (!v.available || v.available())).map(([k, v]) => ({ value: k, label: v.label }));
		for (const g of (settings && settings.regions) || []) out.push({ value: `regions:${g.key}`, label: `regions: ${g.label}` });
		return out;
	}
	/** The view for a value ('cells', 'regions:ADM1', …) and its argument. */
	function view(value) {
		const [k, ...rest] = String(value || '').split(':');
		return VIEWS[k] ? { view: VIEWS[k], arg: rest.join(':') } : { view: VIEWS.cells, arg: '' };
	}

	window.ttAtlasViews = { VIEWS, options, view, regionRows, pie };
})();
