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
		var frag = document.createDocumentFragment();
		if (!d.rows.length) frag.appendChild(el('div', 'empty', 'Nobody is connected right now.'));
		d.rows.forEach(function (r) {
			var pc = clsFor[r.proto] || 'p-other';
			var row = el('div', 'row' + (r.talking ? ' talk' : ''));
			row.appendChild(el('span', 'av ' + pc, r.proto.slice(0, 2).toUpperCase()));
			var mid = el('div');
			var b = el('b');
			if (r.link) {
				var a = el('a', '', r.node);
				a.href = r.link; a.target = '_blank'; a.rel = 'noopener';
				b.appendChild(a);
			} else { b.textContent = r.node; }
			mid.appendChild(b);
			mid.appendChild(el('div', 'sub', r.attrs || 'Idle'));
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
