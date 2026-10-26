<?php
// Shared helpers for admin.php and the notifier. Defines functions only; safe if requested directly.

const TLB_STATE_DIR = '/var/lib/tlb-admin';
const TLB_SETTINGS  = '/var/lib/tlb-admin/settings.json';
const TLB_BIN       = '/usr/local/bin/tlbcmd';
const TLB_VERSION   = '2.4.0';   // dashboard release, shown in the page footer; see CHANGELOG.md

// Run one tlbcmd command and return its output without the leading numeric status line.
function tlb_cmd($text) {
	$out = (string)shell_exec(TLB_BIN.' -s '.escapeshellarg($text).' 2>&1');
	return trim(preg_replace('/^\d+\r?\n/', '', $out));
}

require_once __DIR__.'/tlb-channels.php';
require_once __DIR__.'/tlb-asl.php';
require_once __DIR__.'/tlb-heard.php';

function tlb_default_settings() {
	return tlb_channel_defaults() + [
		'server_label'  => 'TheLinkBox',
		'asl_node'      => '',
		'asl_live_base' => '',
		'viewer_alerts' => true,
		'on_connect'    => true,
		'on_disconnect' => true,
		'on_admin'      => true,
		'skip_lurkers'  => true,
		'skip_permanent'=> false,
		'heard_public'  => true,
		'heard_days'    => 7,
		'server_stats'  => true,
		'footer_url'    => '',
		'footer_text'   => '',
	];
}

function tlb_load_settings() {
	$d = @json_decode(@file_get_contents(TLB_SETTINGS), true);
	return array_merge(tlb_default_settings(), is_array($d) ? $d : []);
}

function tlb_save_settings(array $s) {
	$tmp = TLB_SETTINGS.'.tmp';
	if (file_put_contents($tmp, json_encode($s, JSON_PRETTY_PRINT), LOCK_EX) === false) return false;
	@chmod($tmp, 0640);
	return rename($tmp, TLB_SETTINGS);
}

// Current users as [node => ['attrs' => '...', 'time' => 'h:mm:ss']]
function tlb_users() {
	$out = shell_exec(TLB_BIN.' -s "users -q -c -T" 2>/dev/null');
	$users = [];
	foreach (preg_split("/\r?\n/", (string)$out) as $line) {
		if (preg_match('/^\d+\.\s+(\S+)\s+(?:([^\s,]+),\s*)?connected:\s*(\S+)/', $line, $m)) {
			$users[$m[1]] = ['attrs' => $m[2], 'time' => $m[3]];
		}
	}
	return $users;
}

// Nodes currently sending audio into the conference. The port table lists "<node> Rx" for a node
// whose audio the conference is receiving, and "<node> Tx" for nodes it is transmitting to.
// (EchoLink stations never get the "T" flag on the users list, so this is the only signal for them.)
function tlb_talking() {
	$out = shell_exec(TLB_BIN.' -s "port -q" 2>/dev/null');
	$t = [];
	foreach (preg_split("/\r?\n/", (string)$out) as $line) {
		if (preg_match('/^\s+(\S+)\s+Rx\b/', $line, $m)) $t[$m[1]] = true;
	}
	return $t;
}

// node => protocol name (IRLP-style stnNNNN nodes come back as Speak Freely)
function tlb_protocols() {
	$out = shell_exec(TLB_BIN.' -s "showip -q" 2>/dev/null');
	$map = ['S' => 'IRLP', 'R' => 'RTP', 'E' => 'EchoLink'];
	$p = [];
	foreach (preg_split("/\r?\n/", (string)$out) as $line) {
		$f = preg_split('/\s+/', trim($line));
		if (count($f) >= 3) $p[$f[0]] = ['ip' => $f[1], 'proto' => $map[$f[2]] ?? 'Unknown'];
	}
	return $p;
}

// Where a node's name links to: IRLP status for stnNNNN nodes, QRZ for callsigns, else ''.
function tlb_node_link($node) {
	if (preg_match('/^stn(\d+)$/', $node, $m)) return 'https://status.irlp.net/index.php?PSTART=11&nodeid='.$m[1];
	$call = strtoupper(preg_replace('/-[LR]$/i', '', $node));
	return preg_match('/^[A-Z0-9]{1,3}[0-9][A-Z]{1,4}$/', $call) ? 'https://www.qrz.com/db/'.$call : '';
}

// Everything the public dashboard shows, as plain data (used by index.php and api.php).
function tlb_snapshot() {
	$attributes = [
		'A' => 'Administrator', 'a' => 'ADPCM codec', 'B' => 'theBridge', 'C' => 'Other conference',
		'c' => 'Chat suppressed', 'F' => 'Playing file', 'f' => 'Full duplex', 'K' => 'Kicked',
		'L' => 'Lurker', 'M' => 'Audio and text muted', 'm' => 'Audio muted', 'n' => 'Nailed up',
		'P' => 'Permanent', 'R' => 'Receive only', 'S' => 'Sysop', 'T' => 'Talking',
		't' => 'Text muted', 'u' => 'uLaw codec', 'x' => 'Inactive', '*' => 'Asterisk protocol',
		'0' => 'Speak Freely protocol', '1' => 'RTP protocol', '7' => 'G.726 codec', '!' => 'Old theBridge',
	];
	$users   = tlb_users();
	$protos  = $users ? tlb_protocols() : [];
	$talking = $users ? tlb_talking() : [];
	$rows = [];
	$tx = 0;
	$lookups = 0;   // new IRLP callsign lookups this request (a couple at most, so the page stays quick)
	foreach ($users as $node => $u) {
		if (strpos($u['attrs'], 'L') !== false) continue; // hide lurkers
		$p    = $protos[$node] ?? ['ip' => '', 'proto' => 'Unknown'];
		$isTx = isset($talking[$node]) || strpos($u['attrs'], 'T') !== false;
		$tx  += $isTx ? 1 : 0;
		$names = [];
		foreach (str_split($u['attrs']) as $c) if (isset($attributes[$c])) $names[] = $attributes[$c];
		$link = tlb_node_link($node);
		$row = ['node' => $node, 'proto' => $p['proto'], 'ip' => $p['ip'], 'time' => $u['time'],
			'talking' => $isTx, 'attrs' => implode(', ', $names), 'link' => $link];
		if (($num = tlb_irlp_num($node)) !== '') {
			$fetch = $lookups < 2 && tlb_irlp_stale($num);
			if ($fetch) $lookups++;
			$row += tlb_station_info($node, $fetch);   // call, where, num
		}
		$rows[] = $row;
	}
	$conf = trim(str_replace('"', '', (string)preg_replace('/^.*=/s', '', tlb_cmd('set ConferenceID'))));
	$up   = trim(str_replace('Uptime', '', tlb_cmd('uptime -q')), " :\r\n");
	$ver  = trim(str_replace(['thelinkbox V', ' on x86_64-pc-linux-gnu'], '', tlb_cmd('version')));
	return ['conf' => $conf, 'count' => count($rows), 'talking' => $tx, 'uptime' => $up, 'version' => $ver,
		'rows' => $rows, 'legend' => $attributes];
}

