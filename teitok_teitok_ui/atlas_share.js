/**
 * atlas.php's share links (the page: atlas.js): a map's whole state (query, attribute, variants, view,
 * colour, minimum, cut at the border, and where the map is: centre and zoom) as one URL-safe string,
 * index.php?action=atlas&s=…, and back, checked, so a damaged or hand-made link cannot put anything odd
 * into the page.
 */
(function () {
	const MAX_VARIANTS = 30;

	function toBase64Url(text) {
		const bytes = new TextEncoder().encode(text);
		let bin = '';
		for (const b of bytes) bin += String.fromCharCode(b);
		return btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}
	function fromBase64Url(s) {
		const bin = atob(String(s).replace(/-/g, '+').replace(/_/g, '/'));
		return new TextDecoder().decode(Uint8Array.from(bin, (c) => c.charCodeAt(0)));
	}

	/** The share string of a map's state. */
	function encodeState(st) {
		return toBase64Url(JSON.stringify({
			n: st.name || '', q: st.query || '', f: st.field || 'form', v: st.variants || [],
			w: st.view || 'cells', c: st.colourBy || '', m: Number(st.minN) || 10, x: st.clip === false ? 0 : 1,
			...(st.at ? { p: [Math.round(st.at.lat * 1e5) / 1e5, Math.round(st.at.lon * 1e5) / 1e5], z: st.at.zoom } : {}),
		}));
	}

	/** A map's state from a share string, checked; null when the string is not one. */
	function decodeState(s) {
		let o;
		try { o = JSON.parse(fromBase64Url(s)); } catch (_) { return null; }
		if (!o || typeof o !== 'object' || typeof o.q !== 'string' || !o.q.trim()) return null;
		const str = (x, max) => (typeof x === 'string' ? x.slice(0, max) : '');
		const variants = (Array.isArray(o.v) ? o.v : []).slice(0, MAX_VARIANTS)
			.filter((x) => Array.isArray(x) && typeof x[0] === 'string' && x[0] !== '')
			.map((x) => [x[0].slice(0, 40), str(x[1], 2000)]);
		return {
			name: str(o.n, 80), query: str(o.q, 2000), field: /^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(o.f) ? o.f : 'form',
			variants, view: /^[a-z]+(?::[A-Za-z0-9_./-]{1,200})?$/.test(o.w) ? o.w : 'cells',
			colourBy: variants.some((x) => x[0] === o.c) ? o.c : '', minN: Math.max(1, Math.min(1e6, parseInt(o.m, 10) || 10)), clip: o.x !== 0,
			at: mapPosition(o.p, o.z),
		};
	}
	/** Where the map is ({ lat, lon, zoom }) when the link gives a usable one, else null (the map fits its places). */
	function mapPosition(p, z) {
		if (!Array.isArray(p) || p.length !== 2) return null;
		const [lat, lon] = p.map(Number), zoom = Number(z);
		if (!(Math.abs(lat) <= 90 && Math.abs(lon) <= 180 && Number.isInteger(zoom) && zoom >= 1 && zoom <= 19)) return null;
		return { lat, lon, zoom };
	}

	window.ttAtlasShare = { encodeState, decodeState };
})();
