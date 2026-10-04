function flexicorpFqsExtend() {
	const SYNTHETIC_JOB_TTL_MS = 20000;
	return {
		/** FQS probe snapshot from flexicorp.php. */
		fqs: null,
		/** Active queued/running reindex jobs as seen by flexicorp.php startup probe. */
		fqsActiveReindex: null,
		/** Prevent re-injecting the same optimistic enqueue forever. */
		_fqsOptimisticPromoted: {},
		/** Latest reindex feedback payload from server state (FQS-sourced when available). */
		_fqsLastFeedback: null,
		/** Short-lived confirmation poll after enqueue to replace optimistic state with live FQS data. */
		_fqsConfirmPollTimerId: null,
		_fqsConfirmPollDeadlineMs: 0,
		_fqsConfirmPollInFlight: false,
		_fqsConfirmPollJobId: '',

		applyFqsState(state) {
			if (!state || typeof state !== 'object') return;
			if (Object.prototype.hasOwnProperty.call(state, 'fqs')) {
				this.fqs = state.fqs && typeof state.fqs === 'object' ? state.fqs : null;
			}
			if (Object.prototype.hasOwnProperty.call(state, 'fqsActiveReindex')) {
				this.fqsActiveReindex =
					state.fqsActiveReindex && typeof state.fqsActiveReindex === 'object' ? state.fqsActiveReindex : null;
			}
			this.captureLatestReindexFeedback(state);
			this.reconcileSyntheticWithFeedback();
			this.pruneSyntheticFqsJobs();
			this.promoteCurrentReindexIntoFqsActiveSnapshot(state);
		},

		isFqsAvailable() {
			const f = this.fqs;
			return !!(f && typeof f === 'object' && f.http_running);
		},

		fqsActiveJobs() {
			this.pruneSyntheticFqsJobs();
			const active = this.fqsActiveReindex;
			if (!active || typeof active !== 'object' || !active.ok || !Array.isArray(active.jobs)) return [];
			return active.jobs;
		},

		pruneSyntheticFqsJobs() {
			const active = this.fqsActiveReindex;
			if (!active || typeof active !== 'object' || !Array.isArray(active.jobs)) return;
			const now = Date.now();
			const kept = active.jobs.filter((job) => {
				if (!job || typeof job !== 'object') return false;
				if (!job.__synthetic) return true;
				const createdMs = Number(job.__created_at_ms || 0);
				return Number.isFinite(createdMs) && createdMs > 0 && now - createdMs <= SYNTHETIC_JOB_TTL_MS;
			});
			active.jobs = kept;
			const activeBackends = [];
			for (const job of kept) {
				const backends = Array.isArray(job && job.requested_backends) ? job.requested_backends : [];
				for (const backend of backends) {
					const b = String(backend || '').trim();
					if (b && !activeBackends.includes(b)) activeBackends.push(b);
				}
			}
			active.active_backends = activeBackends;
		},

		currentCorpusId() {
			const f = this.fqs;
			const cid = f && typeof f === 'object' && f.corpus_id != null ? String(f.corpus_id) : '';
			return cid.trim();
		},

		fqsJobCorpusId(job) {
			if (!job || typeof job !== 'object') return '';
			if (job.corpus_id != null && String(job.corpus_id).trim() !== '') return String(job.corpus_id).trim();
			const req = job.request && typeof job.request === 'object' ? job.request : null;
			if (req && req.corpus != null && String(req.corpus).trim() !== '') return String(req.corpus).trim();
			return '';
		},

		fqsJobProgress(job) {
			if (!job || typeof job !== 'object') return null;
			const result = job.result && typeof job.result === 'object' ? job.result : null;
			const progress = result && result.progress && typeof result.progress === 'object' ? result.progress : null;
			if (!progress) return null;
			const percentRaw = progress.percent;
			const percentNum =
				percentRaw === '' || percentRaw == null ? null : Number(percentRaw);
			const percent = Number.isFinite(percentNum) ? Math.max(0, Math.min(100, Math.round(percentNum))) : null;
			const phase = String(progress.phase || '').trim();
			const message = String(progress.message || '').trim();
			if (percent === null && !phase && !message) return null;
			return { percent, phase, message };
		},

		fqsProgressTextFromJobs(jobs) {
			if (!Array.isArray(jobs) || !jobs.length) return '';
			const running = jobs.filter((job) => {
				const st = String((job && job.status) || '').toLowerCase();
				return st === 'running' || st === 'started';
			});
			if (!running.length) return '';
			const withProgress = running
				.map((job) => ({ job, progress: this.fqsJobProgress(job) }))
				.filter((item) => !!item.progress);
			if (!withProgress.length) return '';
			const sorted = withProgress.sort((a, b) => {
				const au = String((a.job && (a.job.updated_at || a.job.started_at || a.job.requested_at)) || '');
				const bu = String((b.job && (b.job.updated_at || b.job.started_at || b.job.requested_at)) || '');
				return bu.localeCompare(au);
			});
			const primary = sorted[0];
			const p = primary.progress;
			const parts = [];
			if (p.percent !== null) parts.push(`${p.percent}%`);
			if (p.phase) parts.push(p.phase);
			if (p.message && p.message.toLowerCase() !== String(p.phase || '').toLowerCase()) parts.push(p.message);
			let txt = parts.length ? `progress: ${parts.join(' · ')}` : '';
			if (sorted.length > 1) txt += `${txt ? ' · ' : ''}+${sorted.length - 1} more`;
			return txt;
		},

		captureLatestReindexFeedback(state) {
			const result =
				state &&
				state.reindex &&
				typeof state.reindex === 'object' &&
				state.reindex.result &&
				typeof state.reindex.result === 'object'
					? state.reindex.result
					: null;
			if (!result) return;
			if (String(result.source || '').toLowerCase() !== 'fqs') return;
			const corpus = this.currentCorpusId();
			this._fqsLastFeedback = {
				jobId: String(result.fqs_job_id || result.job_id || '').trim(),
				status: String(result.status || '').trim().toLowerCase(),
				backends: Array.isArray(result.reindex_backends)
					? result.reindex_backends.map((b) => String(b || '').trim()).filter(Boolean)
					: [],
				corpus,
				createdAt: result.created_ts != null ? String(result.created_ts) : '',
				updatedAt: result.updated_ts != null ? String(result.updated_ts) : '',
				finishedAt: result.finished_ts != null ? String(result.finished_ts) : '',
			};
		},

		onFqsReindexFeedback(result) {
			if (!result || typeof result !== 'object') return;
			if (String(result.source || '').toLowerCase() !== 'fqs') return;
			const corpus = this.currentCorpusId();
			this._fqsLastFeedback = {
				jobId: String(result.fqs_job_id || result.job_id || '').trim(),
				status: String(result.status || '').trim().toLowerCase(),
				backends: Array.isArray(result.reindex_backends)
					? result.reindex_backends.map((b) => String(b || '').trim()).filter(Boolean)
					: [],
				corpus,
				createdAt: result.created_ts != null ? String(result.created_ts) : '',
				updatedAt: result.updated_ts != null ? String(result.updated_ts) : '',
				finishedAt: result.finished_ts != null ? String(result.finished_ts) : '',
			};
			this.reconcileSyntheticWithFeedback();
			this.pruneSyntheticFqsJobs();
		},

		fqsFeedbackWhenText(fb) {
			if (!fb || typeof fb !== 'object') return '';
			const raw = String(fb.finishedAt || fb.updatedAt || fb.createdAt || '').trim();
			if (!raw) return '';
			// Keep server-formatted timestamp (typically YYYY-MM-DD HH:MM:SS) for consistency.
			return raw;
		},

		fqsTerminalFeedbackText(fb, isFailure) {
			if (!fb || typeof fb !== 'object') return 'FQS: no active processes';
			const parts = ['FQS: no active processes'];
			const action = isFailure ? 'last failed' : 'last completed';
			const when = this.fqsFeedbackWhenText(fb);
			const corpus = String(fb.corpus || '').trim();
			const backends = Array.isArray(fb.backends) ? fb.backends.filter(Boolean) : [];
			const details = [];
			if (when) details.push(when);
			if (corpus) details.push(`corpus: ${corpus}`);
			if (backends.length) details.push(`backends: ${backends.join(', ')}`);
			if (this.debugMode && fb.jobId) details.push(`job: ${fb.jobId}`);
			if (details.length) {
				parts.push(`(${action}: ${details.join(' · ')})`);
			}
			return parts.join(' ');
		},

		fqsVersionSummary() {
			const f = this.fqs;
			if (!f || typeof f !== 'object') return '';
			const cli = String(f.cli_version || '').trim();
			const server = String(f.server_version || '').trim();
			if (!cli && !server) return '';
			if (cli && server && cli !== server) return `version mismatch (server ${server}, cli ${cli})`;
			if (server) return `version ${server}`;
			return `cli ${cli}`;
		},

		reconcileSyntheticWithFeedback() {
			const active = this.fqsActiveReindex;
			if (!active || typeof active !== 'object' || !Array.isArray(active.jobs)) return;
			const fb = this._fqsLastFeedback;
			if (!fb || !fb.jobId) return;
			if (!['done', 'completed', 'failed', 'error'].includes(fb.status)) return;
			active.jobs = active.jobs.filter((job) => !(job && job.__synthetic && String(job.job_id || '').trim() === fb.jobId));
			this.stopFqsConfirmationPolling();
		},

		promoteCurrentReindexIntoFqsActiveSnapshot(state) {
			const reindexResult =
				state &&
				state.reindex &&
				typeof state.reindex === 'object' &&
				state.reindex.result &&
				typeof state.reindex.result === 'object'
					? state.reindex.result
					: null;
			if (!reindexResult || String(reindexResult.source || '').toLowerCase() !== 'fqs') return;
			const jobId = String(reindexResult.fqs_job_id || reindexResult.job_id || '').trim();
			if (!jobId) return;
			const status = String(reindexResult.status || '').trim().toLowerCase();
			if (['done', 'completed', 'failed', 'error'].includes(status)) return;
			if (this._fqsOptimisticPromoted && this._fqsOptimisticPromoted[jobId]) return;
			const corpusId = this.currentCorpusId();
			if (!this.fqsActiveReindex || typeof this.fqsActiveReindex !== 'object') {
				this.fqsActiveReindex = { ok: true, jobs: [], active_backends: [], error: '' };
			}
			if (!Array.isArray(this.fqsActiveReindex.jobs)) this.fqsActiveReindex.jobs = [];
			const existing = this.fqsActiveReindex.jobs.some((j) => j && String(j.job_id || '').trim() === jobId);
			if (existing) return;
			const requestedBackends = Array.isArray(reindexResult.reindex_backends) ? reindexResult.reindex_backends : [];
			const synthetic = {
				job_id: jobId,
				corpus_id: corpusId,
				status: status || 'queued',
				__synthetic: true,
				__created_at_ms: Date.now(),
				requested_backends: requestedBackends,
				request: {
					corpus: corpusId,
					reindex_backends: requestedBackends,
				},
			};
			this.fqsActiveReindex.jobs.unshift(synthetic);
			const activeBackends = Array.isArray(this.fqsActiveReindex.active_backends)
				? this.fqsActiveReindex.active_backends.slice()
				: [];
			for (const backend of requestedBackends) {
				const b = String(backend || '').trim();
				if (b && !activeBackends.includes(b)) activeBackends.push(b);
			}
			this.fqsActiveReindex.active_backends = activeBackends;
			this.fqsActiveReindex.ok = true;
			this._fqsOptimisticPromoted[jobId] = true;
			this.startFqsConfirmationPolling(jobId);
		},

		stopFqsConfirmationPolling() {
			if (this._fqsConfirmPollTimerId) {
				clearInterval(this._fqsConfirmPollTimerId);
				this._fqsConfirmPollTimerId = null;
			}
			this._fqsConfirmPollDeadlineMs = 0;
			this._fqsConfirmPollJobId = '';
		},

		startFqsConfirmationPolling(jobId) {
			const id = String(jobId || '').trim();
			if (!id) return;
			if (this._fqsConfirmPollTimerId && this._fqsConfirmPollJobId === id) return;
			this.stopFqsConfirmationPolling();
			this._fqsConfirmPollJobId = id;
			this._fqsConfirmPollDeadlineMs = Date.now() + SYNTHETIC_JOB_TTL_MS;
			const tick = async () => {
				if (Date.now() > this._fqsConfirmPollDeadlineMs) {
					this.stopFqsConfirmationPolling();
					return;
				}
				if (this._fqsConfirmPollInFlight) return;
				const fb = this._fqsLastFeedback;
				if (fb && fb.jobId === id && ['done', 'completed', 'failed', 'error'].includes(fb.status)) {
					this.stopFqsConfirmationPolling();
					return;
				}
				const hasLive = this.fqsActiveJobs().some((job) => job && !job.__synthetic && String(job.job_id || '').trim() === id);
				if (hasLive) {
					this.stopFqsConfirmationPolling();
					return;
				}
				if (typeof this.buildCommonRequestData !== 'function' || typeof this.submitAjaxData !== 'function') return;
				try {
					this._fqsConfirmPollInFlight = true;
					const formData = this.buildCommonRequestData();
					formData.set('active_tab', 'overview');
					formData.set('run', '');
					await this.submitAjaxData(formData, 'backend');
				} catch (_) {
					// ignore; next tick retries until deadline
				} finally {
					this._fqsConfirmPollInFlight = false;
				}
			};
			this._fqsConfirmPollTimerId = setInterval(() => { void tick(); }, 2500);
			void tick();
		},

		fqsStatusBarText() {
			if (!this.isFqsAvailable()) return '';
			const corpusId = this.currentCorpusId();
			const jobs = this.fqsActiveJobs().filter((job) => {
				if (!corpusId) return true;
				return this.fqsJobCorpusId(job) === corpusId;
			});
			const liveJobs = jobs.filter((job) => !(job && job.__synthetic));
			const syntheticJobs = jobs.filter((job) => !!(job && job.__synthetic));
			if (!liveJobs.length && syntheticJobs.length) {
				const vs = this.fqsVersionSummary();
				return `FQS: sent to FQS, awaiting queue confirmation...${vs ? ` · ${vs}` : ''}`;
			}
			if (liveJobs.length) this.stopFqsConfirmationPolling();
			if (!liveJobs.length) {
				const fb = this._fqsLastFeedback;
				if (fb && ['done', 'completed'].includes(fb.status)) {
					const txt = this.fqsTerminalFeedbackText(fb, false);
					const vs = this.fqsVersionSummary();
					return `${txt}${vs ? ` · ${vs}` : ''}`;
				}
				if (fb && ['failed', 'error'].includes(fb.status)) {
					const txt = this.fqsTerminalFeedbackText(fb, true);
					const vs = this.fqsVersionSummary();
					return `${txt}${vs ? ` · ${vs}` : ''}`;
				}
				const vs = this.fqsVersionSummary();
				return `FQS: no active processes${vs ? ` · ${vs}` : ''}`;
			}
			const corpusSet = new Set();
			const backendSet = new Set();
			let queuedCount = 0;
			let runningCount = 0;
			for (const job of liveJobs) {
				const corpusId = job && job.corpus_id ? String(job.corpus_id) : '';
				if (corpusId) corpusSet.add(corpusId);
				const st = String((job && job.status) || '').toLowerCase();
				if (st === 'queued') queuedCount += 1;
				if (st === 'running' || st === 'started') runningCount += 1;
				const backends = Array.isArray(job && job.requested_backends) ? job.requested_backends : [];
				for (const backend of backends) {
					const name = String(backend || '').trim();
					if (name) backendSet.add(name);
				}
			}
			const parts = [`FQS: ${liveJobs.length} active process${liveJobs.length === 1 ? '' : 'es'}`];
			if (queuedCount || runningCount) parts.push(`queued ${queuedCount}, running ${runningCount}`);
			if (corpusSet.size) parts.push(`corpus: ${Array.from(corpusSet).join(', ')}`);
			if (backendSet.size) parts.push(`backends: ${Array.from(backendSet).join(', ')}`);
			const progressTxt = this.fqsProgressTextFromJobs(liveJobs);
			if (progressTxt) parts.push(progressTxt);
			const vs = this.fqsVersionSummary();
			if (vs) parts.push(vs);
			return parts.join(' · ');
		},
	};
}

window.flexicorpFqsExtend = flexicorpFqsExtend;
