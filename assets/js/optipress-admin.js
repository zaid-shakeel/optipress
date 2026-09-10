/* OptiPress admin app — vanilla JS, no dependencies. */
(function () {
	'use strict';

	var D = window.OptiPressData || {};
	var I = D.i18n || {};

	/* ---------------- Utilities ---------------- */

	function api(action, data, method) {
		var params = new URLSearchParams();
		params.set('action', 'optipress_' + action);
		params.set('nonce', D.nonce);
		data = data || {};
		Object.keys(data).forEach(function (k) {
			var v = data[k];
			if (v && typeof v === 'object') {
				Object.keys(v).forEach(function (k2) { params.append(k + '[' + k2 + ']', v[k2]); });
			} else {
				params.append(k, v);
			}
		});
		var url = D.ajax;
		var opts = { method: method || 'POST', credentials: 'same-origin' };
		if ((method || 'POST') === 'GET') { url += '?' + params.toString(); }
		else { opts.headers = { 'Content-Type': 'application/x-www-form-urlencoded' }; opts.body = params.toString(); }
		return fetch(url, opts).then(function (r) {
			if (!r.ok) { throw new Error('HTTP ' + r.status); }
			return r.json();
		}).then(function (json) {
			if (!json.success) { throw new Error((json.data && json.data.message) || 'Request failed'); }
			return json.data;
		});
	}

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = s == null ? '' : String(s);
		return d.innerHTML;
	}

	function fmtBytes(b) {
		if (b == null || isNaN(b)) return '0 B';
		var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = 0;
		b = Number(b);
		while (b >= 1024 && i < u.length - 1) { b /= 1024; i++; }
		return (i === 0 ? b : b.toFixed(2)) + ' ' + u[i];
	}

	function fmtNum(n) { return Number(n || 0).toLocaleString(); }

	function debounce(fn, ms) {
		var t;
		return function () {
			var a = arguments, c = this;
			clearTimeout(t);
			t = setTimeout(function () { fn.apply(c, a); }, ms);
		};
	}

	/* ---------------- Toasts ---------------- */

	var toastWrap = null;
	function toast(msg, type) {
		if (!toastWrap) {
			toastWrap = document.createElement('div');
			toastWrap.id = 'op-toasts';
			document.body.appendChild(toastWrap);
		}
		var t = document.createElement('div');
		t.className = 'op-toast op-toast--' + (type || 'info');
		t.innerHTML = '<span>' + esc(msg) + '</span>';
		toastWrap.appendChild(t);
		setTimeout(function () { t.style.opacity = '0'; t.style.transition = 'opacity .4s'; }, 4200);
		setTimeout(function () { t.remove(); }, 4700);
	}

	/* ---------------- Modal ---------------- */

	var overlay = null;
	function openModal(title, bodyHTML) {
		closeModal();
		overlay = document.createElement('div');
		overlay.className = 'op-modal-overlay';
		overlay.innerHTML =
			'<div class="op-modal" role="dialog" aria-modal="true">' +
			'<div class="op-modal__head"><h2>' + esc(title) + '</h2>' +
			'<button class="op-modal__close" aria-label="Close">&times;</button></div>' +
			'<div class="op-modal__body">' + bodyHTML + '</div></div>';
		document.body.appendChild(overlay);
		overlay.addEventListener('click', function (e) {
			if (e.target === overlay || e.target.classList.contains('op-modal__close')) closeModal();
		});
		return overlay;
	}
	function closeModal() { if (overlay) { overlay.remove(); overlay = null; } }
	document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

	
	/* ---------------- Charts (responsive SVG bars) ---------------- */

	function ring(el) {
		if (!el) return;
		var pct = Math.max(0, Math.min(100, parseFloat(el.dataset.pct) || 0));
		var circ = 2 * Math.PI * 52;
		var fg = el.querySelector('.op-ring__fg');
		if (fg) {
			fg.style.strokeDasharray = circ;
			requestAnimationFrame(function () {
				fg.style.strokeDashoffset = circ * (1 - pct / 100);
			});
		}
	}

	var opCharts = [];
	window.addEventListener('resize', debounce(function () {
		opCharts.forEach(function (el) { if (el._opRender) el._opRender(); });
	}, 200));

	function niceCeil(v) {
		if (v <= 0) return 1;
		var p = Math.pow(10, Math.floor(Math.log(v) / Math.LN10));
		var f = v / p;
		var nf = f <= 1 ? 1 : (f <= 2 ? 2 : (f <= 2.5 ? 2.5 : (f <= 5 ? 5 : 10)));
		return nf * p;
	}

	/**
	 * Bar chart that handles 1..365 days gracefully.
	 * opts: {key, key2 (stacked), fmt, color, color2, label1, label2, legend, aria}
	 */
	function barChart(el, series, opts) {
		if (!el) return;
		opts = opts || {};
		el._opRender = function () { drawBarChart(el, series, opts); };
		if (opCharts.indexOf(el) === -1) opCharts.push(el);
		drawBarChart(el, series, opts);
	}

	function drawBarChart(el, series, opts) {
		var W = Math.max(560, el.clientWidth || 900);
		var H = Math.max(180, el.clientHeight || 240);
		var P = { t: 14, r: 10, b: 30, l: 62 };
		var iw = W - P.l - P.r, ih = H - P.t - P.b;
		var key = opts.key || 'saved';
		var key2 = opts.key2 || null;
		var fmt = opts.fmt || fmtBytes;

		var max = 0;
		series.forEach(function (d) {
			var v = (Number(d[key]) || 0) + (key2 ? (Number(d[key2]) || 0) : 0);
			if (v > max) max = v;
		});
		var niceMax = niceCeil(max || 1);

		function y(v) { return P.t + ih - (v / niceMax) * ih; }

		var n = series.length;
		var slot = iw / n;
		var barW = Math.max(6, Math.min(slot * 0.62, 48));
		var base = P.t + ih;

		var grid = '', labels = '', bars = '';
		for (var g = 0; g <= 4; g++) {
			var gv = niceMax / 4 * g;
			var gy = y(gv);
			grid += '<line x1="' + P.l + '" y1="' + gy + '" x2="' + (W - P.r) + '" y2="' + gy + '"/>';
			labels += '<text x="' + (P.l - 8) + '" y="' + (gy + 4) + '" text-anchor="end">' + esc(fmt(gv)) + '</text>';
		}

		var labelStep = Math.max(1, Math.ceil(n / Math.max(4, Math.floor(iw / 70))));
		series.forEach(function (d, i) {
			var v1 = Number(d[key]) || 0;
			var v2 = key2 ? (Number(d[key2]) || 0) : 0;
			var h1 = (v1 / niceMax) * ih;
			var h2 = (v2 / niceMax) * ih;
			var x = P.l + slot * i + (slot - barW) / 2;
			var y1 = base - h1;
			var y2 = y1 - h2;
			var title = esc(d.day) + ' · ' + esc(opts.label1 || key) + ': ' + esc(fmt(v1)) +
				(key2 ? ' · ' + esc(opts.label2 || key2) + ': ' + esc(fmt(v2)) : '');

			bars += '<g><title>' + title + '</title>';
			if (h2 > 0.5) {
				bars += '<rect x="' + x + '" y="' + y2 + '" width="' + barW + '" height="' + h2 + '" rx="3" fill="' + (opts.color2 || '#e8909f') + '"/>';
			}
			if (h1 > 0.5) {
				bars += '<rect x="' + x + '" y="' + y1 + '" width="' + barW + '" height="' + h1 + '" rx="3" fill="' + (opts.color || 'url(#opBarGrad)') + '"/>';
			}
			if (h1 <= 0.5 && h2 <= 0.5) {
				bars += '<rect x="' + x + '" y="' + (base - 2) + '" width="' + barW + '" height="2" rx="1" fill="#dfe5ef"/>';
			}
			bars += '</g>';

			if (i % labelStep === 0 || i === n - 1) {
				labels += '<text x="' + (x + barW / 2) + '" y="' + (H - 8) + '" text-anchor="middle">' + esc(d.day.slice(5)) + '</text>';
			}
		});

		el.innerHTML =
			'<svg width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '" role="img" aria-label="' + esc(opts.aria || 'chart') + '">' +
			'<defs><linearGradient id="opBarGrad" x1="0" y1="0" x2="0" y2="1">' +
			'<stop offset="0%" stop-color="#3056d3"/><stop offset="100%" stop-color="#1fb6b0"/></linearGradient></defs>' +
			'<g class="op-chart-grid">' + grid + '</g>' + bars + labels + '</svg>' +
			(opts.legend || '');
	}

	/* ---------------- Pill helper ---------------- */

	function pill(status, label) {
		var map = { optimized: 'Optimized', pending: 'Pending', failed: 'Failed', skipped: 'Skipped', processing: 'Processing', done: 'Done', none: '—' };
		return '<span class="op-pill op-pill--' + esc(status) + '">' + esc(label || map[status] || status) + '</span>';
	}

	/* ---------------- Bulk runner ---------------- */

		var Bulk = {
		token: null, cancelled: false, running: false, total: 0, errors: 0,
		el: {},

		init: function () {
			var root = document.getElementById('op-bulk');
			if (!root) return;
			this.el = {
				progress: document.getElementById('op-bulk-progress'),
				pct: document.getElementById('op-bulk-pct'), fraction: document.getElementById('op-bulk-fraction'),
				current: document.getElementById('op-bulk-current'), done: document.getElementById('op-bulk-done'),
				remaining: document.getElementById('op-bulk-remaining'), failed: document.getElementById('op-bulk-failed'),
				saved: document.getElementById('op-bulk-saved'), bar: document.getElementById('op-bulk-bar'),
				status: document.getElementById('op-bulk-status'), log: document.getElementById('op-bulk-log'),
				cancel: document.getElementById('op-bulk-cancel')
			};

			var self = this;
			document.querySelectorAll('[data-bulk-mode]').forEach(function (btn) {
				btn.addEventListener('click', function () {
					if (btn.hasAttribute('disabled')) return;
					self.scanThenStart(btn.dataset.bulkMode);
				});
			});
			var scanBtn = document.querySelector('[data-bulk-scan]');
			if (scanBtn) scanBtn.addEventListener('click', function () { self.scan(function () { toast('Library scan complete.', 'success'); }); });
			this.el.cancel.addEventListener('click', function () { self.cancel(); });

			api('bulk_status', {}, 'GET').then(function (r) {
				if (r.lock && r.lock.state === 'running' && r.lock.token) {
					self.total = r.counters.total;
					self.token = r.lock.token;
					self.beginUI();
					self.loop();
				}
			}).catch(function () {});
		},

		scan: function (done) {
			var self = this;
			function step() {
				api('scan').then(function (r) {
					if (r.remaining > 0) { setTimeout(step, 60); } else { done(); }
				}).catch(function (e) { toast(e.message, 'error'); });
			}
			step();
		},

		scanThenStart: function (mode) {
			var self = this;
			var btn = document.querySelector('[data-bulk-mode="' + mode + '"]');
			if (btn) { btn.disabled = true; btn.innerHTML = '<span class="op-spinner"></span> ' + esc(I.scan_found || 'Scanning…'); }
			this.scan(function () { self.start(mode); });
		},

		start: function (mode) {
			var self = this;
			api('bulk_start', { mode: mode }).then(function (r) {
				self.token = r.token;
				self.total = r.total;
				self.cancelled = false;
				self.errors = 0;
				if (r.total === 0) {
					toast('Nothing to process — the queue is empty.', 'info');
					self.refreshButtons();
					return;
				}
				self.beginUI();
				self.loop();
			}).catch(function (e) {
				toast(e.message, 'error');
				self.refreshButtons();
			});
		},

		beginUI: function () {
			this.running = true;
			this.el.progress.hidden = false;
			this.el.log.hidden = false;
			this.el.log.innerHTML = '';
			this.el.bar.classList.remove('is-done');
			this.el.cancel.disabled = false;
			document.querySelectorAll('[data-bulk-mode]').forEach(function (b) { b.disabled = true; });
		},

		loop: function () {
			var self = this;
			if (this.cancelled) return;
			api('bulk_run', { token: this.token }).then(function (r) {
				self.errors = 0;
				self.render(r);
				(r.batch || []).forEach(function (b) { self.logLine(b); });
				if (r.finished) { self.finish(r); }
				else { setTimeout(function () { self.loop(); }, 60); }
			}).catch(function () {
				if (self.cancelled) return;
				self.errors++;
				if (self.errors >= 3) {
					// Stop guessing: ask the server for the real queue state.
					api('bulk_status', {}, 'GET').then(function (s) {
						if (!s.lock || s.lock.state !== 'running') {
							self.finishWith(s.counters, s.lock, true);
						} else {
							self.el.status.textContent = I.network || 'Network error — retrying…';
							setTimeout(function () { if (!self.cancelled) self.loop(); }, 2500);
						}
					}).catch(function () {
						self.el.status.textContent = I.network || 'Network error — retrying…';
						setTimeout(function () { if (!self.cancelled) self.loop(); }, 4000);
					});
				} else {
					self.el.status.textContent = I.network || 'Network error — retrying…';
					setTimeout(function () { self.loop(); }, 2500);
				}
			});
		},

		render: function (r) {
			var total = Math.max(this.total, r.lock.processed + r.remaining);
			var pct = total > 0 ? (r.lock.processed / total) * 100 : 0;
			this.el.pct.textContent = pct.toFixed(1) + '%';
			this.el.fraction.textContent = fmtNum(r.lock.processed) + ' / ' + fmtNum(total);
			this.el.bar.style.width = pct + '%';
			this.el.current.textContent = r.lock.last_file || '—';
			this.el.done.textContent = fmtNum(r.lock.processed - r.lock.failed);
			this.el.remaining.textContent = fmtNum(r.remaining);
			this.el.failed.textContent = fmtNum(r.lock.failed);
			if (r.counters) this.el.saved.textContent = fmtBytes(r.counters.saved);
		},

		logLine: function (b) {
			var line = document.createElement('div');
			var ok = b.ok && !b.failed;
			line.className = ok ? 'ok' : (b.skipped ? '' : 'bad');
			line.textContent = (ok ? '✓ ' : (b.skipped ? '↷ ' : '✗ ')) + b.file + (b.message ? ' — ' + b.message : '');
			this.el.log.appendChild(line);
			this.el.log.scrollTop = this.el.log.scrollHeight;
			while (this.el.log.children.length > 200) this.el.log.removeChild(this.el.log.firstChild);
		},

		finish: function (r) {
			this.finishWith(r.counters, r.lock, false);
		},

		finishWith: function (counters, lock, stalled) {
			this.running = false;
			this.cancelled = true;
			var spinner = document.getElementById('op-bulk-spinner');
			if (spinner) spinner.style.display = 'none';
			this.el.bar.style.width = '100%';
			this.el.bar.classList.add('is-done');
			this.el.pct.textContent = '100.0%';
			this.el.remaining.textContent = '0';
			if (counters) this.el.saved.textContent = fmtBytes(counters.saved);

			var failed = lock ? parseInt(lock.failed || 0, 10) : 0;
			if (failed > 0) {
				this.el.status.textContent = 'Completed with ' + failed + ' failed image' + (failed > 1 ? 's' : '') + '. Open Logs to see the exact reasons.';
				toast('Finished with ' + failed + ' failure(s). Check Logs for details.', 'error');
			} else {
				this.el.status.textContent = stalled
					? 'Complete. (Connection dropped at the very end — the queue had already finished on the server.)'
					: 'Complete. All queued images were processed.';
				toast('Bulk operation completed successfully.', 'success');
			}
			this.el.cancel.disabled = true;
			this.refreshButtons();
		},

		cancel: function () {
			this.cancelled = true;
			var self = this;
			api('bulk_cancel', { token: this.token }).then(function () {
				self.running = false;
				var spinner = document.getElementById('op-bulk-spinner');
				if (spinner) spinner.style.display = 'none';
				self.el.bar.classList.add('is-done');
				self.el.status.textContent = 'Cancelled. Progress so far is preserved.';
				self.el.cancel.disabled = true;
				toast('Bulk operation cancelled.', 'info');
				self.refreshButtons();
			}).catch(function () {});
		},

		refreshButtons: function () {
			api('bulk_status', {}, 'GET').then(function (r) {
				document.querySelectorAll('[data-bulk-mode]').forEach(function (b) {
					var mode = b.dataset.bulkMode;
					var count = r.remaining[mode] || 0;
					b.disabled = count < 1;
				});
			}).catch(function () {});
		}
	};

	/* ---------------- Media table ---------------- */

		/* ---------------- Media table ---------------- */

	var Media = {
		page: 1, filters: { q: '', status: 'all', webp: 'all', type: 'all' }, selected: {},

		init: function () {
			if (!document.getElementById('op-media-table')) return;
			var self = this;
			document.getElementById('op-media-q').addEventListener('input', debounce(function (e) {
				self.filters.q = e.target.value; self.page = 1; self.load();
			}, 350));
			['status', 'webp', 'type'].forEach(function (k) {
				document.getElementById('op-media-' + k).addEventListener('change', function (e) {
					self.filters[k] = e.target.value; self.page = 1; self.load();
				});
			});
			this.load();
		},

		load: function () {
			var self = this;
			var wrap = document.getElementById('op-media-table');
			wrap.innerHTML = '<div class="op-skeleton"><div></div><div></div><div></div><div></div><div></div></div>';
			var params = { page: this.page };
			Object.keys(this.filters).forEach(function (k) { params[k] = self.filters[k]; });
			api('media', params, 'GET').then(function (r) { self.render(r); }).catch(function (e) {
				wrap.innerHTML = '<div class="op-alert op-alert--error">' + esc(e.message) + '</div>';
			});
		},

		render: function (r) {
			var wrap = document.getElementById('op-media-table');
			this.selected = {};
			this.syncBar();

			if (!r.rows.length) {
				wrap.innerHTML = '<div class="op-empty"><span class="dashicons dashicons-format-image"></span><h3>No images found</h3><p>' +
					(this.page > 1 || this.filters.q ? 'Try different filters, or scan the library from the Bulk Optimize screen.' :
					'Upload images to the Media Library, then scan and optimize them from the Bulk Optimize screen.') + '</p></div>';
				document.getElementById('op-media-pager').innerHTML = '';
				return;
			}

			var html = '<table class="op-table"><thead><tr>' +
				'<th class="op-check"><input type="checkbox" id="op-media-selectall" aria-label="Select all"></th>' +
				'<th>Image</th><th>Original</th><th>Current</th><th>Saved</th>' +
				'<th>Status</th><th>WebP</th><th>AVIF</th><th>Date</th><th>Actions</th></tr></thead><tbody>';

			r.rows.forEach(function (row) {
				var short = row.mime.replace('image/', '').toUpperCase();
				var savedCell = row.saved > 0
					? '<b>−' + fmtBytes(row.saved) + '</b><div class="op-mini"><div class="op-mini__bar" style="width:' + Math.min(100, row.pct) + '%"></div></div><span class="op-sub">' + row.pct + '%</span>'
					: '<span class="op-sub">—</span>';
				html += '<tr data-item="' + row.id + '">' +
					'<td class="op-check"><input type="checkbox" class="op-row-check" data-id="' + row.id + '" aria-label="Select ' + esc(row.file) + '"></td>' +
					'<td><div style="display:flex;gap:10px;align-items:center">' +
					(row.thumb ? '<img class="op-thumb" src="' + esc(row.thumb) + '" alt="" loading="lazy">' : '<span class="op-thumb"></span>') +
					'<div><span class="op-file" title="' + esc(row.file) + '">' + esc(row.file) + '</span>' +
					'<span class="op-sub">' + esc(short) + ' · ' + esc(row.dimensions) + '</span></div></div></td>' +
					'<td>' + fmtBytes(row.orig) + '</td><td>' + fmtBytes(row.current) + '</td>' +
					'<td>' + savedCell + '</td>' +
					'<td>' + pill(row.status) + (row.error ? '<br><span class="op-sub" title="' + esc(row.error) + '">' + esc(row.error.slice(0, 40)) + (row.error.length > 40 ? '…' : '') + '</span>' : '') + '</td>' +
					'<td>' + pill(row.webp) + '</td><td>' + pill(row.avif) + '</td>' +
					'<td class="op-sub">' + esc(row.optimized_at || '—') + '</td>' +
					'<td><div class="op-row-actions">' +
					'<button class="op-btn op-btn--sm op-btn--ghost" data-detail="' + row.id + '">Details</button>' +
					(row.status === 'pending' ? '<button class="op-btn op-btn--sm op-btn--primary" data-run="optimize" data-id="' + row.id + '">Optimize</button>' : '') +
					(row.status === 'optimized' ? '<button class="op-btn op-btn--sm op-btn--ghost" data-run="reoptimize" data-id="' + row.id + '">Re-opt</button>' : '') +
					(row.status === 'failed' ? '<button class="op-btn op-btn--sm op-btn--primary" data-run="retry" data-id="' + row.id + '">' + esc(I.retry || 'Retry') + '</button>' : '') +
					'</div></td></tr>';
			});
			html += '</tbody></table>';
			wrap.innerHTML = html;
			this.bindChecks();
			this.pager(r);
		},

		bindChecks: function () {
			var self = this;
			var all = document.getElementById('op-media-selectall');
			if (all) {
				all.addEventListener('change', function () {
					document.querySelectorAll('.op-row-check').forEach(function (c) {
						c.checked = all.checked;
						if (all.checked) { self.selected[c.dataset.id] = true; } else { delete self.selected[c.dataset.id]; }
					});
					self.syncBar();
				});
			}
			document.querySelectorAll('.op-row-check').forEach(function (c) {
				c.addEventListener('change', function () {
					if (c.checked) { self.selected[c.dataset.id] = true; } else { delete self.selected[c.dataset.id]; }
					self.syncBar();
				});
			});
		},

		syncBar: function () {
			var bar = document.getElementById('op-media-bulkbar');
			if (!bar) return;
			var ids = Object.keys(this.selected);
			var count = document.getElementById('op-media-selcount');
			if (count) count.textContent = ids.length + ' selected';
			bar.hidden = ids.length === 0;
		},

		bulkAction: function (op) {
			var ids = Object.keys(this.selected);
			if (!ids.length) return;
			if (op === 'restore' && !window.confirm(I.confirm_restore)) return;
			var self = this;
			runAction(op, ids, null);
			this.selected = {};
			this.syncBar();
			setTimeout(function () { self.load(); }, 1500);
		},

		pager: function (r) {
			var el = document.getElementById('op-media-pager');
			if (r.pages < 2) { el.innerHTML = '<span>' + fmtNum(r.total) + ' images</span>'; return; }
			var html = '<span>' + fmtNum(r.total) + ' images</span>';
			html += '<button data-page="' + Math.max(1, this.page - 1) + '" ' + (this.page === 1 ? 'disabled' : '') + '>‹</button>';
			var start = Math.max(1, this.page - 2), end = Math.min(r.pages, start + 4);
			for (var p = start; p <= end; p++) {
				html += '<button data-page="' + p + '" class="' + (p === this.page ? 'is-active' : '') + '">' + p + '</button>';
			}
			html += '<button data-page="' + Math.min(r.pages, this.page + 1) + '" ' + (this.page === r.pages ? 'disabled' : '') + '>›</button>';
			el.innerHTML = html;
			var self = this;
			el.querySelectorAll('button[data-page]').forEach(function (b) {
				b.addEventListener('click', function () { self.page = parseInt(b.dataset.page, 10); self.load(); });
			});
		}
	};

	/* ---------------- Single actions ---------------- */

	function runAction(op, ids, btn) {
		var original = btn ? btn.innerHTML : '';
		var labels = { optimize: I.optimizing, reoptimize: I.optimizing, webp: I.generating, avif: I.generating, restore: I.restoring, retry: I.optimizing };
		if (btn) { btn.disabled = true; btn.innerHTML = '<span class="op-spinner"></span> ' + esc(labels[op] || 'Working…'); }
		api('action', { op: op, ids: ids }).then(function (r) {
			var okCount = 0, failCount = 0, msg = '';
			(r.results || []).forEach(function (res) {
				if (res.ok) okCount++; else failCount++;
				msg = res.message || msg;
			});
			if (failCount === 0) toast(okCount + (op === 'restore' ? ' image(s) restored.' : ' operation(s) succeeded. ') + (msg || ''), 'success');
			else toast(msg || (failCount + ' operation(s) failed.'), 'error');
			if (window.Media) Media.load();
		}).catch(function (e) {
			toast(e.message, 'error');
			if (btn) { btn.disabled = false; btn.innerHTML = original; }
		});
	}

	/* ---------------- Detail modal ---------------- */

	function openDetail(id) {
		openModal('Loading…', '<div class="op-skeleton"><div></div><div></div><div></div></div>');
		api('item_detail', { id: id }, 'GET').then(function (d) {
			var r = d.row;
			var body = '<div class="op-detail">' +
				'<div>' + (d.preview ? '<img src="' + esc(d.preview) + '" alt="">' : '') + '</div><div>' +
				'<div class="op-detail__stats">' +
				'<div><span>Filename</span><b>' + esc(r.file) + '</b></div>' +
				'<div><span>Dimensions</span><b>' + esc(r.dimensions) + '</b></div>' +
				'<div><span>Format</span><b>' + esc(r.mime) + '</b></div>' +
				'<div><span>Status</span><b>' + pill(r.status) + '</b></div>' +
				'<div><span>Original size</span><b>' + fmtBytes(r.orig) + '</b></div>' +
				'<div><span>Current size</span><b>' + fmtBytes(r.current) + '</b></div>' +
				'<div><span>Saved</span><b>' + fmtBytes(r.saved) + ' (' + r.pct + '%)</b></div>' +
				'<div><span>Optimized at</span><b>' + esc(r.optimized_at || 'Never') + '</b></div>' +
				'<div><span>WebP</span><b>' + pill(r.webp) + '</b></div>' +
				'<div><span>AVIF</span><b>' + pill(r.avif) + '</b></div>' +
				'<div><span>Backup</span><b>' + (r.backup ? 'Available' : 'None') + '</b></div>' +
				'<div><span>Engine</span><b>' + esc((d.meta && d.meta.engine) || '—') + '</b></div>' +
				'</div>';
			if (r.error) body += '<div class="op-error-box">' + esc(r.error) + '</div>';
			body += '<div class="op-row-actions" style="margin-top:14px">' +
				(r.status === 'pending' ? '<button class="op-btn op-btn--primary" data-run="optimize" data-id="' + r.id + '">Optimize</button>' : '') +
				(r.status === 'optimized' ? '<button class="op-btn op-btn--primary" data-run="reoptimize" data-id="' + r.id + '">Re-optimize</button>' : '') +
				(r.status === 'failed' ? '<button class="op-btn op-btn--primary" data-run="retry" data-id="' + r.id + '">Retry</button>' : '') +
				(r.status === 'optimized' && d.caps.webp_encode ? '<button class="op-btn op-btn--ghost" data-run="webp" data-id="' + r.id + '">' + (r.webp === 'done' ? 'Regenerate' : 'Generate') + ' WebP</button>' : '') +
				(r.status === 'optimized' && d.caps.avif_encode ? '<button class="op-btn op-btn--ghost" data-run="avif" data-id="' + r.id + '">' + (r.avif === 'done' ? 'Regenerate' : 'Generate') + ' AVIF</button>' : '') +
				(r.backup ? '<button class="op-btn op-btn--ghost" data-run="restore" data-id="' + r.id + '">Restore Original</button>' : '') +
				'</div></div></div>';

			body += '<div class="op-history"><h3>Processing history</h3>';
			if (!d.history.length) {
				body += '<p class="op-muted">No recorded operations for this image yet.</p>';
			} else {
				body += '<ul style="list-style:none;margin:0;padding:0">';
				d.history.forEach(function (h) {
					body += '<li><span class="op-dot op-dot--' + esc(h.level) + '" style="margin-top:6px"></span><div>' + esc(h.message) + '</div><time>' + esc(h.time) + '</time></li>';
				});
				body += '</ul>';
			}
			body += '</div>';

			var m = openModal(r.file, body);
			bindActions(m);
		}).catch(function (e) {
			openModal('Error', '<div class="op-alert op-alert--error">' + esc(e.message) + '</div>');
		});
	}

	function bindActions(scope) {
		scope.querySelectorAll('[data-run]').forEach(function (btn) {
			btn.addEventListener('click', function () {
				var op = btn.dataset.run;
				if (op === 'restore' && !window.confirm(I.confirm_restore)) return;
				runAction(op, [btn.dataset.id], btn);
			});
		});
		scope.querySelectorAll('[data-detail]').forEach(function (btn) {
			btn.addEventListener('click', function () { openDetail(btn.dataset.detail); });
		});
	}

	/* ---------------- Logs ---------------- */

	var Logs = {
		page: 1, filters: { q: '', level: 'all', op: 'all', from: '', to: '' },

		init: function () {
			if (!document.getElementById('op-logs-table')) return;
			var self = this;
			document.getElementById('op-logs-q').addEventListener('input', debounce(function (e) { self.filters.q = e.target.value; self.page = 1; self.load(); }, 350));
			['level', 'op'].forEach(function (k) {
				document.getElementById('op-logs-' + k).addEventListener('change', function (e) { self.filters[k] = e.target.value; self.page = 1; self.load(); });
			});
			['from', 'to'].forEach(function (k) {
				document.getElementById('op-logs-' + k).addEventListener('change', function (e) { self.filters[k] = e.target.value; self.page = 1; self.load(); });
			});
			document.getElementById('op-logs-clear').addEventListener('click', function () {
				if (!window.confirm(I.confirm_clear)) return;
				api('logs_clear').then(function () { toast('Logs cleared.', 'info'); self.page = 1; self.load(); }).catch(function (e) { toast(e.message, 'error'); });
			});
			this.load();
		},

		load: function () {
			var self = this;
			var params = { page: this.page };
			Object.keys(this.filters).forEach(function (k) { params[k] = self.filters[k]; });
			api('logs', params, 'GET').then(function (r) { self.render(r); }).catch(function (e) {
				document.getElementById('op-logs-table').innerHTML = '<div class="op-alert op-alert--error">' + esc(e.message) + '</div>';
			});
		},

		render: function (r) {
			var wrap = document.getElementById('op-logs-table');
			if (!r.rows.length) {
				wrap.innerHTML = '<div class="op-empty"><span class="dashicons dashicons-list-view"></span><h3>No logs yet</h3><p>Every optimization, conversion, restore and error will be recorded here with full context.</p></div>';
				document.getElementById('op-logs-pager').innerHTML = '';
				return;
			}
			var html = '<table class="op-table"><thead><tr><th>Time</th><th>Level</th><th>Operation</th><th>Image</th><th>Message</th><th></th></tr></thead><tbody>';
			r.rows.forEach(function (row) {
				var ctx = '';
				if (row.context && row.context.orig != null) {
					ctx = '<div class="op-sub">Original: ' + fmtBytes(row.context.orig) + ' · Optimized: ' + fmtBytes(row.context.new) +
						' · Saved: ' + fmtBytes(row.context.saved) + ' (' + row.context.pct + '%)</div>';
				}
				html += '<tr><td class="op-sub">' + esc(row.time) + '</td>' +
					'<td><span class="op-pill op-pill--' + esc(row.level) + '">' + esc(row.level) + '</span></td>' +
					'<td>' + esc(row.op) + '</td>' +
					'<td><span class="op-file" style="max-width:180px">' + esc(row.file || '—') + '</span></td>' +
					'<td>' + esc(row.message) + ctx + '</td>' +
					'<td>' + (row.retryable ? '<button class="op-btn op-btn--sm op-btn--ghost" data-retry="' + row.attachment + '">Retry</button>' : '') + '</td></tr>';
			});
			html += '</tbody></table>';
			wrap.innerHTML = html;

			wrap.querySelectorAll('[data-retry]').forEach(function (b) {
				b.addEventListener('click', function () {
					// Reset to pending then optimize.
					runAction('retry', [b.dataset.retry], b);
					setTimeout(function () { Logs.load(); }, 1500);
				});
			});

			var el = document.getElementById('op-logs-pager');
			if (r.pages < 2) { el.innerHTML = '<span>' + fmtNum(r.total) + ' entries</span>'; return; }
			var ph = '<span>' + fmtNum(r.total) + ' entries</span><button data-page="' + Math.max(1, this.page - 1) + '" ' + (this.page === 1 ? 'disabled' : '') + '>‹</button>';
			ph += '<span>Page ' + this.page + ' / ' + r.pages + '</span>';
			ph += '<button data-page="' + Math.min(r.pages, this.page + 1) + '" ' + (this.page === r.pages ? 'disabled' : '') + '>›</button>';
			el.innerHTML = ph;
			var self = this;
			el.querySelectorAll('button[data-page]').forEach(function (b) {
				b.addEventListener('click', function () { self.page = parseInt(b.dataset.page, 10); self.load(); });
			});
		}
	};

	/* ---------------- Analytics ---------------- */

	var Analytics = {
		range: '30',

		init: function () {
			if (!document.getElementById('op-an-body')) return;
			var self = this;
			document.querySelectorAll('#op-range button').forEach(function (b) {
				b.addEventListener('click', function () {
					document.querySelectorAll('#op-range button').forEach(function (x) { x.classList.remove('is-active'); });
					b.classList.add('is-active');
					self.range = b.dataset.range;
					self.load();
				});
			});
			this.load();
		},

		load: function () {
			var self = this;
			api('analytics', { range: this.range }, 'GET').then(function (d) { self.render(d); }).catch(function (e) {
				document.getElementById('op-an-body').innerHTML = '<div class="op-alert op-alert--error">' + esc(e.message) + '</div>';
			});
		},

		render: function (d) {
			var t = d.totals;
			document.querySelector('[data-an="optimized"]').textContent = fmtNum(t.optimized);
			document.querySelector('[data-an="saved"]').textContent = fmtBytes(t.saved);
			document.querySelector('[data-an="converted"]').textContent = fmtNum(t.webp + t.avif);
			document.querySelector('[data-an="success"]').textContent = t.success_rate + '%';

			var body = document.getElementById('op-an-body');
			if (!d.series.length) {
				body.innerHTML = '<div class="op-empty"><span class="dashicons dashicons-chart-area"></span><h3>No analytics for this period</h3><p>Run some optimizations (Dashboard → Bulk Optimize) and real data will appear here — nothing is ever simulated.</p></div>';
				return;
			}
			body.innerHTML =
				'<div class="op-card__head" style="margin-top:22px"><h2>Space Saved Per Day</h2><span class="op-muted">' + esc(fmtBytes(t.saved)) + ' total in range</span></div>' +
				'<div class="op-chart" id="op-an-area"></div>' +
				'<div class="op-card__head" style="margin-top:26px"><h2>Operations Per Day</h2><span class="op-muted">' + fmtNum(t.operations) + ' operations in range</span></div>' +
				'<div class="op-chart op-chart--sm" id="op-an-bars"></div>' +
				'<div class="op-grid op-grid--2" style="margin-top:26px">' +
				'<div><h3 style="margin-top:0">Conversions</h3><ul class="op-kv">' +
				'<li><span>WebP conversions</span><b>' + fmtNum(t.webp) + '</b></li>' +
				'<li><span>AVIF conversions</span><b>' + fmtNum(t.avif) + '</b></li></ul></div>' +
				'<div><h3 style="margin-top:0">Reliability</h3><ul class="op-kv">' +
				'<li><span>Total operations</span><b>' + fmtNum(t.operations) + '</b></li>' +
				'<li><span>Failed operations</span><b class="' + (t.failed ? 'op-danger' : '') + '">' + fmtNum(t.failed) + '</b></li>' +
				'<li><span>Success rate</span><b>' + t.success_rate + '%</b></li></ul></div></div>';
			barChart(document.getElementById('op-an-area'), d.series, {
				key: 'saved', fmt: fmtBytes, label1: 'Saved', aria: 'Space saved per day',
				legend: '<div class="op-legend"><span><i style="background:linear-gradient(180deg,#3056d3,#1fb6b0)"></i>Bytes saved</span></div>'
			});
			barChart(document.getElementById('op-an-bars'), d.series, {
				key: 'optimized', key2: 'failed', fmt: fmtNum, label1: 'Optimized', label2: 'Failed', aria: 'Operations per day',
				legend: '<div class="op-legend"><span><i style="background:#3056d3"></i>Optimized</span><span><i style="background:#e8909f"></i>Failed</span></div>'
			});
		}
	};

	/* ---------------- Settings ---------------- */

	var Settings = {
		init: function () {
			var form = document.getElementById('op-settings');
			if (!form) return;
			var bar = document.getElementById('op-savebar');
			var pristine = new FormData(form);
			var snap = function () {
				var o = {};
				new FormData(form).forEach(function (v, k) { o[k] = v; });
				return JSON.stringify(o);
			};
			var initial = snap();

			// Checkboxes: FormData omits unchecked boxes → normalize.
			var normalize = function () {
				form.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.setAttribute('value', '1'); });
			};
			normalize();
			initial = snap();

			form.addEventListener('input', function () {
				bar.hidden = snap() === initial;
			});
			form.addEventListener('change', function () {
				bar.hidden = snap() === initial;
			});

			['quality:op-quality-out', 'webp_quality:op-webpq-out', 'avif_quality:op-avifq-out'].forEach(function (pair) {
				var parts = pair.split(':'), input = form.querySelector('[name="' + parts[0] + '"]'), out = document.getElementById(parts[1]);
				if (input && out) input.addEventListener('input', function () { out.textContent = input.value; });
			});

			document.getElementById('op-settings-reset').addEventListener('click', function () {
				location.reload();
			});

			form.addEventListener('submit', function (e) {
				e.preventDefault();
				var btn = document.getElementById('op-settings-save');
				btn.disabled = true; btn.innerHTML = '<span class="op-spinner"></span> Saving…';
				var data = {};
				new FormData(form).forEach(function (v, k) { data[k] = v; });
				api('settings_save', { settings: data }).then(function (r) {
					btn.disabled = false; btn.textContent = 'Save Settings';
					toast(r.message, 'success');
					(r.warnings || []).forEach(function (w) { toast(w, 'error'); });
					bar.hidden = true;
					initial = snap();
				}).catch(function (err) {
					btn.disabled = false; btn.textContent = 'Save Settings';
					toast(err.message, 'error');
				});
			});

			var purge = document.getElementById('op-purge-btn');
			if (purge) purge.addEventListener('click', function () {
				purge.disabled = true;
				api('purge_cache').then(function (r) { toast(r.message, 'success'); purge.disabled = false; })
					.catch(function (e) { toast(e.message, 'error'); purge.disabled = false; });
			});
		}
	};

	/* ---------------- Dashboard refresh ---------------- */

	function initDashboard() {
		ring(document.getElementById('op-dash-ring'));
		var dataEl = document.getElementById('op-dash-chart-data');
		if (dataEl) {
			try {
				barChart(document.getElementById('op-dash-chart'), JSON.parse(dataEl.textContent), {
					key: 'saved', fmt: fmtBytes, label1: 'Saved', aria: 'Bytes saved per day',
					legend: '<div class="op-legend"><span><i style="background:linear-gradient(180deg,#3056d3,#1fb6b0)"></i>Bytes saved per day</span></div>'
				});
			} catch (e) {}
		}
		// Periodic refresh so stats stay live during background auto-optimization.
		if (document.getElementById('op-dash-cards')) {
			setInterval(function () {
				api('stats', {}, 'GET').then(function (d) {
					var c = d.counters;
					var set = function (sel, val) { var el = document.querySelector('[data-counter="' + sel + '"]'); if (el) el.textContent = val; };
					set('optimized', fmtNum(c.optimized));
					set('saved', fmtBytes(c.saved));
					set('avg', c.avg_pct.toFixed(1) + '%');
					set('converted', fmtNum(c.converted));
				}).catch(function () {});
			}, 15000);
		}
	}

	/* ---------------- Boot ---------------- */

	document.addEventListener('DOMContentLoaded', function () {
		var body = document.body;
		var safe = function (fn) {
			try { fn(); } catch (e) { if (window.console) console.error('OptiPress UI error:', e); }
		};
		safe(initDashboard);
		safe(function () { Bulk.init(); });
		safe(function () { Media.init(); window.Media = Media; });
		safe(function () { Logs.init(); });
		safe(function () { Analytics.init(); });
		safe(function () { Settings.init(); });

		// Delegated actions for tables rendered via AJAX.
		document.addEventListener('click', function (e) {
			var run = e.target.closest('[data-run]');
			if (run) {
				var op = run.dataset.run;
				if (op === 'restore' && !window.confirm(I.confirm_restore)) return;
				runAction(op, [run.dataset.id], run);
				return;
			}
			var detail = e.target.closest('[data-detail]');
			if (detail) { openDetail(detail.dataset.detail); return; }

			var mb = e.target.closest('[data-media-bulk]');
			if (mb && window.Media) {
				var mop = mb.dataset.mediaBulk;
				if (mop === 'clear') {
					Media.selected = {};
					document.querySelectorAll('.op-row-check').forEach(function (c) { c.checked = false; });
					var sa = document.getElementById('op-media-selectall');
					if (sa) sa.checked = false;
					Media.syncBar();
				} else {
					Media.bulkAction(mop);
				}
			}
		});

		// Dismiss the global pending notice.
		var noticeDismiss = body.querySelector('.notice [data-dismiss-optipress]');
		if (noticeDismiss) noticeDismiss.addEventListener('click', function () { api('dismiss_notice'); });
	});
})();