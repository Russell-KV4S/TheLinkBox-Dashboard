// Live network diagram: TheLinkBox conference and the AllStar node as two hubs, every station as a node,
// the bridge between them as a link. Whoever is talking lights up and audio "flows" toward the hubs.
// Fed by app.js ("tlb:data") and asl.js ("asl:data"); also runs the Live diagram / AllStar map tabs.
(function () {
	var box = document.getElementById('net');
	if (!box) return;
	var NS = 'http://www.w3.org/2000/svg';
	var W = 440, H = 300, CY = 150;
	var tlb = null, asl = null, sig = '', els = {};
	var protoCls = { IRLP: 'p-irlp', EchoLink: 'p-echolink', RTP: 'p-rtp' };

	function s(tag, attrs, parent) {
		var e = document.createElementNS(NS, tag);
		for (var k in attrs) if (attrs[k] !== undefined && attrs[k] !== null) e.setAttribute(k, attrs[k]);
		if (parent) parent.appendChild(e);
		return e;
	}

	var PAD = 40; // room for labels on the outermost nodes
	var svg = s('svg', { viewBox: (-PAD) + ' 0 ' + (W + 2 * PAD) + ' ' + H, class: 'netsvg', role: 'img', 'aria-label': 'Live network diagram' }, box);
	var gEdges = s('g', {}, svg), gNodes = s('g', {}, svg);

	// Evenly spread n points on an arc (degrees; 0 = right, 90 = down).
	function arc(cx, n, from, to, r) {
		var out = [];
		for (var i = 0; i < n; i++) {
			var a = (from + (to - from) * (i + 0.5) / n) * Math.PI / 180;
			out.push([cx + r * Math.cos(a), CY + r * Math.sin(a)]);
		}
		return out;
	}

	function trunc(t, n) { t = String(t || ''); return t.length > n ? t.slice(0, n - 1) + '…' : t; }

	function shortConf(c) {
		var p = String(c || '').split(',').pop().trim();
		return p.length > 18 ? p.slice(0, 17) + '…' : p;
	}

	function model() {
		var nodes = [], edges = [];
		var rows = tlb ? tlb.rows : [];
		var bridge = null;
		if (asl && asl.ip) rows.forEach(function (r) { if (!bridge && r.ip === asl.ip) bridge = r; });
		var two = !!asl;
		var A = { id: 'hubA', x: two ? 110 : W / 2, y: CY, hub: true, label: 'TheLinkBox', sub: shortConf(tlb && tlb.conf), cls: 'hub' };
		nodes.push(A);

		var leaves = rows.filter(function (r) { return r !== bridge; });
		var rA = Math.min(115, 78 + leaves.length * 4);
		var pa = two ? arc(A.x, leaves.length, 105, 255, rA) : arc(A.x, leaves.length, -180, 180, rA); // one station sits to the right
		var tlbTalk = false;
		leaves.forEach(function (r, i) {
			var n = { id: 't:' + r.node, x: pa[i][0], y: pa[i][1], label: trunc(r.node, 12), sub: r.proto, cls: protoCls[r.proto] || 'p-other', tx: !!r.talking, link: r.link };
			nodes.push(n);
			edges.push({ id: 'e:' + n.id, a: n, b: A, flow: n.tx });
			tlbTalk = tlbTalk || n.tx;
		});

		if (two) {
			var B = { id: 'hubB', x: W - 110, y: CY, hub: true, label: 'Node ' + asl.node, sub: 'AllStar', cls: 'hub' };
			nodes.push(B);
			var list = asl.list || [];
			var rB = Math.min(115, 78 + list.length * 4);
			var pb = arc(B.x, list.length, -75, 75, rB);
			var aslTalk = !!asl.localTx;
			list.forEach(function (l, i) {
				var tx = asl.talking !== '' && String(asl.talking) === String(l.node);
				var n = { id: 'a:' + l.node, x: pb[i][0], y: pb[i][1], label: l.call || l.num || l.node, sub: l.call ? (l.num || l.node) : trunc(l.desc || l.mode, 16), cls: 'p-other', tx: tx,
					link: l.link || null };
				nodes.push(n);
				edges.push({ id: 'e:' + n.id, a: n, b: B, flow: tx });
				aslTalk = aslTalk || tx;
			});
			var bridgeRx = !!(bridge && bridge.talking);
			var inbound = aslTalk || bridgeRx;
			edges.push({ id: 'e:bridge', a: inbound ? B : A, b: inbound ? A : B, flow: inbound || tlbTalk, bridge: true,
				label: bridge ? bridge.node : 'not linked', down: !bridge });
			A.tx = tlbTalk || inbound;
			B.tx = inbound || tlbTalk;
		} else {
			A.tx = tlbTalk;
		}
		return { nodes: nodes, edges: edges, sig: (two ? '2|' : '1|') + nodes.map(function (n) { return n.id; }).join(',') + '|' + (edges.length) };
	}

	function build(m) {
		while (gEdges.firstChild) gEdges.removeChild(gEdges.firstChild);
		while (gNodes.firstChild) gNodes.removeChild(gNodes.firstChild);
		els = {};
		m.edges.forEach(function (e) {
			var line = s('line', { class: 'edge' + (e.bridge ? ' bridge' : '') + (e.down ? ' down' : '') }, gEdges);
			els[e.id] = { line: line };
			if (e.bridge) {
				var t = s('text', { class: 'elabel', x: (e.a.x + e.b.x) / 2, y: CY - 9 }, gEdges);
				t.textContent = e.label;
			}
		});
		if (!m.nodes.some(function (n) { return !n.hub; })) {
			var t = s('text', { class: 's2', x: W / 2, y: H - 20, 'text-anchor': 'middle' }, gEdges);
			t.textContent = 'Nobody is connected right now.';
		}
		m.nodes.forEach(function (n) {
			var g = s('g', { class: 'nd ' + n.cls + (n.hub ? ' hubnode' : ''), transform: 'translate(' + n.x.toFixed(1) + ',' + n.y.toFixed(1) + ')' }, gNodes);
			var r = n.hub ? 24 : 14;
			s('circle', { class: 'ring', r: r }, g);
			s('circle', { class: 'dotc', r: r }, g);
			var holder = g;
			if (n.link) holder = s('a', { href: n.link, target: '_blank', rel: 'noopener' }, g);
			var lab = s('text', { class: 'l1', y: r + 14 }, holder);
			lab.textContent = n.label;
			if (n.sub) { var sub = s('text', { class: 's2', y: r + 26 }, holder); sub.textContent = n.sub; }
			if (n.hub) { var ic = s('text', { class: 'hubic', y: 5 }, g); ic.textContent = n.id === 'hubA' ? 'TLB' : 'ASL'; }
			var tt = s('title', {}, g);
			tt.textContent = n.label + (n.sub ? ' (' + n.sub + ')' : '');
			els[n.id] = { g: g, base: 'nd ' + n.cls + (n.hub ? ' hubnode' : '') };
		});
	}

	function render() {
		if (!tlb) return;
		var m = model();
		if (m.sig !== sig) { build(m); sig = m.sig; }
		m.edges.forEach(function (e) {
			var el = els[e.id];
			if (!el) return;
			// the line is drawn toward where the audio is going, so the moving dashes show direction
			el.line.setAttribute('x1', e.a.x.toFixed(1)); el.line.setAttribute('y1', e.a.y.toFixed(1));
			el.line.setAttribute('x2', e.b.x.toFixed(1)); el.line.setAttribute('y2', e.b.y.toFixed(1));
			el.line.setAttribute('class', 'edge' + (e.bridge ? ' bridge' : '') + (e.down ? ' down' : '') + (e.flow ? ' flow' : ''));
		});
		m.nodes.forEach(function (n) {
			var el = els[n.id];
			if (el) el.g.setAttribute('class', el.base + (n.tx ? ' tx' : ''));
		});
	}

	document.addEventListener('tlb:data', function (e) { tlb = e.detail; render(); });
	document.addEventListener('asl:data', function (e) { asl = e.detail; render(); });

	// Tabs: Live diagram / AllStar map
	var tabs = document.querySelectorAll('[data-tab]');
	Array.prototype.forEach.call(tabs, function (b) {
		b.addEventListener('click', function () {
			Array.prototype.forEach.call(tabs, function (x) {
				var on = x === b;
				x.classList.toggle('on', on);
				x.setAttribute('aria-selected', on ? 'true' : 'false');
				var panel = document.getElementById(x.getAttribute('data-tab'));
				if (panel) panel.hidden = !on;
			});
		});
	});
	var img = document.getElementById('asl-map-img');
	if (img) {
		var miss = function () { img.hidden = true; var m = document.getElementById('asl-map-missing'); if (m) m.hidden = false; };
		img.addEventListener('error', miss);
		if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) miss();
	}
})();
