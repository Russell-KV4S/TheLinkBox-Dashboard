<?php
// Watches TheLinkBox and
//  - sends alerts (Telegram, Slack, Discord, ntfy, Pushover, email, webhook) when nodes connect or disconnect;
//  - keeps the "Last heard" log: who talked, when and for how long. Audio that comes in over the
//    AllStar link is credited to the AllStar node that keyed up, read from the node's Allmon3 live feed.
// Settings are read fresh each cycle from the file the admin page writes, so changes take effect
// without restarting the service. Run by systemd as www-data (see tlb-notify.service).

if (php_sapi_name() !== 'cli') exit(1);

require getenv('TLB_LIB') ?: '/var/www/html/irlp/tlb-lib.php';

const TICK_SECS = 1;       // talk detection
const POLL_SECS = 2;       // connect/disconnect detection
const MISSING_GRACE = 4;   // seconds a user must be missing before we call it a disconnect (rides out tlbcmd hiccups)
const TALK_GAP = 2;        // seconds of silence that end an over (a one-tick dropout doesn't split it)
const ASL_KEY_HOLD = 4;    // seconds an AllStar key-up still names the talker after Allmon3 stops showing it

// Send to all enabled channels; log failures to the journal.
function send($s, $event, $msg) {
	foreach (tlb_notify($s, $event, $msg) as $ch => $r) {
		if (!$r[0]) fwrite(STDERR, "[$ch] ".$r[1]."\n");
	}
}

// ---- minimal WebSocket client for the Allmon3 live feed ----
// Allmon3 pushes its node status as JSON text frames about once a second; that is all this needs to handle.

