// AllStar panel: node list from asl.php (about once a minute), upgraded to a real-time
// Allmon3 WebSocket feed when one is configured in the admin page.
(function () {
	var sec = document.getElementById('asl');
	if (!sec) return;
	var node = sec.getAttribute('data-node');
	var rowsEl = document.getElementById('asl-rows');
	var stateEl = document.getElementById('asl-state');
	var info = {};          // node number -> {call, where, site}
	var aslIp = '';         // the node's IP, used to spot which TheLinkBox connection is the AllStar bridge
	var ws = null, liveUrl = null, liveOn = false, retryMs = 5000, retryTimer = null;

	function el(tag, cls, text) {
		var e = document.createElement(tag);
		if (cls) e.className = cls;
		if (text !== undefined) e.textContent = text;
		return e;
	}

	// Callsign for a node: AllStarLink's record first, else a description that starts with one
	// (how EchoLink stations show up, e.g. "N4RGJ-R ..."). Bridges like "IRLP: 0091/EchoLink: KV4S-R" get none.
	function callOf(n, desc) {
		if (info[n] && info[n].call) return info[n].call.toUpperCase();
		var m = /^([A-Z]{1,2}[0-9][A-Z]{1,4})(?:-[LR])?\b/.exec(desc || '');
		return m ? m[1] : '';
	}

	function setState(text, live) {
		stateEl.textContent = text;
		stateEl.className = live ? 'live' : 'sub';
		if (live) { var d = el('span', 'dot'); stateEl.insertBefore(d, stateEl.firstChild); }
	}

	// list: [{node, desc, mode, time}], talking: node number string or '', localTx: bool
	function render(list, talking, localTx) {
		var frag = document.createDocumentFragment();
		if (!list.length) frag.appendChild(el('div', 'empty', 'Nothing is linked to this node right now.'));
		list.forEach(function (r) {
			var isTx = talking && String(r.node) === String(talking);
			var inf = info[r.node] || {};
			var call = callOf(r.node, r.desc);
			var row = el('div', 'row adm' + (isTx ? ' talk' : ''));
			row.appendChild(el('span', 'av p-other', 'AS'));
			var mid = el('div');
			var b = el('b');
			if (call) {
				var a = el('a', '', call);
				a.href = 'https://www.qrz.com/db/' + encodeURIComponent(call);
				a.target = '_blank'; a.rel = 'noopener';
				b.appendChild(a);
			} else { b.textContent = r.node; }
			mid.appendChild(b);
			var bits = [];
			if (call) bits.push(r.node);
			var place = inf.where || inf.site || '';
			if (place) bits.push(place);
			else if (r.desc) bits.push(r.desc);
			if (r.mode) bits.push(r.mode);
			mid.appendChild(el('div', 'sub', bits.join(' · ')));
			row.appendChild(mid);
			row.appendChild(el('span', isTx ? 'tk' : 'sub', (isTx ? 'Talking' : '') + (isTx && r.time ? ' · ' : '') + (r.time || '')));
			frag.appendChild(row);
		});
		if (localTx) {
			var lr = el('div', 'row adm talk');
			lr.appendChild(el('span', 'av p-other', 'RX'));
			lr.appendChild(el('div', '', 'Local audio on this node'));
			lr.appendChild(el('span', 'tk', 'Talking'));
			frag.insertBefore(lr, frag.firstChild);
		}
		if (rowsEl.replaceChildren) rowsEl.replaceChildren(frag);
		else { rowsEl.innerHTML = ''; rowsEl.appendChild(frag); }
		document.dispatchEvent(new CustomEvent('asl:data', { detail: {
			node: node, ip: aslIp, talking: talking ? String(talking) : '', localTx: !!localTx,
			list: list.map(function (r) { return { node: String(r.node), call: callOf(r.node, r.desc), mode: r.mode, desc: r.desc }; })
		} }));
	}

	function poll() {
		if (document.hidden && !window.tlbKeepAlive) return; // alerts.js sets tlbKeepAlive when alerts are on
		fetch('asl.php', { cache: 'no-store' })
			.then(function (r) { if (!r.ok) throw 0; return r.json(); })
			.then(function (d) {
				if (!d) return;
				aslIp = d.ip || '';
				(d.links || []).forEach(function (l) { info[l.node] = l; });
				if (d.live && d.live.ws && d.live.ws !== liveUrl) { liveUrl = d.live.ws; connect(); }
				if (!liveOn) {
					if (d.error) { render([], '', false); rowsEl.firstChild.textContent = d.error; setState('Unavailable', false); return; }
					render((d.links || []).map(function (l) {
						return { node: l.node, desc: l.site, mode: l.mode, time: '' };
					}), '', false);
					setState(d.live && d.live.error ? d.live.error : 'Updates about once a minute', false);
				}
			})
			.catch(function () { if (!liveOn) setState('Unavailable', false); });
	}

	function connect() {
		if (!liveUrl || (document.hidden && !window.tlbKeepAlive) || !window.WebSocket) return;
		if (ws && ws.readyState <= 1) return;
		try { ws = new WebSocket(liveUrl); } catch (e) { return; }
		ws.onopen = function () { retryMs = 5000; };
		ws.onmessage = function (m) {
			var d; try { d = JSON.parse(m.data); } catch (e) { return; }
			var n = d[node];
			if (!n || typeof n !== 'object') return;
			var list = [], unknown = false;
			Object.keys(n.CONNS || {}).forEach(function (k) {
				var c = n.CONNS[k];
				if (!info[k]) unknown = true;
				list.push({ node: k, desc: c.DESC, mode: c.MODE, time: c.CTIME });
			});
			liveOn = true;
			render(list, n.CONNKEYED === true ? n.CONNKEYEDNODE : '', n.RXKEYED === true);
			setState('Live', true);
			if (unknown) setTimeout(poll, 1500); // pick up callsign details for a node we haven't seen
		};
		ws.onclose = function () {
			liveOn = false;
			clearTimeout(retryTimer);
			retryTimer = setTimeout(connect, retryMs);
			retryMs = Math.min(retryMs * 2, 60000);
			poll();
		};
		ws.onerror = function () { try { ws.close(); } catch (e) {} };
	}

	document.addEventListener('visibilitychange', function () { if (!document.hidden) { poll(); connect(); } });
	poll();
	setInterval(poll, 20000);
})();