// ---- recent nodes (history for the admin node picker) ----
const TLB_RECENT_FILE = '/var/lib/tlb-admin/recent-nodes.json';
const TLB_NODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9-]{2,15}$/';

// Most recent first, [node => unix time].
function tlb_recent_list() {
	$d = @json_decode(@file_get_contents(TLB_RECENT_FILE), true);
	if (!is_array($d)) return [];
	arsort($d);
	return $d;
}

function tlb_recent_add($node) {
	if (!preg_match(TLB_NODE_PATTERN, $node)) return;
	$d = tlb_recent_list();
	$d[$node] = time();
	arsort($d);
	$d = array_slice($d, 0, 25, true);
	@file_put_contents(TLB_RECENT_FILE, json_encode($d), LOCK_EX);
}

// Entries on the ban or allow list as [callsign, host]. $which is 'ban' or 'allow'.
function tlb_acl_entries($which) {
	$out = tlb_cmd($which === 'ban' ? 'ban list' : 'allow list');
	$e = [];
	foreach (preg_split("/\r?\n/", $out) as $line) {
		$line = trim($line);
		if ($line === '' || preg_match('/(banned|allowed) users:$/i', $line) || stripos($line, 'list is empty') !== false) continue;
		$p = preg_split('/\s+/', $line, 2);
		$e[] = [$p[0], (isset($p[1]) && $p[1] !== '-') ? $p[1] : ''];
	}
	return $e;
}

// ---- server health (the cards at the bottom of the main page) ----
const TLB_NET_SAMPLE = '/var/lib/tlb-admin/cache/net-sample.json';

// CPU load, memory, disk, network traffic and (when the machine has a sensor) temperature.
function tlb_sysinfo() {
	$cores = max(1, (int)@shell_exec('nproc 2>/dev/null'));
	$load  = sys_getloadavg() ?: [0, 0, 0];
	$out = ['load' => round($load[0], 2), 'load5' => round($load[1], 2), 'cores' => $cores];

	$mem = [];
	foreach (@file('/proc/meminfo') ?: [] as $line) {
		if (preg_match('/^(MemTotal|MemAvailable):\s+(\d+)/', $line, $m)) $mem[$m[1]] = $m[2] * 1024;
	}
	if (!empty($mem['MemTotal'])) $out['mem'] = ['used' => $mem['MemTotal'] - ($mem['MemAvailable'] ?? 0), 'total' => $mem['MemTotal']];

	$dt = @disk_total_space('/');
	if ($dt) $out['disk'] = ['used' => $dt - (float)@disk_free_space('/'), 'total' => $dt];

	// network: bytes per second across real interfaces, from the change since the last sample
	$rx = $tx = 0;
	foreach (array_slice(@file('/proc/net/dev') ?: [], 2) as $line) {
		$p = preg_split('/[\s:]+/', trim($line));
		if (count($p) < 10 || $p[0] === 'lo' || strpos($p[0], 'docker') === 0 || strpos($p[0], 'veth') === 0) continue;
		$rx += (float)$p[1];
		$tx += (float)$p[9];
	}
	$now  = microtime(true);
	$prev = @json_decode(@file_get_contents(TLB_NET_SAMPLE), true);
	if (is_array($prev) && $now - $prev['t'] >= 1 && $now - $prev['t'] < 120 && $rx >= $prev['rx'] && $tx >= $prev['tx']) {
		$out['net'] = ['rx' => ($rx - $prev['rx']) / ($now - $prev['t']), 'tx' => ($tx - $prev['tx']) / ($now - $prev['t'])];
	} elseif (is_array($prev) && isset($prev['rate'])) {
		$out['net'] = $prev['rate'];
	}
	if (!is_array($prev) || $now - $prev['t'] >= 1) {
		if (!is_dir(dirname(TLB_NET_SAMPLE))) @mkdir(dirname(TLB_NET_SAMPLE), 0750, true);
		@file_put_contents(TLB_NET_SAMPLE, json_encode(['t' => $now, 'rx' => $rx, 'tx' => $tx, 'rate' => $out['net'] ?? null]), LOCK_EX);
	}

	foreach (glob('/sys/class/thermal/thermal_zone*/temp') ?: [] as $f) {
		$c = (int)@file_get_contents($f) / 1000;
		if ($c > 0 && $c < 150) { $out['temp'] = round($c, 1); break; }
	}
	return $out;
}
