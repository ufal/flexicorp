// multiedit: change many tokens from a query, after looking at them (multiedit.php; dev/MULTIEDIT-DESIGN.md).
// 1 the query and what to change; 2 the overview: how the current values are spread, for the target and every
// other label (each value narrows the query); 3 the matches page by page, values and context from the XML;
// ticked matches go into the changes (only what has been on screen); 4 the changes are made in the background,
// with what was done per change, and an undo.
var ME = (function () {
	var cfg, app, ui = {}, st = { set: null, from: 0, size: 100, sort: '', rows: [], ticked: {}, lines: false, poll: null };

	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') e.textContent = attrs[k];
			else if (k === 'on') Object.keys(attrs.on).forEach(function (ev) { e.addEventListener(ev, attrs.on[ev]); });
			else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) e.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
		});
		(kids || []).forEach(function (k) { if (k !== null && k !== undefined) e.appendChild(typeof k === 'string' ? document.createTextNode(k) : k); });
		return e;
	}
	function num(n) { return Number(n).toLocaleString(); }
	function msg(text, kind) { ui.msg.textContent = text || ''; ui.msg.className = 'me-msg' + (kind ? ' me-' + kind : ''); }
	function get(act, params) {
		var q = Object.keys(params || {}).map(function (k) {
			var v = params[k];
			return Array.isArray(v) ? v.map(function (x) { return encodeURIComponent(k + '[]') + '=' + encodeURIComponent(x); }).join('&')
				: encodeURIComponent(k) + '=' + encodeURIComponent(v);
		}).join('&');
		return fetch(cfg.base + '&act=' + act + (q ? '&' + q : ''), { credentials: 'same-origin' }).then(answer);
	}
	function post(act, body) {
		return fetch(cfg.base + '&act=' + act, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify(body || {}) }).then(answer);
	}
	function answer(r) {
		return r.text().then(function (t) {
			var d; try { d = JSON.parse(t); } catch (e) {
				if (r.status === 403 || /name=["']?(login|password)/i.test(t)) throw new Error('Your login has ended: log in again (in another tab).');
				var text = t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
				throw new Error('The server did not answer as expected (' + r.status + '): ' + text.slice(0, 300));
			}
			if (d.error) throw new Error(d.error);
			return d;
		});
	}
	function fail(e) { msg(String(e.message || e), 'error'); }

	function init(c) {
		cfg = c;
		app = document.getElementById('me-app');
		['define', 'msg', 'overview', 'list', 'draft', 'job', 'jobs'].forEach(function (k) { ui[k] = app.querySelector('.me-' + k); });
		define();
		draft();
		jobs();
		if (cfg.query) list();
	}

	// ---- 1 the query and what to change

	function define() {
		var keys = Object.keys(cfg.fields);
		var checked = st.fields || (keys.indexOf('upos') >= 0 ? ['upos'] : keys.slice(0, 1));
		ui.define.innerHTML = '';
		ui.q = el('textarea', { rows: 2, class: 'me-query', spellcheck: 'false' });
		ui.q.value = cfg.query;
		ui.q.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); list(); } });
		var boxes = keys.map(function (k) {
			return el('label', { class: 'me-field' }, [el('input', { type: 'checkbox', value: k, checked: checked.indexOf(k) >= 0 }), ' ' + cfg.fields[k]]);
		});
		ui.define.appendChild(el('div', { class: 'me-row' }, [el('label', { class: 'me-qlabel', text: 'Query' }), ui.q]));
		ui.define.appendChild(el('div', { class: 'me-row' }, [el('span', { class: 'me-qlabel', text: 'To change' })].concat(boxes)));
		ui.define.appendChild(el('div', { class: 'me-row' }, [el('span', { class: 'me-qlabel' }),
			el('button', { type: 'button', class: 'me-main', text: 'Show the matches', on: { click: list } })]));
	}

	function chosen() {
		return Array.prototype.map.call(ui.define.querySelectorAll('.me-field input:checked'), function (i) { return i.value; });
	}

	// ---- 2 the overview

	// fromChoice: the token to edit (and to attach to) was just chosen; otherwise a new query chooses again
	function list(fromChoice) {
		if (fromChoice !== true) { st.target = ''; st.head = ''; }
		cfg.query = ui.q.value.trim();
		st.fields = chosen();
		if (!cfg.query) return msg('Give a query.', 'error');
		if (!st.fields.length) return msg('Choose what to change.', 'error');
		msg('Looking for the matches …', 'info');
		ui.overview.innerHTML = ''; ui.list.innerHTML = '';
		get('list', { query: cfg.query, fields: st.fields, target: st.target || '', head: st.head || '' }).then(function (d) {
			if (d.choose) return choose(d.choose);
			st.set = d; st.from = 0; st.sort = ''; st.ticked = {};
			msg('');
			overview();
			page();
		}).catch(fail);
	}

	// several labelled tokens: which one to edit, and (optionally) which one to attach the ticked ones to
	function choose(labels) {
		msg('');
		var box = ui.overview;
		box.innerHTML = '';
		var name = 'me-target-' + Math.random().toString(36).slice(2), hname = name + '-h';
		var targets = labels.map(function (l, i) {
			return el('label', { class: 'me-field' }, [el('input', { type: 'radio', name: name, value: l, checked: i === 0 }), ' ', el('code', { text: l })]);
		});
		var heads = [el('label', { class: 'me-field' }, [el('input', { type: 'radio', name: hname, value: '', checked: true }), ' no'])].concat(labels.map(function (l) {
			return el('label', { class: 'me-field' }, [el('input', { type: 'radio', name: hname, value: l }), ' ', el('code', { text: l })]);
		}));
		box.appendChild(el('div', { class: 'me-row' }, [el('span', { class: 'me-qlabel', text: 'Token to edit' })].concat(targets)));
		box.appendChild(el('div', { class: 'me-row' }, [el('span', { class: 'me-qlabel', text: 'Attach to' })].concat(heads)));
		box.appendChild(el('div', { class: 'me-row' }, [el('span', { class: 'me-qlabel' }), el('button', { type: 'button', class: 'me-main', text: 'Show the matches', on: { click: function () {
			var t = box.querySelector('input[name="' + name + '"]:checked'), h = box.querySelector('input[name="' + hname + '"]:checked');
			st.target = t ? t.value : '';
			st.head = h ? h.value : '';
			if (st.head && st.head === st.target) return msg('The token to attach to must be another one than the token to edit.', 'error');
			list(true);
		} } })]));
		box.appendChild(el('p', { class: 'me-hint', text: 'The query labels several tokens: choose the one to change. "Attach to": a reattach (its head becomes that token); otherwise the other labels only group and sort.' }));
	}

	function narrow(q, label, field, value) {
		var cond = label + '.' + field + ' = "' + String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"';
		return /::/.test(q.replace(/"(?:[^"\\]|\\.)*"/g, '')) ? q + ' & ' + cond : q + ' :: ' + cond;
	}

	function overview() {
		var d = st.set, box = ui.overview;
		box.innerHTML = '';
		box.appendChild(el('p', { class: 'me-total' }, [el('b', { text: num(d.total) }), d.total === 1 ? ' match' : ' matches',
			' for ', el('code', { text: d.query })]));
		// the target's fields first, then the other labels'; a field and its values
		var tp = d.target + '.';
		var keys = Object.keys(d.overview).sort(function (a, b) {
			var ea = a.indexOf(tp) === 0, eb = b.indexOf(tp) === 0;
			return ea !== eb ? (ea ? -1 : 1) : (a === tp + 'form' ? 1 : b === tp + 'form' ? -1 : 0);
		});
		keys.forEach(function (k) {
			var dot = k.indexOf('.'), label = k.slice(0, dot), field = k.slice(dot + 1), vals = d.overview[k], names = Object.keys(vals);
			var shown = names.slice(0, 30);
			var chips = shown.map(function (v) {
				return el('a', { href: '#', class: 'me-chip', title: 'only the matches where ' + k + ' is "' + v + '"', on: { click: function (e) {
					e.preventDefault(); ui.q.value = narrow(d.query, label, field, v); list();
				} } }, [v === '' ? '(empty)' : v, el('span', { class: 'me-n', text: ' ' + num(vals[v]) })]);
			});
			if (names.length > shown.length) chips.push(el('span', { class: 'me-more', text: '… ' + (names.length - shown.length) + ' more values' }));
			var sortBtn = el('a', { href: '#', class: 'me-sortby', text: 'sort by this', on: { click: function (e) { e.preventDefault(); st.sort = k; st.from = 0; page(); } } });
			box.appendChild(el('div', { class: 'me-ov' }, [el('span', { class: 'me-ovkey' }, [el('b', { text: label }), ' · ' + (cfg.fields[field] || field)]),
				el('span', { class: 'me-chips' }, chips), sortBtn]));
		});
		box.appendChild(el('p', { class: 'me-hint', text: 'Values as the search index has them. A value narrows the query to it; the list below shows what the files say now.' }));
	}

	// ---- 3 the matches

	function page() {
		ui.list.innerHTML = '';
		msg('Reading the matches …', 'info');
		// with a token to attach to, the current head and relation are needed for the old values, shown or not
		var fields = hasHead() ? st.fields.concat(['head', 'deprel'].filter(function (f) { return st.fields.indexOf(f) < 0 && cfg.fields[f]; })) : st.fields;
		get('page', { set: st.set.set, from: st.from, size: st.size, sort: st.sort, fields: fields }).then(function (d) {
			msg('');
			st.rows = d.rows; st.ticked = {};
			table();
		}).catch(fail);
	}

	function hasHead() { return !!st.set.head; }

	function table() {
		var box = ui.list, total = st.set.total, to = Math.min(total, st.from + st.size);
		box.innerHTML = '';
		// paging, page size, sorting
		var sizes = el('select', { on: { change: function () { st.size = parseInt(this.value, 10); st.from = 0; page(); } } },
			[100, 250, 500, 1000].map(function (n) { return el('option', { value: n, selected: n === st.size, text: n + ' a page' }); }));
		var sorts = el('select', { on: { change: function () { st.sort = this.value; st.from = 0; page(); } } },
			[el('option', { value: '', text: 'in corpus order' })].concat(st.set.sortkeys.map(function (k) {
				return el('option', { value: k, selected: k === st.sort, text: 'by ' + k });
			})));
		var nav = el('div', { class: 'me-nav' }, [
			el('button', { type: 'button', text: '‹', disabled: st.from === 0, on: { click: function () { st.from = Math.max(0, st.from - st.size); page(); } } }),
			el('span', { text: ' ' + num(st.from + 1) + '–' + num(to) + ' of ' + num(total) + ' ' }),
			el('button', { type: 'button', text: '›', disabled: to >= total, on: { click: function () { st.from += st.size; page(); } } }),
			sizes, sorts]);
		box.appendChild(nav);
		box.appendChild(actions());

		var head = [el('th'), el('th', { text: 'document' }), el('th', { class: 'me-l', text: 'before' }), el('th', { text: 'token' }), el('th', { text: 'after' })];
		st.fields.forEach(function (f) { head.push(el('th', { text: cfg.fields[f] || f })); });
		if (hasHead()) head.push(el('th', { text: 'attach to (' + st.set.head + ')' }));
		if (cfg.tree) head.push(el('th'));
		var tb = el('tbody');
		st.rows.forEach(function (r) { tb.appendChild(row(r)); });
		box.appendChild(el('table', { class: 'me-table' }, [el('thead', {}, [el('tr', {}, head)]), tb]));
	}

	function row(r) {
		var cells = [];
		if (!r.found) {
			return el('tr', { class: 'me-gone' }, [el('td'), el('td', { text: r.doc }), el('td', { colspan: 3 + st.fields.length + (hasHead() ? 1 : 0), text: r.problem })]);
		}
		var box = el('input', { type: 'checkbox', on: { change: function () { if (this.checked) st.ticked[r.n] = 1; else delete st.ticked[r.n]; counter(); } } });
		box.checked = !!st.ticked[r.n];
		r.box = box;
		cells.push(el('td', {}, [box]));
		cells.push(el('td', { class: 'me-doc' }, [el('a', { href: 'index.php?action=file&cid=' + encodeURIComponent(r.doc) + '&jmp=' + encodeURIComponent(r.id), target: 'check', text: r.doc.replace(/^xmlfiles\//, '').replace(/\.xml$/, '') })]));
		cells.push(el('td', { class: 'me-l', text: r.left }));
		cells.push(el('td', { class: 'me-t', title: r.in ? 'part of ' + r.in : r.id }, [el('b', { text: r.form })]));
		cells.push(el('td', { class: 'me-r', text: r.right }));
		r.inputs = {};
		st.fields.forEach(function (f) {
			var v = r.values[f] || '';
			if (st.lines) {
				var inp = el('input', { class: 'me-val', value: v, size: Math.max(6, Math.min(24, v.length + 2)), 'data-field': f });
				r.inputs[f] = inp;
				cells.push(el('td', {}, [inp]));
			} else {
				cells.push(el('td', { class: 'me-cur', text: f === 'head' && r.headform ? v + ' (' + r.headform + ')' : v }));
			}
		});
		if (hasHead()) cells.push(el('td', { class: 'me-newhead', text: r.newhead ? r.newheadform + ' (' + r.newhead + ')' : '' }));
		if (cfg.tree) cells.push(el('td', {}, [r.sid ? el('a', { href: 'index.php?action=deptree-edit&cid=' + encodeURIComponent(r.doc) + '&sid=' + encodeURIComponent(r.sid), target: 'tree', title: 'the sentence in the tree editor', text: 'tree' }) : null]));
		return el('tr', {}, cells);
	}

	function counter() {
		var n = Object.keys(st.ticked).length;
		app.querySelectorAll('.me-ticked').forEach(function (s) { s.textContent = n + ' ticked'; });
	}

	function tick(test) {
		st.rows.forEach(function (r) {
			if (!r.found) return;
			var on = test(r);
			r.box.checked = on;
			if (on) st.ticked[r.n] = 1; else delete st.ticked[r.n];
		});
		counter();
	}

	// what to do with the ticked ones
	function actions() {
		var box = el('div', { class: 'me-actions' });
		// ticking
		var tf = el('select', {}, st.fields.map(function (f) { return el('option', { value: f, text: cfg.fields[f] || f }); }));
		var tv = el('input', { size: 10, placeholder: 'value' });
		box.appendChild(el('div', { class: 'me-row' }, [
			el('span', { class: 'me-ticked', text: '0 ticked' }), ' · tick ',
			el('a', { href: '#', text: 'all on this page', on: { click: function (e) { e.preventDefault(); tick(function () { return true; }); } } }), ' · ',
			el('a', { href: '#', text: 'none', on: { click: function (e) { e.preventDefault(); tick(function () { return false; }); } } }), ' · ',
			'where ', tf, ' is ', tv, ' ',
			el('button', { type: 'button', text: 'tick', on: { click: function () { var f = tf.value, v = tv.value; tick(function (r) { return (r.values[f] || '') === v; }); } } })]));

		if (st.lines) {
			var row1 = [el('button', { type: 'button', class: 'me-main', text: 'Add the changed lines to the changes', on: { click: addLines } }), ' ',
				el('a', { href: '#', text: 'back to setting one value', on: { click: function (e) { e.preventDefault(); st.lines = false; table(); } } })];
			if (cfg.admin) {
				var rf = el('select', {}, st.fields.map(function (f) { return el('option', { value: f, text: cfg.fields[f] || f }); }));
				var from = el('input', { size: 8, placeholder: 'regex' }), to = el('input', { size: 8, placeholder: 'replacement' });
				row1.push(' · fill in: ', rf, ' s/', from, '/', to, '/ ', el('button', { type: 'button', text: 'apply', on: { click: function () {
					var re; try { re = new RegExp(from.value, 'g'); } catch (err) { return msg('Not a regular expression: ' + err.message, 'error'); }
					st.rows.forEach(function (r) { if (r.inputs && r.inputs[rf.value]) r.inputs[rf.value].value = r.inputs[rf.value].value.replace(re, to.value); });
				} } }));
			}
			box.appendChild(el('div', { class: 'me-row' }, row1));
			return box;
		}
		// one value per chosen field for all ticked (an empty one is left as it is, as in cqpedit)
		var setFields = st.fields.filter(function (f) { return f !== 'head'; });
		var setInputs = {};
		var setRow = [];
		setFields.forEach(function (f, i) {
			var listId = 'me-values-' + f + '-' + Math.random().toString(36).slice(2);
			var dl = el('datalist', { id: listId }, Object.keys(st.set.overview[st.set.target + '.' + f] || {}).map(function (v) { return el('option', { value: v }); }));
			setInputs[f] = el('input', { size: 12, list: listId, placeholder: 'unchanged' });
			setRow.push(i ? ' and ' : 'Set ', el('b', { text: cfg.fields[f] || f }), ' to ', setInputs[f], dl);
		});
		if (setFields.length) {
			setRow.push(' ', el('button', { type: 'button', class: 'me-main', text: 'for the ticked: add to the changes', on: { click: function () {
				var vals = {};
				setFields.forEach(function (f) { var v = setInputs[f].value; if (v !== '') vals[f] = v; });
				addValues(vals);
			} } }), ' · ');
		}
		setRow.push(el('a', { href: '#', text: 'edit per line', on: { click: function (e) { e.preventDefault(); st.lines = true; table(); } } }));
		box.appendChild(el('div', { class: 'me-row' }, setRow));
		if (hasHead() && cfg.fields.head) {
			var rel = el('input', { size: 10, placeholder: 'relation (optional)' });
			box.appendChild(el('div', { class: 'me-row' }, ['Attach the ticked to ', el('code', { text: st.set.head }), ', with relation ', rel, ' ',
				el('button', { type: 'button', class: 'me-main', text: 'add to the changes', on: { click: function () { addAttach(rel.value.trim()); } } })]));
		}
		return box;
	}

	function tickedRows() { return st.rows.filter(function (r) { return r.found && st.ticked[r.n]; }); }

	function addValues(vals) {
		var rows = tickedRows(), fields = Object.keys(vals);
		if (!rows.length) return msg('Tick the matches to change first.', 'error');
		if (!fields.length) return msg('Give a new value for at least one field.', 'error');
		var ch = [];
		rows.forEach(function (r) {
			fields.forEach(function (f) {
				if ((r.values[f] || '') !== vals[f]) ch.push({ n: r.n, form: r.form, field: f, old: r.values[f] || '', new: vals[f] });
			});
		});
		add(ch, rows.length);
	}

	function addAttach(rel) {
		var rows = tickedRows();
		if (!rows.length) return msg('Tick the matches to change first.', 'error');
		var ch = [];
		rows.forEach(function (r) {
			if (!r.newhead) return;
			if ((r.values.head || '') !== r.newhead) ch.push({ n: r.n, form: r.form, field: 'head', old: r.values.head || '', new: r.newhead });
			if (rel && (r.values.deprel || '') !== rel) ch.push({ n: r.n, form: r.form, field: 'deprel', old: r.values.deprel || '', new: rel });
		});
		add(ch, rows.length);
	}

	function addLines() {
		var ch = [];
		st.rows.forEach(function (r) {
			if (!r.found || !r.inputs) return;
			Object.keys(r.inputs).forEach(function (f) {
				var v = r.inputs[f].value;
				if (v !== (r.values[f] || '')) ch.push({ n: r.n, form: r.form, field: f, old: r.values[f] || '', new: v });
			});
		});
		add(ch, ch.length);
	}

	function add(changes, ticked) {
		if (!changes.length) return msg('Nothing to change: the ticked matches already have that.', 'info');
		post('add', { set: st.set.set, changes: changes }).then(function (d) {
			msg(changes.length + ' change' + (changes.length > 1 ? 's' : '') + ' added' + (d.problems.length ? '; not added: ' + d.problems.join('; ') : '') + '.', d.problems.length ? 'error' : 'ok');
			draftBox(d.draft);
		}).catch(fail);
	}

	// ---- 4 the changes, the job

	function draft() {
		get('draft').then(function (d) { draftBox(d.draft, d.draft && d.draft.list); }).catch(function () {});
	}

	function draftBox(d, list) {
		var box = ui.draft;
		box.innerHTML = '';
		if (!d || !d.changes) return;
		var showList = el('div', { class: 'me-draftlist' });
		box.appendChild(el('div', { class: 'me-pending' }, [
			el('b', { text: num(d.changes) + ' change' + (d.changes > 1 ? 's' : '') + ' in ' + num(d.documents) + ' document' + (d.documents > 1 ? 's' : '') }),
			' not made yet · ',
			el('a', { href: '#', text: 'show them', on: { click: function (e) {
				e.preventDefault();
				get('draft').then(function (x) { showList.innerHTML = ''; showList.appendChild(changesTable(x.draft.list)); });
			} } }), ' ',
			el('button', { type: 'button', class: 'me-main', text: 'Make the changes', on: { click: run } }), ' ',
			el('button', { type: 'button', text: 'Discard them', on: { click: function () {
				if (!confirm('Discard the ' + d.changes + ' changes not made yet?')) return;
				post('clear').then(function () { box.innerHTML = ''; msg('Discarded.', 'info'); }).catch(fail);
			} } })]));
		box.appendChild(showList);
	}

	function changesTable(list, withResult) {
		var head = ['document', 'token', 'field', 'from', 'to'].concat(withResult ? ['result'] : []);
		return el('table', { class: 'me-table me-small' }, [el('thead', {}, [el('tr', {}, head.map(function (h) { return el('th', { text: h }); }))]),
			el('tbody', {}, list.map(function (c) {
				var cells = [el('td', {}, [el('a', { href: 'index.php?action=file&cid=' + encodeURIComponent(c.doc) + '&jmp=' + encodeURIComponent(c.id), target: 'check', text: c.doc.replace(/^xmlfiles\//, '') })]),
					el('td', { text: c.form, title: c.id }), el('td', { text: c.field }), el('td', { text: c.old }), el('td', { text: c.new })];
				if (withResult) cells.push(el('td', { class: 'me-res-' + c.result, text: c.result + (c.why ? ': ' + c.why : '') }));
				return el('tr', {}, cells);
			}))]);
	}

	function run() {
		post('run').then(function (d) { ui.draft.innerHTML = ''; watch(d.job); }).catch(fail);
	}

	function watch(id) {
		clearTimeout(st.poll);
		get('job', { id: id }).then(function (j) {
			jobBox(j);
			if (j.status === 'queued' || j.status === 'running') st.poll = setTimeout(function () { watch(id); }, 2000);
			else jobs();
		}).catch(fail);
	}

	function jobBox(j) {
		var box = ui.job;
		box.innerHTML = '';
		box.appendChild(el('h2', { text: (j.undo_of ? 'Undo of ' + j.undo_of : 'Changes of ' + j.made) }));
		if (j.status === 'queued' || j.status === 'running') {
			var p = j.progress || {};
			box.appendChild(el('p', { text: j.status === 'queued' ? 'Waiting to start …' : 'Changing: ' + (p.done || 0) + ' of ' + (p.documents || '?') + ' documents …' }));
			return;
		}
		if (j.status === 'failed') box.appendChild(el('p', { class: 'me-error', text: 'Stopped: ' + (j.error || 'an error') }));
		var c = j.counts || {};
		box.appendChild(el('p', {}, [el('b', { text: num(c.applied || 0) + ' changed' }),
			c.same ? ' · ' + num(c.same) + ' already so' : '', c.skipped ? ' · ' : '', c.skipped ? el('b', { class: 'me-error', text: num(c.skipped) + ' skipped' }) : '',
			' · in ' + num(j.documents) + ' document' + (j.documents > 1 ? 's' : '')]));
		if (c.applied) box.appendChild(el('p', { class: 'me-hint', text: 'The files are changed. The search index still has the old values until it is rebuilt; this page and the document views show the files.' }));
		var skipped = (j.details || []).filter(function (d) { return d.result === 'skipped'; });
		if (skipped.length) {
			box.appendChild(el('p', { text: 'Skipped (the file is not what the page showed, or the change is not possible):' }));
			box.appendChild(changesTable(skipped, true));
		}
		var all = el('div');
		var bar = [el('a', { href: '#', text: 'all changes of this job', on: { click: function (e) { e.preventDefault(); all.innerHTML = ''; all.appendChild(changesTable(j.details || [], true)); } } })];
		if (j.may_undo) bar.push(' · ', el('button', { type: 'button', text: 'Undo this job', on: { click: function () {
			if (!confirm('Put back the old values of the ' + (c.applied || 0) + ' changes of this job? Values changed again since are left as they are.')) return;
			post('undo', { id: j.id }).then(function (d) { watch(d.job); }).catch(fail);
		} } }));
		if (j.undone_by) bar.push(' · undone by ' + j.undone_by);
		box.appendChild(el('p', {}, bar));
		box.appendChild(all);
	}

	function jobs() {
		get('jobs').then(function (d) {
			ui.jobs.innerHTML = '';
			if (!d.jobs.length) { ui.jobs.appendChild(el('p', { class: 'me-hint', text: 'None yet.' })); return; }
			ui.jobs.appendChild(el('table', { class: 'me-table me-small' }, [el('thead', {}, [el('tr', {}, ['when', 'who', 'query', 'changes', ''].map(function (h) { return el('th', { text: h }); }))]),
				el('tbody', {}, d.jobs.map(function (j) {
					var c = j.counts || {};
					var what = j.status === 'done' ? (c.applied || 0) + ' changed' + (c.skipped ? ', ' + c.skipped + ' skipped' : '') : j.status;
					return el('tr', {}, [el('td', { text: j.made }), el('td', { text: j.user }), el('td', {}, [el('code', { text: j.undo_of ? 'undo of ' + j.undo_of : j.query })]),
						el('td', { text: what + (j.undone_by ? ' (undone)' : '') }),
						el('td', {}, [el('a', { href: '#', text: 'show', on: { click: function (e) { e.preventDefault(); watch(j.id); ui.job.scrollIntoView(); } } })])]);
				}))]));
		}).catch(function () {});
	}

	return { init: init };
})();
