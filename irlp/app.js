// Live updates for the dashboard: fetch api.php every couple of seconds and redraw the node list.
(function () {
	var POLL_MS = 2500;
	var rowsEl = document.getElementById('rows');
	if (!rowsEl || !window.fetch) return;

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text !== undefined) e.textContent = text;
		return e;
	}
	var clsFor = { IRLP: 'p-irlp', EchoLink: 'p-echolink', RTP: 'p-rtp' };

	function render(d) {
		document.getElementById('st-count').textContent = d.count;
		document.getElementById('st-talking').textContent = d.talking;
		document.getElementById('st-uptime').textContent = d.uptime || '-';
		document.getElementById('st-version').textContent = d.version || '-';
		if (d.sys) renderSys(d.sys);
		var frag = document.createDocumentFragment();
		if (!d.rows.length) frag.appendChild(el('div', 'empty', 'Nobody is connected right now.'));
		d.rows.forEach(function (r) {
			var pc = clsFor[r.proto] || 'p-other';
			var row = el('div', 'row' + (r.talking ? ' talk' : ''));
			row.appendChild(el('span', 'av ' + pc, r.proto.slice(0, 2).toUpperCase()));
			var mid = el('div');
			var b = el('b');
			var name = r.call || r.node;   // IRLP stations: callsign, with the node number on the line below
			if (r.link) {
				var a = el('a', '', name);
				a.href = r.link; a.target = '_blank'; a.rel = 'noopener';
				b.appendChild(a);
			} else { b.textContent = name; }
			mid.appendChild(b);
			var sub = r.call ? [r.num || r.node] : [];
			if (r.call && r.where) sub.push(r.where);
			sub.push(r.attrs || 'Idle');
			mid.appendChild(el('div', 'sub', sub.join(' · ')));
			row.appendChild(mid);
			var m1 = el('span', 'meta'); m1.appendChild(el('span', 'chip ' + pc, r.proto)); row.appendChild(m1);
			var m2 = el('span', 'meta');
			if (r.ip) {
				var ia = el('a', '', r.ip);
				ia.href = 'https://whois.arin.net/ui/query.do?queryinput=' + encodeURIComponent(r.ip);
				ia.target = '_blank'; ia.rel = 'noopener';
				m2.appendChild(ia);
			}
			row.appendChild(m2);
			row.appendChild(el('span', r.talking ? 'tk' : 'sub', (r.talking ? 'Talking · ' : '') + r.time));
			frag.appendChild(row);
		});
		rowsEl.replaceChildren ? rowsEl.replaceChildren(frag) : (rowsEl.innerHTML = '', rowsEl.appendChild(frag));
	}

	// server health cards (only on the page when turned on in admin)
	function set(id, text) { var e = document.getElementById(id); if (e) e.textContent = text; }
	function gb(b) { return (b / 1073741824).toFixed(2); }
	function pct(u) { return u.total ? Math.round(u.used / u.total * 100) + '%' : '-'; }
	function rate(bps) { var k = bps * 8 / 1000; return k >= 1000 ? (k / 1000).toFixed(1) + ' Mb/s' : k.toFixed(k < 10 ? 1 : 0) + ' kb/s'; }
	function renderSys(s) {
		set('hs-cpu', s.load.toFixed(2));
		if (s.mem) { set('hs-mem', pct(s.mem)); set('hs-mem-sub', gb(s.mem.used) + ' / ' + gb(s.mem.total) + ' GB'); }
		if (s.disk) { set('hs-disk', pct(s.disk)); set('hs-disk-sub', gb(s.disk.used) + ' / ' + gb(s.disk.total) + ' GB'); }
		if (s.net) { set('hs-net', rate(s.net.rx + s.net.tx)); set('hs-net-sub', '↓ ' + rate(s.net.rx) + ' · ↑ ' + rate(s.net.tx)); }
		if (s.temp !== undefined) set('hs-temp', Math.round(s.temp) + '°C');
	}

	var live = document.getElementById('live');
	function tick() {
		if (document.hidden && !window.tlbKeepAlive) return; // keep going in a background tab when alerts are on
		fetch('api.php', { cache: 'no-store' })
			.then(function (r) { if (!r.ok) throw 0; return r.json(); })
			.then(function (d) {
				render(d);
				live.hidden = false;
				document.dispatchEvent(new CustomEvent('tlb:data', { detail: d })); // for the network diagram
			})
			.catch(function () { live.hidden = true; });
	}
	tick();
	setInterval(tick, POLL_MS);
	document.addEventListener('visibilitychange', tick);
})();
