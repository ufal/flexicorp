/**
 * atlas.php's geometry (the page itself: atlas.js): Voronoi cells around the places, in a local plane
 * in km (the same geometry as pando_geo/voronoi.py), optionally cut at an outline such as a country's
 * border; which region a place lies in; areas merged. A shape is a multipolygon of [lat, lon] rings:
 * [[outer, hole, …], …].
 */
(function () {
	const KM_PER_DEG_LAT = 110.574;

	function localPlane(points) {
		const lat0 = points.reduce((s, p) => s + p[0], 0) / points.length;
		const lon0 = points.reduce((s, p) => s + p[1], 0) / points.length;
		const kx = 111.320 * Math.cos((lat0 * Math.PI) / 180);
		return {
			xy: (lat, lon) => [(lon - lon0) * kx, (lat - lat0) * KM_PER_DEG_LAT],
			latlon: (x, y) => [lat0 + y / KM_PER_DEG_LAT, lon0 + x / kx],
		};
	}

	/** The part of convex `poly` closer to a than to b (one Sutherland–Hodgman step). */
	function clip(poly, a, b) {
		const nx = b[0] - a[0], ny = b[1] - a[1];
		const mx = (a[0] + b[0]) / 2, my = (a[1] + b[1]) / 2;
		const side = (p) => (p[0] - mx) * nx + (p[1] - my) * ny;
		const out = [];
		for (let i = 0; i < poly.length; i += 1) {
			const p = poly[i], q = poly[(i + 1) % poly.length];
			const sp = side(p), sq = side(q);
			if (sp <= 0) out.push(p);
			if ((sp < 0 && sq > 0) || (sq < 0 && sp > 0)) {
				const t = sp / (sp - sq);
				out.push([p[0] + t * (q[0] - p[0]), p[1] + t * (q[1] - p[1])]);
			}
		}
		return out;
	}

	/**
	 * For each [lat, lon] point its cell: a closed ring of [lat, lon], inside a box 15 km around the
	 * places, widened to `extent` ([[south, west], [north, east]]) when given, so that cells reach an
	 * outline that lies further out.
	 */
	function voronoiCells(points, marginKm = 15, extent = null) {
		if (!points.length) return [];
		const plane = localPlane(points);
		const xy = points.map((p) => plane.xy(p[0], p[1]));
		const box = extent ? xy.concat([plane.xy(extent[0][0], extent[0][1]), plane.xy(extent[1][0], extent[1][1])]) : xy;
		const xs = box.map((p) => p[0]), ys = box.map((p) => p[1]);
		const x0 = Math.min(...xs) - marginKm, x1 = Math.max(...xs) + marginKm;
		const y0 = Math.min(...ys) - marginKm, y1 = Math.max(...ys) + marginKm;
		return xy.map((a, i) => {
			let poly = [[x0, y0], [x1, y0], [x1, y1], [x0, y1]];
			for (let j = 0; j < xy.length && poly.length; j += 1) {
				const b = xy[j];
				if (i !== j && (Math.abs(a[0] - b[0]) > 1e-9 || Math.abs(a[1] - b[1]) > 1e-9)) poly = clip(poly, a, b);
			}
			const ring = poly.map((p) => plane.latlon(p[0], p[1]));
			return ring.length ? ring.concat([ring[0]]) : [];
		});
	}

	/** The [[south, west], [north, east]] of an outline (a GeoJSON MultiPolygon's coordinates, [lon, lat]). */
	function outlineExtent(outline) {
		let s = 90, w = 180, n = -90, e = -180;
		for (const poly of outline || []) {
			for (const [lon, lat] of poly[0] || []) {
				if (lat < s) s = lat;
				if (lat > n) n = lat;
				if (lon < w) w = lon;
				if (lon > e) e = lon;
			}
		}
		return s <= n ? [[s, w], [n, e]] : null;
	}

	/**
	 * The cells as shapes: cut at the outline when one is given (needs polygon-clipping, `pc`: a country
	 * border is not convex), else each cell as it is. A cell wholly outside the outline becomes [].
	 */
	function cellShapes(cells, outline, pc) {
		const flip = (ring) => ring.map(([a, b]) => [b, a]);
		return cells.map((ring) => {
			if (!ring.length) return [];
			if (!outline || !pc) return [[ring]];
			return pc.intersection([flip(ring)], outline).map((poly) => poly.map(flip));
		});
	}

	/** Is [lat, lon] inside polygons given as a GeoJSON MultiPolygon's coordinates ([lon, lat]; holes respected)? */
	function inPolygons(polys, lat, lon) {
		const inRing = (ring) => {
			let ins = false;
			for (let i = 0, j = ring.length - 1; i < ring.length; j = i, i += 1) {
				const [xi, yi] = ring[i], [xj, yj] = ring[j];
				if ((yi > lat) !== (yj > lat) && lon < xi + ((lat - yi) * (xj - xi)) / (yj - yi)) ins = !ins;
			}
			return ins;
		};
		return (polys || []).some((poly) => poly.length && inRing(poly[0]) && !poly.slice(1).some(inRing));
	}

	/** Shapes merged into one ([lat, lon] multipolygons; with polygon-clipping, `pc`, their inner borders go, else the pieces are kept together). */
	function mergeShapes(shapes, pc) {
		const flip = (shape) => shape.map((poly) => poly.map((ring) => ring.map(([a, b]) => [b, a])));
		const list = shapes.filter((x) => x.length);
		if (list.length < 2 || !pc) return list.flat(1);
		return flip(pc.union(...list.map(flip)));
	}

	window.ttAtlasGeo = { localPlane, voronoiCells, outlineExtent, cellShapes, inPolygons, mergeShapes };
})();
