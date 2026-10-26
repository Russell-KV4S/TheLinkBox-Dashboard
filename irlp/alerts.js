// Opt-in alerts for this viewer: a chime and/or a phone/desktop notification when someone starts
// talking, and a notification when a watched callsign (or anyone) connects. Settings are kept in this
// browser only. Fed by the "tlb:data" (app.js) and "asl:data" (asl.js) events.
(function () {
	var KEY = 'tlbAlerts';
	var btn = document.getElementById('alerts-btn');
	var panel = document.getElementById('alerts-panel');
	if (!btn || !panel) return;

	var cfg = load();
	var baseTitle = document.title;
	var audio = null, swReg = null, lastChime = 0, lastNotified = {};
	var prevTlb = null, prevTlbTalk = {}, tlbTalkers = [];
	var prevAsl = null, prevAslTalk = '', prevAslLocal = false, aslTalker = '', aslIp = '', aslNode = '', lastAslTalkAt = 0;

	function load() {
		var d = { on: true, chime: false, talk: false, connect: 'off', watch: '' };
		try { var s = JSON.parse(localStorage.getItem(KEY) || '{}'); for (var k in d) if (k in s) d[k] = s[k]; } catch (e) {}
		return d;
	}
	function save() { try { localStorage.setItem(KEY, JSON.stringify(cfg)); } catch (e) {} applyState(); }
	function active() { return cfg.on && (cfg.chime || cfg.talk || cfg.connect !== 'off'); }
	function applyState() {
		window.tlbKeepAlive = active(); // app.js / asl.js keep checking in a background tab
		btn.classList.toggle('on', active());
		btn.title = active() ? 'Alerts are on for this device' : 'Alerts are off for this device';
		var opts = document.getElementById('al-opts');
		if (opts) opts.classList.toggle('paused', !cfg.on);
	}

	// ---- sound ----
	function audioCtx() {
		var C = window.AudioContext || window.webkitAudioContext;
		if (!C) return null;
		if (!audio) audio = new C();
		if (audio.state === 'suspended') audio.resume();
		return audio;
	}
	// Browsers only allow sound after the visitor has touched the page once.
	document.addEventListener('pointerdown', function () { if (cfg.chime) audioCtx(); }, { passive: true });

	function chime() {
		var a = audioCtx();
		if (!a || a.state !== 'running') { status('Tap anywhere on the page once so it can play sounds.'); return; }
		[[880, 0], [1318.5, 0.14]].forEach(function (n) {
			var o = a.createOscillator(), g = a.createGain(), t = a.currentTime + n[1];
			o.type = 'sine';
			o.frequency.value = n[0];
			g.gain.setValueAtTime(0.0001, t);
			g.gain.exponentialRampToValueAtTime(0.2, t + 0.02);
			g.gain.exponentialRampToValueAtTime(0.0001, t + 0.38);
			o.connect(g); g.connect(a.destination);
			o.start(t); o.stop(t + 0.4);
		});
	}

	// ---- notifications ----
	function canNotify() { return 'Notification' in window && Notification.permission === 'granted'; }
	function registerSW() {
		if (swReg || !('serviceWorker' in navigator)) return;
		navigator.serviceWorker.register('sw.js').then(function (r) { swReg = r; }).catch(function () {});
	}
	function askPermission(done) {
		if (!('Notification' in window)) { status('This browser can\'t show notifications. On iPhone, add this page to your Home Screen first.'); return done(false); }
		if (Notification.permission === 'granted') { registerSW(); return done(true); }
		if (Notification.permission === 'denied') { status('Notifications are blocked for this site in your browser settings.'); return done(false); }
		var p = Notification.requestPermission(function (r) { finish(r); });
		if (p && p.then) p.then(finish);
		var called = false;
		function finish(r) {
			if (called) return; called = true;
			if (r === 'granted') { registerSW(); status(''); done(true); }
			else { status('Notifications weren\'t allowed, so only the chime will work.'); done(false); }
		}
	}
	// Only pop a notification when the page isn't in front of you; one per station per minute.
	function notify(title, body, tag) {
		if (!canNotify() || (!document.hidden && document.hasFocus())) return;
		var now = Date.now();
		if (lastNotified[tag] && now - lastNotified[tag] < 60000) return;
		lastNotified[tag] = now;
		var opts = { body: body, tag: tag, icon: 'icon.svg' };
		if (swReg) { swReg.showNotification(title, opts); return; }
		try {
			var n = new Notification(title, opts);
			n.onclick = function () { window.focus(); n.close(); };
		} catch (e) { // Android Chrome only allows notifications through a service worker
			if (navigator.serviceWorker) navigator.serviceWorker.ready.then(function (r) { r.showNotification(title, opts); });
		}
	}

	// ---- events ----
	function baseCall(c) { return String(c || '').toUpperCase().replace(/-[LR]$/, ''); }
	function watched(c) {
		var b = baseCall(c);
		return cfg.watch.toUpperCase().split(/[\s,;]+/).some(function (w) { return w && baseCall(w) === b; });
	}
	function talkStarted(label, where, key) {
		if (!cfg.on) return;
		var now = Date.now();
		if (cfg.chime && now - lastChime > 2500) { lastChime = now; chime(); }
		if (cfg.talk) notify(label + ' is talking', where, 'talk:' + key);
	}
	function connected(call, label, where, key) {
		if (!cfg.on) return;
		var hit = cfg.connect === 'any' || (cfg.connect === 'watch' && watched(call));
		if (!hit) return;
		if (cfg.chime) chime();
		notify(label + ' connected', where, 'conn:' + key);
	}
	function updateTitle() {
		var who = tlbTalkers.concat(aslTalker ? [aslTalker] : []);
		document.title = who.length ? '● ' + who.join(', ') + ' talking · ' + baseTitle : baseTitle;
	}

	document.addEventListener('tlb:data', function (e) {
		var d = e.detail, now = {}, talk = {}, conf = 'TheLinkBox' + (d.conf ? ' (' + d.conf.split(',').pop().trim() + ')' : '');
		tlbTalkers = [];
		d.rows.forEach(function (r) {
			now[r.node] = r;
			if (r.talking) { talk[r.node] = r; tlbTalkers.push(r.call || r.node); }
		});
		if (prevTlb) {
			Object.keys(talk).forEach(function (n) {
				if (prevTlbTalk[n]) return;
				var r = talk[n];
				if (aslIp && r.ip === aslIp) {
					// the AllStar bridge: name the AllStar station instead, if the live AllStar feed knows who it is
					setTimeout(function () { if (Date.now() - lastAslTalkAt > 4000) talkStarted(r.node + ' (AllStar)', conf, n); }, 1500);
				} else {
					talkStarted(r.call ? r.call + ' (' + r.node + ')' : r.node, r.proto + ' · ' + conf, n);
				}
			});
			Object.keys(now).forEach(function (n) {
				var c = now[n].call;
				if (!prevTlb[n]) connected(c || n, c ? c + ' (' + n + ')' : n, now[n].proto + ' · ' + conf, n);
			});
		}
		prevTlb = now; prevTlbTalk = talk;
		updateTitle();
	});

	document.addEventListener('asl:data', function (e) {
		var d = e.detail, now = {}, where = 'AllStar node ' + d.node;
		aslIp = d.ip; aslNode = d.node;
		d.list.forEach(function (l) { now[l.node] = l; });
		aslTalker = '';
		if (d.talking) {
			var l = now[d.talking] || { node: d.talking };
			aslTalker = l.call || l.node;
			lastAslTalkAt = Date.now();
			if (d.talking !== prevAslTalk) talkStarted(aslTalker, where, 'asl:' + d.talking);
		}
		if (d.localTx) {
			aslTalker = aslTalker || 'Node ' + d.node;
			lastAslTalkAt = Date.now();
			if (!prevAslLocal) talkStarted('Node ' + d.node + ' (local)', where, 'asl:local');
		}
		if (prevAsl) {
			Object.keys(now).forEach(function (n) {
				if (!prevAsl[n]) connected(now[n].call || n, now[n].call || 'Node ' + n, where, 'asl:' + n);
			});
		}
		prevAsl = now; prevAslTalk = d.talking || ''; prevAslLocal = d.localTx;
		updateTitle();
	});

	// ---- settings panel ----
	panel.innerHTML =
		'<h3>Alerts on this device</h3>' +
		'<label class="set master"><span>Alerts</span><input class="sw" type="checkbox" id="al-on"></label>' +
		'<div id="al-opts">' +
		'<label class="set"><span>Chime when someone starts talking</span><input class="sw" type="checkbox" id="al-chime"></label>' +
		'<label class="set"><span>Notify me when someone starts talking</span><input class="sw" type="checkbox" id="al-talk"></label>' +
		'<label class="fld" style="margin-top:12px">Notify me when someone connects' +
			'<select id="al-connect"><option value="off">Off</option><option value="watch">Only callsigns on my watch list</option><option value="any">Anyone</option></select></label>' +
		'<label class="fld">Watch list (callsigns, separated by commas)<input type="text" id="al-watch" placeholder="N4RGJ, W1AW" autocapitalize="characters" autocomplete="off"></label>' +
		'</div>' +
		'<div class="btns"><button type="button" id="al-test">Send a test</button><button type="button" class="primary" id="al-close">Done</button></div>' +
		'<p class="sub" id="al-status" role="status"></p>' +
		'<p class="sub">Alerts work while this page is open, even in a background tab (background tabs may check less often). ' +
		'Notifications appear when the page isn\'t in front of you. On iPhone, add this page to your Home Screen first.</p>';

	var $ = function (id) { return document.getElementById(id); };
	function status(t) { var s = $('al-status'); if (s) s.textContent = t; }
	$('al-on').checked = cfg.on;
	$('al-chime').checked = cfg.chime;
	$('al-talk').checked = cfg.talk;
	$('al-connect').value = cfg.connect;
	$('al-watch').value = cfg.watch;

	$('al-on').addEventListener('change', function () {
		cfg.on = this.checked; save();
		status(cfg.on ? '' : 'Alerts are paused on this device. Your choices are kept for when you turn them back on.');
	});
	$('al-chime').addEventListener('change', function () {
		cfg.chime = this.checked; save();
		if (cfg.chime) { audioCtx(); chime(); }
	});
	$('al-talk').addEventListener('change', function () {
		var el = this;
		if (!el.checked) { cfg.talk = false; return save(); }
		askPermission(function (ok) { cfg.talk = ok; el.checked = ok; save(); });
	});
	$('al-connect').addEventListener('change', function () {
		var el = this, v = el.value;
		if (v === 'off') { cfg.connect = 'off'; return save(); }
		askPermission(function (ok) { cfg.connect = ok ? v : 'off'; el.value = cfg.connect; save(); });
	});
	$('al-watch').addEventListener('input', function () { cfg.watch = this.value; save(); });
	$('al-test').addEventListener('click', function () {
		audioCtx(); chime();
		if (canNotify()) {
			var opts = { body: 'Alerts are working on this device.', tag: 'test', icon: 'icon.svg' };
			if (swReg) swReg.showNotification('Test alert', opts);
			else try { new Notification('Test alert', opts); } catch (e) {}
			status('Test sent. You should hear the chime and see a notification.');
		} else {
			status(cfg.talk || cfg.connect !== 'off' ? 'Notifications aren\'t allowed yet, so only the chime played.' : 'Chime played. Turn on a notification option to get pop-up alerts too.');
		}
	});
	function toggle(open) {
		panel.hidden = !open;
		btn.setAttribute('aria-expanded', open ? 'true' : 'false');
	}
	btn.addEventListener('click', function () { toggle(panel.hidden); });
	$('al-close').addEventListener('click', function () { toggle(false); });

	btn.hidden = false;
	applyState();
	if ((cfg.talk || cfg.connect !== 'off') && canNotify()) registerSW();
})();
