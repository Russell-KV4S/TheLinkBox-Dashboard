// "Last heard": stations that talked, with how long, plus a 24-hour timeline of who was on the air.
// Data comes from heard.php (logged by the tlb-notify service). Refreshes every 30 seconds and right
// away when the live list shows someone start or stop talking.
(function () {
	var sec = document.getElementById('heard');
	if (!sec || !window.fetch) return;
	var rowsEl = document.getElementById('heard-rows');
	var tlEl = document.getElementById('heard-tl');
	var moreBtn = document.getElementById('heard-more');
	var detailEl = document.getElementById('heard-detail');
	var POLL_MS = 30000, LANES = 8, SHORT = 10;
	var data = null, offset = 0, expanded = false, lastSig = '', busy = false;
	var clsFor = { IRLP: 'p-irlp', EchoLink: 'p-echolink', RTP: 'p-rtp', AllStar: 'p-rtp' };

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text !== undefined) e.textContent = text;
		return e;
	}
	function nowSec() { return Date.now() / 1000 + offset; }
	function dur(sec) {
		sec = Math.max(1, Math.round(sec));
		var h = Math.floor(sec / 3600), m = Math.floor(sec / 60) % 60, s = sec % 60;
		return (h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (s < 10 ? '0' : '') + s;
	}
	function total(sec) {
		if (sec < 60) return Math.max(1, Math.round(sec)) + 's';
		var h = Math.floor(sec / 3600), m = Math.round(sec / 60) % 60;
		return h ? h + 'h ' + m + 'm' : Math.round(sec / 60) + 'm';
	}
	function clock(t) { return new Date(t * 1000).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }
	function ago(t) {
		var d = nowSec() - t;
		if (d < 60) return 'just now';
		if (d < 3600) return Math.floor(d / 60) + ' min ago';
		if (d < 86400) return Math.floor(d / 3600) + ' h ago';
		return new Date(t * 1000).toLocaleDateString([], { month: 'short', day: 'numeric' }) + ', ' + clock(t);
	}
	function link(name, href) {
		if (!href) return document.createTextNode(name);
		var a = el('a', '', name);
		a.href = href; a.target = '_blank'; a.rel = 'noopener';
		return a;
	}

	function renderList() {
		var list = data.stations, frag = document.createDocumentFragment();
		if (!list.length) frag.appendChild(el('div', 'empty', 'Nobody has talked in the last ' + (data.days > 1 ? data.days + ' days.' : 'day.')));
		list.slice(0, expanded ? list.length : SHORT).forEach(function (r) {
			var pc = clsFor[r.p] || 'p-other';
			var row = el('div', 'row adm' + (r.live ? ' talk' : ''));
			row.appendChild(el('span', 'av ' + pc, r.p === 'AllStar' ? 'AS' : (r.p || '?').slice(0, 2).toUpperCase()));
			var mid = el('div'), b = el('b');
			b.appendChild(link(r.name, r.link));
			mid.appendChild(b);
			var bits = [];
			if (r.sub) bits.push(r.sub);
			if (r.overs) bits.push(r.overs + (r.overs === 1 ? ' over' : ' overs') + ' · ' + total(r.total) + ' in 24h');
			mid.appendChild(el('div', 'sub', bits.join(' · ')));
			row.appendChild(mid);
			var right = el('div', 'hr-when');
			if (r.live) {
				right.appendChild(el('span', 'tk', 'Talking · ' + dur(nowSec() - (r.last - r.dur))));
				right.lastChild.setAttribute('data-start', r.last - r.dur);
			} else {
				right.appendChild(el('span', '', ago(r.last)));
				right.appendChild(el('span', 'sub', dur(r.dur)));
			}
			row.appendChild(right);
			frag.appendChild(row);
		});
		rowsEl.replaceChildren ? rowsEl.replaceChildren(frag) : (rowsEl.innerHTML = '', rowsEl.appendChild(frag));
		moreBtn.hidden = list.length <= SHORT;
		moreBtn.textContent = expanded ? 'Show fewer' : 'Show all ' + list.length;
	}

	function renderTimeline() {
		var since = data.since, span = data.now - since, byKey = {}, lanes = [], other = null;
		data.stations.forEach(function (s) { byKey[s.k] = s; });
		var totals = {};
		data.events.forEach(function (e) { totals[e.k] = (totals[e.k] || 0) + ((e.e || data.now) - Math.max(e.s, since)); });
		var keys = Object.keys(totals).sort(function (a, b) { return totals[b] - totals[a]; });
		var laneOf = {};
		keys.forEach(function (k, i) {
			if (i < LANES || keys.length === LANES + 1) {
				var st = byKey[k] || { name: k, p: '' };
				laneOf[k] = { name: st.name, cls: clsFor[st.p] || 'p-other', bars: [] };
				lanes.push(laneOf[k]);
			} else {
				if (!other) other = { name: (keys.length - LANES) + ' others', cls: 'p-other', bars: [] };
				laneOf[k] = other;
			}
		});
		if (other) lanes.push(other);
		data.events.forEach(function (e) { laneOf[e.k].bars.push(e); });

		var frag = document.createDocumentFragment();
		if (!lanes.length) {
			frag.appendChild(el('div', 'empty', 'Quiet for the last 24 hours.'));
		} else {
			lanes.forEach(function (ln) {
				var lane = el('div', 'tl-lane');
				lane.appendChild(el('span', 'tl-name', ln.name));
				var track = el('div', 'tl-track');
				ln.bars.forEach(function (e) {
					var s = Math.max(e.s, since), end = e.e || data.now;
					var bar = el('button', 'tl-bar ' + ln.cls + (e.e ? '' : ' live'));
					bar.type = 'button';
					bar.style.left = ((s - since) / span * 100) + '%';
					bar.style.width = Math.max((end - s) / span * 100, 0.35) + '%';
					var who = (byKey[e.k] || {}).name || e.k;
					var label = who + ' · ' + clock(e.s) + ' · ' + (e.e ? dur(e.e - e.s) : 'talking now');
					bar.title = label;
					bar.setAttribute('aria-label', label);
					bar.addEventListener('click', function () { detailEl.textContent = label; });
					track.appendChild(bar);
				});
				lane.appendChild(track);
				frag.appendChild(lane);
			});
			// hour marks every 3 hours (6 on a phone), in the viewer's own time zone
			var narrow = tlEl.clientWidth < 520, step = narrow ? 6 : 3;
			var axis = el('div', 'tl-lane tl-axis');
			axis.appendChild(el('span', 'tl-name'));
			var at = el('div', 'tl-track');
			var first = Math.ceil(since / 3600) * 3600;
			for (var t = first; t <= data.now; t += 3600) {
				if (new Date(t * 1000).getHours() % step) continue;
				var pos = (t - since) / span * 100;
				if (pos > (narrow ? 86 : 94)) continue;
				var tick = el('span', 'tl-tick', new Date(t * 1000).toLocaleTimeString([], { hour: 'numeric' }));
				tick.style.left = pos + '%';
				at.appendChild(tick);
			}
			var nowT = el('span', 'tl-tick now', 'Now');
			nowT.style.left = '100%';
			at.appendChild(nowT);
			axis.appendChild(at);
			frag.appendChild(axis);
		}
		tlEl.replaceChildren ? tlEl.replaceChildren(frag) : (tlEl.innerHTML = '', tlEl.appendChild(frag));
	}

	function load() {
		if (busy || (document.hidden && !window.tlbKeepAlive)) return;
		busy = true;
		fetch('heard.php', { cache: 'no-store' })
			.then(function (r) { if (!r.ok) throw 0; return r.json(); })
			.then(function (d) {
				if (!d || d.off) { sec.hidden = true; return; }
				data = d;
				offset = d.now - Date.now() / 1000;
				renderList();
				renderTimeline();
			})
			.catch(function () { if (!data) rowsEl.firstChild.textContent = 'The log isn\'t available right now.'; })
			.then(function () { busy = false; });
	}

	// tick running "Talking" timers between refreshes
	setInterval(function () {
		var live = rowsEl.querySelectorAll('[data-start]');
		for (var i = 0; i < live.length; i++) live[i].textContent = 'Talking · ' + dur(nowSec() - live[i].getAttribute('data-start'));
	}, 1000);

	// refresh as soon as the live list shows a change in who is talking
	document.addEventListener('tlb:data', function (ev) {
		var sig = (ev.detail.rows || []).filter(function (r) { return r.talking; }).map(function (r) { return r.node; }).join(',');
		if (sig !== lastSig) { lastSig = sig; setTimeout(load, 1500); }
	});
	moreBtn.addEventListener('click', function () { expanded = !expanded; renderList(); });
	document.addEventListener('visibilitychange', load);
	load();
	setInterval(load, POLL_MS);
})();