function ws_open($url) {
	$u = parse_url($url);
	if (!$u || ($u['scheme'] ?? '') !== 'wss' || empty($u['host'])) return null;
	$host = $u['host'];
	$path = ($u['path'] ?? '/').(isset($u['query']) ? '?'.$u['query'] : '');
	$ctx  = stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true]]);
	$fp   = @stream_socket_client('ssl://'.$host.':'.($u['port'] ?? 443), $en, $es, 8, STREAM_CLIENT_CONNECT, $ctx);
	if (!$fp) return null;
	stream_set_timeout($fp, 8);
	$key = base64_encode(random_bytes(16));
	fwrite($fp, "GET $path HTTP/1.1\r\nHost: $host\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n".
		"Sec-WebSocket-Key: $key\r\nSec-WebSocket-Version: 13\r\nUser-Agent: TheLinkBox-Dashboard/1.0\r\n\r\n");
	$head = '';
	while (strpos($head, "\r\n\r\n") === false && strlen($head) < 16384) {
		$c = fread($fp, 2048);
		if ($c === false || $c === '') break;
		$head .= $c;
	}
	$end  = strpos($head, "\r\n\r\n");
	$want = base64_encode(sha1($key.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
	if ($end === false || !preg_match('#^HTTP/1\.[01] 101#', $head) || stripos(substr($head, 0, $end), $want) === false) {
		fclose($fp);
		return null;
	}
	stream_set_blocking($fp, false);
	return ['fp' => $fp, 'buf' => (string)substr($head, $end + 4), 'frag' => '', 'at' => time()];
}

function ws_send($fp, $op, $data) {
	$len  = strlen($data);
	$head = chr(0x80 | $op);
	if ($len < 126) $head .= chr(0x80 | $len);
	elseif ($len < 65536) $head .= chr(0x80 | 126).pack('n', $len);
	else $head .= chr(0x80 | 127).pack('J', $len);
	$mask = random_bytes(4);
	@fwrite($fp, $head.$mask.($data ^ substr(str_repeat($mask, intdiv($len, 4) + 1), 0, $len)));
}

// Take one complete frame off the front of $buf: [fin, opcode, payload], or null if it isn't all here yet.
function ws_frame(&$buf) {
	$n = strlen($buf);
	if ($n < 2) return null;
	$b0 = ord($buf[0]);
	$b1 = ord($buf[1]);
	$len = $b1 & 0x7f;
	$off = 2;
	if ($len === 126) { if ($n < 4) return null; $len = unpack('n', substr($buf, 2, 2))[1]; $off = 4; }
	elseif ($len === 127) { if ($n < 10) return null; $len = unpack('J', substr($buf, 2, 8))[1]; $off = 10; }
	$mask = '';
	if ($b1 & 0x80) { if ($n < $off + 4) return null; $mask = substr($buf, $off, 4); $off += 4; }
	if ($n < $off + $len) return null;
	$data = (string)substr($buf, $off, $len);
	if ($mask !== '') $data ^= substr(str_repeat($mask, intdiv($len, 4) + 1), 0, $len);
	$buf = (string)substr($buf, $off + $len);
	return [($b0 & 0x80) !== 0, $b0 & 0x0f, $data];
}

// Wait up to $wait seconds for data. Returns the complete text messages received (maybe none),
// or false when the connection has gone away.
function ws_read(array &$ws, $wait) {
	$r = [$ws['fp']];
	$w = $e = null;
	if ($ws['buf'] === '' && @stream_select($r, $w, $e, (int)$wait, (int)(($wait - (int)$wait) * 1000000)) === false) return false;
	while (($c = @fread($ws['fp'], 65536)) !== false && $c !== '') { $ws['buf'] .= $c; $ws['at'] = time(); }
	if (feof($ws['fp'])) return false;
	$out = [];
	while (($f = ws_frame($ws['buf'])) !== null) {
		list($fin, $op, $data) = $f;
		if ($op === 8) return false;
		if ($op === 9) { ws_send($ws['fp'], 10, $data); continue; }
		if ($op === 0 || $op === 1) {
			$ws['frag'] .= $data;
			if (strlen($ws['frag']) > 1048576) return false;
			if ($fin) { $out[] = $ws['frag']; $ws['frag'] = ''; }
		}
	}
	return $out;
}

function ws_close(&$ws) {
	if ($ws) { ws_send($ws['fp'], 8, ''); @fclose($ws['fp']); }
	$ws = null;
}

// ---- state ----
$known   = null;   // node => ['attrs','time','ip','proto','since']
$missing = [];     // node => first time seen missing
$users   = [];
$protos  = [];
$active  = [];     // overs in progress: key => ['n','p','a','c','d','s','seen']
$nowSaved = 0;     // when now.json was last written
$pruned  = 0;
$aslIp   = '';     // the AllStar node's IP, to spot which TheLinkBox connection is the AllStar link
$aslIpAt = 0;
$ws = null; $wsUrl = ''; $wsRetry = 0; $wsWait = 10;
$asl = ['node' => '', 'desc' => '', 'at' => 0];   // last AllStar node seen keyed up on Allmon3
$aslInfo = [];     // AllStar node => callsign
$tick = 0;

tlb_heard_now_save([]);   // overs from before a restart can't be finished, so don't show them as live

while (true) {
	$tickStart = microtime(true);
	$s   = tlb_load_settings();
	$now = time();

	// ---- connect/disconnect alerts (every POLL_SECS) ----
	if ($tick++ % POLL_SECS === 0) {
		$users = tlb_users();
		// tlbcmd returned nothing parseable and nobody was known: treat as empty. If it fails while
		// users are known we rely on MISSING_GRACE rather than announcing a mass disconnect instantly.
		$protos = $users ? tlb_protocols() : [];

		if ($known === null) {
			$known = [];
			foreach ($users as $n => $u) {
				$known[$n] = $u + ($protos[$n] ?? ['ip' => '', 'proto' => '']) + ['since' => $now];
			}
		} else {
			$skip = function ($attrs) use ($s) {
				if ($s['skip_lurkers'] && strpos($attrs, 'L') !== false) return true;
				if ($s['skip_permanent'] && strpos($attrs, 'P') !== false) return true;
				return false;
			};

			foreach ($users as $n => $u) {
				unset($missing[$n]);
				if (!isset($known[$n])) {
					$known[$n] = $u + ($protos[$n] ?? ['ip' => '', 'proto' => '']) + ['since' => $now];
					tlb_recent_add($n);
					if (tlb_any_channel_ready($s) && $s['on_connect'] && !$skip($u['attrs'])) {
						$msg = $s['server_label']." · connected\n".$n.
							($known[$n]['proto'] ? ' ('.$known[$n]['proto'].')' : '').
							($known[$n]['ip'] ? ' from '.$known[$n]['ip'] : '')."\nUsers now: ".count($users);
						send($s, 'connect', $msg);
					}
				}
			}

			foreach ($known as $n => $k) {
				if (isset($users[$n])) continue;
				if (!isset($missing[$n])) { $missing[$n] = $now; continue; }
				if ($now - $missing[$n] < MISSING_GRACE) continue;
				unset($known[$n], $missing[$n]);
				if (tlb_any_channel_ready($s) && $s['on_disconnect'] && !$skip($k['attrs'])) {
					$mins = max(0, round(($now - $k['since']) / 60));
					$msg = $s['server_label']." · disconnected\n".$n.
						($k['proto'] ? ' ('.$k['proto'].')' : '').' left after '.$mins."m\nUsers now: ".count($users);
					send($s, 'disconnect', $msg);
				}
			}
		}
	}

	// ---- Allmon3 live feed (only when an AllStar node and its Allmon3 address are set) ----
	$aslNode = preg_match('/^\d{3,7}$/', $s['asl_node']) ? $s['asl_node'] : '';
	if ($aslNode && $now - $aslIpAt > 300) {
		$j = json_decode((string)tlb_cached_get(ASL_STATS_URL.$aslNode, 3600), true);
		$aslIp = (string)($j['stats']['user_node']['ipaddr'] ?? '');
		$aslIpAt = $now;
	}
	$live = $aslNode ? tlb_asl_live($s) : null;
	$url  = $live['ws'] ?? '';
	if ($url !== $wsUrl) { ws_close($ws); $wsUrl = $url; $wsRetry = 0; $wsWait = 10; }
	if ($wsUrl && !$ws && $now >= $wsRetry) {
		$ws = ws_open($wsUrl);
		if ($ws) { $wsWait = 10; }
		else { $wsRetry = $now + $wsWait; $wsWait = min($wsWait * 2, 300); }
	}

	// ---- who is talking right now ----
	$talking = $users ? tlb_talking() : [];
	$heard = [];   // key => over details
	foreach ($users as $n => $u) {
		if (!isset($talking[$n]) && strpos($u['attrs'], 'T') === false) continue;
		$p = $protos[$n] ?? ['ip' => '', 'proto' => ''];
		$ev = ['n' => $n, 'p' => $p['proto']];
		if ($aslIp !== '' && $p['ip'] === $aslIp) {
			// audio arriving from AllStar: credit the AllStar node that keyed up, if Allmon3 says who
			$ev['l'] = 1;
			if ($asl['node'] !== '' && $now - $asl['at'] <= ASL_KEY_HOLD) {
				$a = $asl['node'];
				if (!isset($aslInfo[$a])) $aslInfo[$a] = tlb_asl_nodeinfo($a)['call'];
				$ev += ['a' => $a, 'c' => $aslInfo[$a], 'd' => $asl['desc']];
			}
			$heard[$n.'#'.($ev['a'] ?? '')] = $ev;
		} else {
			$heard[$n] = $ev;
		}
	}

	$changed = false;
	foreach ($heard as $k => $ev) {
		if (isset($active[$k])) { $active[$k]['seen'] = $now; continue; }
		// the AllStar link often starts receiving a moment before Allmon3 reports who keyed up:
		// name the over that just started instead of splitting it in two
		if (!empty($ev['a']) && isset($active[$ev['n'].'#']) && $now - $active[$ev['n'].'#']['s'] <= 3) {
			$active[$k] = $ev + ['s' => $active[$ev['n'].'#']['s'], 'seen' => $now];
			unset($active[$ev['n'].'#']);
		} else {
			$active[$k] = $ev + ['s' => $now, 'seen' => $now];
		}
		$changed = true;
	}
	foreach ($active as $k => $o) {
		if (isset($heard[$k]) || $now - $o['seen'] < TALK_GAP) continue;
		unset($active[$k]);
		$o['e'] = $o['seen'] + TICK_SECS;
		unset($o['seen']);
		tlb_heard_add($o);
		$changed = true;
	}
	if ($changed || $now - $nowSaved >= 10) {
		tlb_heard_now_save(array_map(function ($o) { unset($o['seen']); return $o; }, $active));
		$nowSaved = $now;
	}
	if ($now - $pruned > 3600) { tlb_heard_prune($s['heard_days']); $pruned = $now; }

	// ---- wait for the next tick, reading the Allmon3 feed meanwhile ----
	while (($left = TICK_SECS - (microtime(true) - $tickStart)) > 0.01) {
		if (!$ws) { usleep((int)($left * 1000000)); break; }
		$msgs = ws_read($ws, $left);
		if ($msgs === false || time() - $ws['at'] > 60) { ws_close($ws); $wsRetry = time() + 5; continue; }
		foreach ($msgs as $m) {
			$d = json_decode($m, true);
			$st = $d[$aslNode] ?? null;
			if (!is_array($st)) continue;
			if (($st['CONNKEYED'] ?? false) === true && !empty($st['CONNKEYEDNODE'])) {
				$kn = (string)$st['CONNKEYEDNODE'];
				$asl = ['node' => $kn, 'desc' => (string)($st['CONNS'][$kn]['DESC'] ?? ''), 'at' => time()];
			}
		}
	}
}
