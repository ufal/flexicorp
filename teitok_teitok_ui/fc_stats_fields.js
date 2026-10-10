/**
 * flexicorp TEITOK UI: Stats: frequency field selection and collocation measures.
 * Methods of the flexicorpRoot Alpine component, mixed in by flexicorpFreqsExtend() (flexicorp_freqs.js)
 * through window.ttFlexicorpFreqsParts; `this` is the component.
 */
window.ttFlexicorpFreqsParts = window.ttFlexicorpFreqsParts || {};
window.ttFlexicorpFreqsParts.stats_fields = function () {
	return {
		frequencyUseRadioFields() {
			const qe = String((this.settings && this.settings.queryEngine) || '').toLowerCase();
			const backend = String((this.settings && this.settings.backend) || '').toLowerCase();
			return qe === 'cqp' || qe.includes('cqp') || backend === 'cqp';
		},

		isFrequencyFieldSelected(key) {
			const k = String(key || '').trim();
			if (!k || !this.frequency) return false;
			if (this.frequencyUseRadioFields()) {
				const single = String(this.frequency.field || '').trim();
				if (single) return single === k;
			}
			const arr = Array.isArray(this.frequency.formFields) ? this.frequency.formFields : [];
			return arr.map((x) => String(x).trim()).includes(k);
		},

		onFrequencyFieldInputChange(key, checked) {
			const k = String(key || '').trim();
			if (!k || !this.frequency) return;
			if (this.frequencyUseRadioFields()) {
				if (checked) {
					this.frequency.field = k;
					this.frequency.formFields = [k];
				}
				return;
			}
			const cur = Array.isArray(this.frequency.formFields) ? this.frequency.formFields : [];
			const next = cur.map((x) => String(x).trim()).filter(Boolean);
			const idx = next.indexOf(k);
			if (checked && idx === -1) next.push(k);
			if (!checked && idx !== -1) next.splice(idx, 1);
			this.frequency.formFields = next;
			if (next.length) this.frequency.field = next[0];
		},

		/**
		 * Default frequency.formFields from server-rendered field list (data-field-keys).
		 * CQP uses single selection (radio); others keep multi-select checkboxes.
		 *
		 * Guard: if the frequency operation has not actually been run yet, ignore any
		 * server-supplied default field/formFields and start the form unchecked. This
		 * prevents the "first checkbox auto-ticked" regression where a backend default
		 * (e.g. 'lemma') leaks into `state.frequency.formFields` and is rendered as a
		 * pre-selection even though the user has never opened the form before.
		 */
		initFrequencyFieldCheckboxes(fieldsetEl) {
			if (!fieldsetEl || !this.frequency) return;
			const fns = typeof window !== 'undefined' && window.ttFlexicorpFns && typeof window.ttFlexicorpFns === 'object'
				? window.ttFlexicorpFns
				: {};
			const keys = typeof fns.frequencySelectableFieldKeys === 'function'
				? fns.frequencySelectableFieldKeys(fieldsetEl.parentElement || fieldsetEl)
				: (() => {
					const raw = fieldsetEl.getAttribute('data-field-keys');
					if (!raw) return [];
					try { return JSON.parse(raw); } catch (_) { return []; }
				})();
			if (!Array.isArray(keys) || !keys.length) return;
			const allow = new Set(keys.map((k) => String(k).trim()).filter(Boolean));
			const ran = !!(this.frequency && this.frequency.ran);
			const current = ran && Array.isArray(this.frequency.formFields)
				? this.frequency.formFields.map((k) => String(k).trim()).filter((k) => k && allow.has(k))
				: [];
			if (this.frequencyUseRadioFields()) {
				const pick = current.length ? current[0] : '';
				this.frequency.formFields = pick ? [pick] : [];
				this.frequency.field = pick || '';
				return;
			}
			if (current.length) {
				this.frequency.formFields = current;
				this.frequency.field = current[0];
				return;
			}
			this.frequency.formFields = [];
			this.frequency.field = '';
		},

		frequencyFieldSelectionOk() {
			if (!this.frequency) return false;
			if (this.frequencyUseRadioFields()) {
				return String(this.frequency.field || '').trim() !== '';
			}
			return Array.isArray(this.frequency.formFields) && this.frequency.formFields.length > 0;
		},

		frequencyChartLibraryAvailable() {
			return typeof window !== 'undefined' && typeof window.Chart === 'function';
		},

		/** Canonical Pando measure id order (primary = first in list among selected). */
		collocationMeasureOrder() {
			return ['logdice', 'mi', 'mi3', 'tscore', 'll', 'dice'];
		},

		normalizeCollocationMeasureKeys(keys) {
			const ORDER = this.collocationMeasureOrder();
			const arr = Array.isArray(keys) ? keys.filter(Boolean) : [];
			const set = new Set(arr);
			const out = ORDER.filter((id) => set.has(id));
			return out.length ? out : ['logdice'];
		},

		/** Keep checkbox group non-empty (Alpine multi-checkbox) and canonical order. */
		syncCollocationMeasureKeysAfterChange() {
			if (!this.collocation) return;
			const next = this.normalizeCollocationMeasureKeys(this.collocation.measureKeys);
			this.collocation.measureKeys = next;
		},

		onCollocationMeasureSelectionChanged() {
			this.syncCollocationMeasureKeysAfterChange();
			// If collocations are already shown, changing measures means we need fresh
			// backend data for those metrics; do not fake columns from stale rows.
			if (!this.collocation || !this.collocation.ran) return;
			if (!this.search || !this.search.ran || (typeof this.statsSearchHasHits === 'function' && !this.statsSearchHasHits())) return;
			if (typeof this.isLoading === 'function' && this.isLoading('collocation')) return;
			if (this._collocationMeasureRerunTimer) {
				clearTimeout(this._collocationMeasureRerunTimer);
				this._collocationMeasureRerunTimer = null;
			}
			this._collocationMeasureRerunTimer = setTimeout(() => {
				this._collocationMeasureRerunTimer = null;
				try {
					this.submitCollocationFromButton();
				} catch (_) {
					/* ignore */
				}
			}, 250);
		},
	};
};
