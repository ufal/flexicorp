/**
 * flexicorp TEITOK UI: Stats: corpus statistics panel.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpFreqsExtend() (flexicorp_freqs.js)
 * through window.ttFlexicorpFreqsParts; `this` is the component.
 */
window.ttFlexicorpFreqsParts = window.ttFlexicorpFreqsParts || {};
window.ttFlexicorpFreqsParts.stats_corpus = function () {
	return {
		/** Raw flexicorp info payload (corpus) for Stats → Corpus overview. */
		corpusStatsInfoPayload() {
			const info = this.info;
			if (!info || typeof info !== 'object') return null;
			return info;
		},
		corpusStatsInfoResult() {
			const p = this.corpusStatsInfoPayload();
			const r = p && p.result;
			return r && typeof r === 'object' ? r : null;
		},
		corpusStatsHasResult() {
			const r = this.corpusStatsInfoResult();
			if (!r) return false;
			const keys = [
				'tokens_count',
				'corpus_size',
				'size',
				'n_tokens',
				'corpus_tokens',
				'tokens',
				'doc_count',
				'documents_count',
				'pattributes',
				'sattributes',
				'native_structures',
				'struct_attributes',
				'sattributes_by_region',
			];
			return keys.some((k) => r[k] != null && r[k] !== '');
		},
		corpusStatsInfoError() {
			const p = this.corpusStatsInfoPayload();
			if (!p) return false;
			if (p.ok === false) return true;
			const err = p.error || p.err;
			if (err && String(err).trim()) return true;
			const errs = p.errors;
			return Array.isArray(errs) && errs.length > 0;
		},
		corpusStatsInfoErrorMessages() {
			const out = [];
			const p = this.corpusStatsInfoPayload();
			if (!p) return out;
			const err = p.error || p.err;
			if (err && String(err).trim()) out.push(String(err).trim());
			const errs = p.errors;
			if (Array.isArray(errs)) {
				errs.forEach((e) => {
					if (e && String(e).trim()) out.push(String(e).trim());
				});
			}
			const r = p.result;
			if (r && typeof r === 'object' && Array.isArray(r.errors) && r.errors.length) {
				r.errors.forEach((e) => {
					if (e && String(e).trim()) out.push(String(e).trim());
				});
			}
			return out;
		},
		corpusStatsBackendLine() {
			const s = this.settings || {};
			const b = String(s.backend || '').trim() || '—';
			const ql = String(s.queryLanguage || '').trim();
			const cf = String(s.corpusFormat || '').trim();
			const bits = [b];
			if (ql) bits.push(ql);
			if (cf) bits.push(cf);
			const ir = this.corpusStatsInfoResult();
			if (ir && ir.file_format && String(ir.file_format).trim()) {
				const ff = String(ir.file_format).trim();
				if (!bits.some((x) => x.toLowerCase().includes(ff))) bits.push(`format: ${ff}`);
			}
			return bits.join(' · ');
		},
		corpusStatsTokenLine() {
			const r = this.corpusStatsInfoResult();
			if (!r) return '—';
			const keys = ['tokens_count', 'corpus_size', 'size', 'n_tokens', 'corpus_tokens', 'tokens'];
			for (let i = 0; i < keys.length; i++) {
				const k = keys[i];
				const v = r[k];
				if (v == null || v === '') continue;
				const n = typeof v === 'number' ? v : Number(String(v).replace(/,/g, ''));
				if (Number.isFinite(n) && n >= 0) return n.toLocaleString();
			}
			const ct = typeof this.frequencyCorpusTokensForIpm === 'function' ? this.frequencyCorpusTokensForIpm() : null;
			if (ct != null && Number.isFinite(ct) && ct > 0) return `${Math.round(ct).toLocaleString()} (from index)`;
			return '—';
		},
		corpusStatsDocumentsLine() {
			const t = this.documentsTotal;
			if (Number.isFinite(t) && t >= 0) return t.toLocaleString();
			const r = this.corpusStatsInfoResult();
			if (r) {
				const docKeys = ['doc_count', 'docs_count', 'documents_count', 'n_docs', 'docs', 'documents'];
				for (let i = 0; i < docKeys.length; i++) {
					const k = docKeys[i];
					if (r[k] != null && Number.isFinite(Number(r[k]))) {
						return Number(r[k]).toLocaleString();
					}
				}
			}
			const ld = this.listDocs && this.listDocs.result;
			if (ld && ld.total != null && Number.isFinite(Number(ld.total))) return Number(ld.total).toLocaleString();
			return '—';
		},
		corpusStatsRegistryLine() {
			const r = this.corpusStatsInfoResult();
			if (!r) return '—';
			const parts = [];
			const deriveProjectCorpusId = () => {
				const root = String(this.projectRoot || '').trim();
				if (!root) return '';
				const segs = root.split('/').filter(Boolean);
				const leaf = segs.length ? segs[segs.length - 1] : '';
				return leaf ? `tt_${leaf.toLowerCase()}` : '';
			};
			const rawCorpus = (r.corpus && String(r.corpus).trim()) || '';
			const rawRegistry = (r.registry && String(r.registry).trim()) || '';
			const lowerCorpus = rawCorpus.toLowerCase();
			const genericCorpusName = lowerCorpus === '' || ['pando', 'cqp', 'manatee', 'clickhouse', 'blacklab', 'flexi'].includes(lowerCorpus);
			const corpusName =
				(!genericCorpusName ? rawCorpus : '')
				|| (rawRegistry && !rawRegistry.includes('/') ? rawRegistry : '')
				|| deriveProjectCorpusId()
				|| rawCorpus
				|| rawRegistry
				|| '';
			if (corpusName) parts.push(`corpus: ${corpusName}`);
			const backendName =
				(r.backend && String(r.backend).trim())
				|| (this.settings && this.settings.backend && String(this.settings.backend).trim())
				|| '';
			if (backendName) parts.push(`backend: ${backendName}`);
			const storageEngine =
				(r.file_format && String(r.file_format).trim())
				|| (r.corpus_format && String(r.corpus_format).trim())
				|| (this.settings && this.settings.corpusFormat && String(this.settings.corpusFormat).trim())
				|| '';
			if (storageEngine) parts.push(`storage: ${storageEngine}`);
			if (!parts.length) return '—';
			return parts.slice(0, 3).join(' · ');
		},
		corpusStatsShowRegistryCard() {
			return this.corpusStatsRegistryLine() !== '—';
		},
		corpusStatsNumberFromKeys(obj, keys) {
			if (!obj || typeof obj !== 'object' || !Array.isArray(keys)) return null;
			for (let i = 0; i < keys.length; i++) {
				const v = obj[keys[i]];
				if (v == null || v === '') continue;
				const n = typeof v === 'number' ? v : Number(String(v).replace(/,/g, ''));
				if (Number.isFinite(n) && n >= 0) return n;
			}
			return null;
		},
		corpusStatsDisplayLabelOnly(key) {
			const k = String(key || '').trim();
			if (!k || !this.corpusStatsHasMeaningfulLabel(k)) return '';
			return String(this.corpusStatsFieldLabel(k) || '').trim();
		},
		corpusStatsValueType(value, fallback = '—') {
			if (value == null || value === '') return fallback;
			if (typeof value === 'number') return Number(value).toLocaleString();
			if (typeof value === 'boolean') return value ? 'yes' : 'no';
			const txt = String(value).trim();
			return txt || fallback;
		},
		corpusStatsSemanticType(rawName, backendTypeHint) {
			const raw = String(rawName || '').trim().toLowerCase();
			const hint = String(backendTypeHint || '').trim().toLowerCase();
			if (!raw && !hint) return '';
			// Ignore generic storage classes; show only semantic type info.
			if (['pattribute', 'sattribute', 'region', 'attribute', 'string'].includes(hint)) {
				// continue with raw-name heuristics
			} else if (hint) {
				if (['date', 'datetime', 'timestamp'].includes(hint)) return 'Date/time';
				if (['time'].includes(hint)) return 'Time';
				if (['mval', 'multi', 'multivalue', 'set', 'array', 'list'].includes(hint)) return 'Multivalue';
				if (['kv', 'keyvalue', 'map', 'dict', 'object', 'json'].includes(hint)) return 'Key-value';
				if (['int', 'integer', 'long', 'float', 'double', 'number', 'numeric'].includes(hint)) return 'Numeric';
				if (['bool', 'boolean'].includes(hint)) return 'Boolean';
				return this.corpusStatsValueType(backendTypeHint, '');
			}
			if (/(^|_)(year|date|month|day|century)(_|$)/.test(raw)) return 'Date/time';
			if (/(^|_)(time|hour|min|sec)(_|$)/.test(raw)) return 'Time';
			if (/(^|_)(geo|geolocation|location|place|lat|lon|lng|latitude|longitude|coords?)(_|$)/.test(raw)) return 'Geolocation';
			if (/(^|_)(mval|multi|values|list|set)(_|$)/.test(raw)) return 'Multivalue';
			if (/(^|_)(kv|keyvalue|json|dict|map)(_|$)/.test(raw)) return 'Key-value';
			if (/(^|_)(upos|xpos|pos|deprel|udfeats|feats)(_|$)/.test(raw)) return 'Linguistic tag/features';
			return '';
		},
		corpusStatsGeneralInfoRows() {
			const r = this.corpusStatsInfoResult();
			if (!r) return [];
			const rows = [];
			const tokenCount = this.corpusStatsNumberFromKeys(r, ['tokens_count', 'corpus_size', 'size', 'n_tokens', 'corpus_tokens', 'tokens']);
			const docCount = this.corpusStatsNumberFromKeys(r, ['doc_count', 'docs_count', 'documents_count', 'n_docs', 'docs', 'documents']);
			rows.push({ label: 'Tokens', value: tokenCount != null ? tokenCount.toLocaleString() : '—' });
			rows.push({ label: 'Documents', value: docCount != null ? docCount.toLocaleString() : '—' });
			if (tokenCount != null && docCount != null && docCount > 0) {
				rows.push({ label: 'Avg tokens per document', value: (tokenCount / docCount).toLocaleString(undefined, { maximumFractionDigits: 2 }) });
			}
			rows.push({ label: 'Backend / storage', value: this.corpusStatsBackendLine() || '—' });
			if (this.isAdmin && this.corpusStatsShowRegistryCard()) {
				rows.push({ label: 'Corpus / backend / storage (admin)', value: this.corpusStatsRegistryLine() || '—' });
			}
			if (r.corpus != null && String(r.corpus).trim()) rows.push({ label: 'Corpus id', value: String(r.corpus).trim() });
			if (r.available != null) rows.push({ label: 'Index available', value: this.corpusStatsValueType(r.available, '—') });
			return rows;
		},
		corpusStatsPattributeRows() {
			const r = this.corpusStatsInfoResult();
			if (!r) return [];
			const out = [];
			const skip = this.corpusStatsHiddenFieldSet();
			const pushRow = (rawName, displayName, vocab, attrType) => {
				const raw = String(rawName || '').trim();
				if (!raw || skip.has(raw)) return;
				const labelOnly = String(displayName || '').trim();
				if (!labelOnly && !this.isAdmin) return;
				out.push({
					raw,
					display: labelOnly || (this.isAdmin ? '(raw only)' : ''),
					vocab: vocab == null || vocab === '' ? '—' : this.corpusStatsValueType(vocab),
						type: this.corpusStatsSemanticType(raw, attrType) || '—',
				});
			};
			if (Array.isArray(r.attributes)) {
				r.attributes.forEach((attr) => {
					if (!attr || typeof attr !== 'object' || !attr.name) return;
					const name = String(attr.name).trim();
					const label = this.corpusStatsDisplayLabelOnly(name)
						|| (attr.display != null ? String(attr.display).trim() : '')
						|| (attr.label != null ? String(attr.label).trim() : '');
					const vocab = attr.vocab ?? attr.types ?? attr.distinct ?? null;
					const type = attr.type ?? attr.value_type ?? attr.kind ?? attr.data_type ?? null;
					pushRow(name, label, vocab, type);
				});
			} else {
				const raw = r.pattributes || r.positional_attrs || r.native_pattributes;
				if (Array.isArray(raw)) {
					raw.forEach((x) => {
						const name = String(x || '').trim();
						pushRow(name, this.corpusStatsDisplayLabelOnly(name), null, null);
					});
				}
			}
			out.sort((a, b) => a.raw.localeCompare(b.raw));
			return out.slice(0, 120);
		},
		corpusStatsFieldLabels() {
			const fns = typeof window !== 'undefined' && window.ttFlexicorpFns && typeof window.ttFlexicorpFns === 'object'
				? window.ttFlexicorpFns
				: {};
			if (typeof fns.frequencySelectableFieldLabelMap === 'function') {
				return fns.frequencySelectableFieldLabelMap(this) || {};
			}
			return this.frequency && this.frequency.fieldLabels && typeof this.frequency.fieldLabels === 'object'
				? this.frequency.fieldLabels
				: {};
		},
		corpusStatsHiddenFieldSet() {
			if (this.isAdmin) return new Set();
			const out = new Set();
			const cat = this.attributeCatalog && typeof this.attributeCatalog === 'object'
				? this.attributeCatalog
				: {};
			const ckeys = Array.isArray(cat.noshow_keys) ? cat.noshow_keys : [];
			for (let i = 0; i < ckeys.length; i += 1) {
				const k = String(ckeys[i] || '').trim();
				if (k) out.add(k);
			}
			const legacy = Array.isArray(this.noshowFields) ? this.noshowFields : [];
			for (let i = 0; i < legacy.length; i += 1) {
				const k = String(legacy[i] || '').trim();
				if (k) out.add(k);
			}
			return out;
		},
		corpusStatsFieldLabel(key) {
			const k = String(key || '').trim();
			if (!k) return '';
			const labels = this.corpusStatsFieldLabels();
			const lbl = labels[k];
			return (lbl && String(lbl).trim()) ? String(lbl).trim() : '';
		},
		corpusStatsHasMeaningfulLabel(key) {
			const k = String(key || '').trim();
			if (!k) return false;
			const lbl = this.corpusStatsFieldLabel(k);
			if (!lbl) return false;
			const low = String(lbl).trim().toLowerCase();
			// Ignore placeholder-like labels leaked from *_display attribute keys.
			if (low === 'display') return false;
			return true;
		},
		corpusStatsHumanizeKey(key) {
			const k = String(key || '').trim();
			if (!k) return '';
			const base = k.replace(/_/g, ' ').replace(/\s+/g, ' ').trim();
			if (!base) return '';
			return base.charAt(0).toUpperCase() + base.slice(1);
		},
		corpusStatsCleanRegionLabel(label, regionKey) {
			const raw = String(label || '').trim();
			if (!raw) return '';
			let out = raw.replace(/\s+/g, ' ').trim();
			// TEITOK often uses labels like "Document Search" for region display; keep this concise in corpus stats.
			if (String(regionKey || '').trim().toLowerCase() === 'text') {
				out = out.replace(/\s+search$/i, '').trim();
			}
			return out || raw;
		},
		corpusStatsDisplayName(key) {
			const k = String(key || '').trim();
			if (!k) return '';
			if (!this.corpusStatsHasMeaningfulLabel(k)) return this.isAdmin ? k : '';
			const lbl = this.corpusStatsFieldLabel(k);
			return this.isAdmin ? `${lbl} (${k})` : lbl;
		},
		corpusStatsRegionHeading(regionKey) {
			const key = String(regionKey || '').trim();
			if (!key) return '';
			// Prefer explicit region label if configured as <region>_display, otherwise humanize.
			const displayKey = `${key}_display`;
			const viaDisplayAttr = this.corpusStatsFieldLabel(displayKey);
			if (this.corpusStatsHasMeaningfulLabel(displayKey) && viaDisplayAttr) {
				const clean = this.corpusStatsCleanRegionLabel(viaDisplayAttr, key);
				return this.isAdmin ? `${clean} (${key})` : clean;
			}
			if (!this.isAdmin) return '';
			if (typeof this.contextScopeLabel === 'function') {
				const fromScope = String(this.contextScopeLabel(key) || '').trim();
				if (fromScope && fromScope.toLowerCase() !== key.toLowerCase()) {
					const pretty = fromScope.charAt(0).toUpperCase() + fromScope.slice(1);
					return this.isAdmin ? `${pretty} (${key})` : pretty;
				}
			}
			return this.corpusStatsDisplayName(key);
		},
		corpusStatsPattributes() {
			const r = this.corpusStatsInfoResult();
			if (!r) return [];
			const out = [];
			const skip = this.corpusStatsHiddenFieldSet();
			if (Array.isArray(r.attributes)) {
				r.attributes.forEach((attr) => {
					if (!attr || typeof attr !== 'object' || !attr.name) return;
					const name = String(attr.name).trim();
				if (name && !skip.has(name)) {
						let s = this.corpusStatsDisplayName(name);
						if (!s) return;
						if (attr.vocab != null && Number.isFinite(Number(attr.vocab))) {
							s += ` (${Number(attr.vocab).toLocaleString()} types)`;
						}
						out.push(s);
					}
				});
			} else {
				const raw = r.pattributes || r.positional_attrs || r.native_pattributes;
				if (Array.isArray(raw)) {
					raw.forEach((x) => {
						const name = String(x).trim();
						if (!name || skip.has(name)) return;
						const shown = this.corpusStatsDisplayName(name);
						if (!shown) return;
						out.push(shown);
					});
				}
			}
			out.sort((a, b) => a.localeCompare(b));
			return out.slice(0, 48);
		},
		corpusStatsRegionGroups() {
			const r = this.corpusStatsInfoResult();
			if (!r) return [];
			const groups = [];
			const skip = this.corpusStatsHiddenFieldSet();
			const pushRegionWithAttrs = (regionRaw, regionLabel, attrs, meta = [], regionInfo = {}) => {
				if (!regionLabel) return;
				groups.push({
					raw: String(regionRaw || '').trim(),
					region: regionLabel,
					meta: Array.isArray(meta) ? meta.filter(Boolean) : [],
					attrs: Array.isArray(attrs) ? attrs.filter(Boolean) : [],
					info: regionInfo && typeof regionInfo === 'object' ? regionInfo : {},
				});
			};
			if (Array.isArray(r.structures)) {
				r.structures.forEach((st) => {
					if (!st || typeof st !== 'object' || !st.name) return;
					const sname = String(st.name).trim();
					if (!sname) return;
					const attrs = [];
					if (Array.isArray(st.region_attrs)) {
						st.region_attrs.forEach((ra) => {
							if (!ra || typeof ra !== 'object' || !ra.name) return;
							const raname = String(ra.name).trim();
							if (raname && !skip.has(`${sname}_${raname}`) && !skip.has(raname)) {
								const rawKey = `${sname}_${raname}`;
								const label = this.corpusStatsDisplayLabelOnly(rawKey) || this.corpusStatsDisplayLabelOnly(raname);
								if (!label && !this.isAdmin) return;
								attrs.push({
									raw: rawKey,
									display: label || '(raw only)',
									vocab: this.corpusStatsValueType(ra.vocab ?? ra.types ?? ra.distinct ?? null),
									type: this.corpusStatsSemanticType(rawKey, ra.type ?? ra.value_type ?? ra.kind ?? ra.data_type ?? null) || '—',
								});
							}
						});
					} else if (Array.isArray(st.attrs)) {
						st.attrs.forEach((a) => {
							const raname = String(a).trim();
							if (raname && !skip.has(`${sname}_${raname}`) && !skip.has(raname)) {
								const rawKey = `${sname}_${raname}`;
								const label = this.corpusStatsDisplayLabelOnly(rawKey) || this.corpusStatsDisplayLabelOnly(raname);
								if (!label && !this.isAdmin) return;
								attrs.push({
									raw: rawKey,
									display: label || '(raw only)',
									vocab: '—',
									type: this.corpusStatsSemanticType(rawKey, null) || '—',
								});
							}
						});
					}
					const meta = [];
					if (st.regions != null && Number.isFinite(Number(st.regions))) meta.push(`n=${Number(st.regions).toLocaleString()}`);
					const shownRegion = this.corpusStatsRegionHeading(sname) || sname;
					if (!shownRegion) return;
					const regionInfo = {
						count: st.regions ?? st.count ?? st.n ?? null,
						token_count: st.tokens ?? st.token_count ?? st.region_tokens ?? null,
						type: st.type ?? st.region_type ?? st.kind ?? null,
						overlap: st.overlap ?? st.overlapping ?? null,
						discontinuous: st.discontinuous ?? st.is_discontinuous ?? null,
					};
					pushRegionWithAttrs(sname, shownRegion, attrs, meta, regionInfo);
				});
			} else {
				const by = r.sattributes_by_region;
				if (by && typeof by === 'object' && !Array.isArray(by)) {
					Object.keys(by)
						.sort()
						.forEach((region) => {
							const attrs = by[region];
							if (attrs && typeof attrs === 'object' && !Array.isArray(attrs)) {
								const names = Object.keys(attrs)
									.filter((k) => !String(k).startsWith('_') && !skip.has(`${region}_${k}`) && !skip.has(k))
									.sort();
								const shown = names
									.map((k) => {
										const rawKey = `${region}_${k}`;
										const label = this.corpusStatsDisplayLabelOnly(rawKey) || this.corpusStatsDisplayLabelOnly(k);
										if (!label && !this.isAdmin) return null;
										return {
											raw: rawKey,
											display: label || '(raw only)',
											vocab: '—',
											type: this.corpusStatsSemanticType(rawKey, null) || '—',
										};
									})
									.filter(Boolean);
								const shownRegion = this.corpusStatsRegionHeading(region) || region;
								if (!shownRegion) return;
								pushRegionWithAttrs(region, shownRegion, shown);
							} else if (Array.isArray(attrs) && attrs.length) {
								const names = attrs
									.map(String)
									.filter((k) => k && !skip.has(`${region}_${k}`) && !skip.has(k));
								const shown = names
									.map((k) => {
										const rawKey = `${region}_${k}`;
										const label = this.corpusStatsDisplayLabelOnly(rawKey) || this.corpusStatsDisplayLabelOnly(k);
										if (!label && !this.isAdmin) return null;
										return {
											raw: rawKey,
											display: label || '(raw only)',
											vocab: '—',
											type: this.corpusStatsSemanticType(rawKey, null) || '—',
										};
									})
									.filter(Boolean);
								const shownRegion = this.corpusStatsRegionHeading(region) || region;
								if (!shownRegion) return;
								pushRegionWithAttrs(region, shownRegion, shown);
							} else {
								const shownRegion = this.corpusStatsRegionHeading(region) || region;
								if (!shownRegion) return;
								pushRegionWithAttrs(region, shownRegion, []);
							}
						});
				}
			}
			groups.sort((a, b) => String(a.region).localeCompare(String(b.region)));
			return groups.slice(0, 20);
		},
		corpusStatsRegionLines() {
			// Backwards-compatible flattened view.
			const groups = this.corpusStatsRegionGroups();
			const lines = [];
			groups.forEach((g) => {
				const meta = Array.isArray(g.meta) && g.meta.length ? ` (${g.meta.join('; ')})` : '';
				lines.push(`${g.region}${meta}`);
				if (Array.isArray(g.attrs) && g.attrs.length) {
					const attrNames = g.attrs.map((a) => (a && typeof a === 'object' ? (a.display || a.raw || '') : String(a))).filter(Boolean);
					if (attrNames.length) lines.push(`Attributes: ${attrNames.join(', ')}`);
				}
			});
			return lines;
		},
	};
};
