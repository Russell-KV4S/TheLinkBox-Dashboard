<?php
// "Last heard" log: who talked, when, and for how long. The notifier writes it; heard.php serves it.
// One JSON-lines file per UTC day in /var/lib/tlb-admin/heard/, plus now.json for overs still in progress.
// An entry: n = TheLinkBox node, p = protocol, s/e = start/end (unix time), and for audio that came in
// over the AllStar link: l = 1, a = AllStar node that keyed up, c = its callsign, d = its description.
// Defines functions only.

const TLB_HEARD_DIR = '/var/lib/tlb-admin/heard';
const TLB_HEARD_NOW = '/var/lib/tlb-admin/heard/now.json';
const TLB_HEARD_WINDOW = 86400;  // the timeline covers the last 24 hours

function tlb_heard_file($t) { return TLB_HEARD_DIR.'/'.gmdate('Y-m-d', $t).'.jsonl'; }

function tlb_heard_dir() {
	if (!is_dir(TLB_HEARD_DIR)) @mkdir(TLB_HEARD_DIR, 0750, true);
	return is_dir(TLB_HEARD_DIR);
}

function tlb_heard_add(array $ev) {
	if (!tlb_heard_dir()) return;
	@file_put_contents(tlb_heard_file($ev['s']), json_encode($ev)."\n", FILE_APPEND | LOCK_EX);
}

// Overs in progress, written by the notifier whenever they change (and every few seconds as a heartbeat).
function tlb_heard_now_save(array $list) {
	if (!tlb_heard_dir()) return;
	@file_put_contents(TLB_HEARD_NOW.'.tmp', json_encode(array_values($list)), LOCK_EX);
	@rename(TLB_HEARD_NOW.'.tmp', TLB_HEARD_NOW);
}

function tlb_heard_now() {
	// a stale file means the notifier isn't running, so nothing it lists is really live
	if (!is_file(TLB_HEARD_NOW) || time() - filemtime(TLB_HEARD_NOW) > 30) return [];
	$d = @json_decode(@file_get_contents(TLB_HEARD_NOW), true);
	return is_array($d) ? $d : [];
}

// Delete day files older than $days.
function tlb_heard_prune($days) {
	$cut = gmdate('Y-m-d', time() - max(1, (int)$days) * 86400);
	foreach (glob(TLB_HEARD_DIR.'/*.jsonl') ?: [] as $f) {
		if (basename($f, '.jsonl') < $cut) @unlink($f);
	}
}

function tlb_heard_clear() {
	foreach (glob(TLB_HEARD_DIR.'/*.jsonl') ?: [] as $f) @unlink($f);
}

// Finished overs that ended at or after $since, oldest first.
function tlb_heard_read($since) {
	$out = [];
	foreach (glob(TLB_HEARD_DIR.'/*.jsonl') ?: [] as $f) {
		if (basename($f, '.jsonl') < gmdate('Y-m-d', $since)) continue;
		foreach (@file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
			$e = json_decode($line, true);
			if (is_array($e) && isset($e['n'], $e['s'], $e['e']) && $e['e'] >= $since) $out[] = $e;
		}
	}
	usort($out, function ($x, $y) { return $x['s'] - $y['s']; });
	return $out;
}

// How a log entry is shown: the station (grouping key, name, detail line, link).
function tlb_heard_who(array $e) {
	if (!empty($e['a'])) {
		$a = (string)$e['a'];
		$c = strtoupper((string)($e['c'] ?? ''));
		return ['k' => 'asl:'.$a, 'name' => $c !== '' ? $c.' ('.$a.')' : (!empty($e['d']) ? $e['d'] : 'AllStar '.$a),
			'sub' => 'AllStar '.$a.($c !== '' && !empty($e['d']) ? ' · '.$e['d'] : '').' · via '.$e['n'],
			'link' => $c !== '' ? 'https://www.qrz.com/db/'.rawurlencode($c) : 'https://stats.allstarlink.org/stats/'.rawurlencode($a),
			'p' => 'AllStar'];
	}
	return ['k' => (string)$e['n'], 'name' => (string)$e['n'], 'sub' => !empty($e['l']) ? 'AllStar link' : (string)($e['p'] ?? ''),
		'link' => tlb_node_link((string)$e['n']), 'p' => (string)($e['p'] ?? '')];
}

// Everything the "Last heard" section shows.
function tlb_heard_feed(array $s) {
	$now   = time();
	$since = $now - TLB_HEARD_WINDOW;
	$days  = max(1, (int)$s['heard_days']);
	$all   = tlb_heard_read($now - $days * 86400);
	$live  = tlb_heard_now();

	$stations = [];
	$events   = [];
	$add = function (array $e, $ongoing) use (&$stations, &$events, $since, $now) {
		$w = tlb_heard_who($e);
		$end = $ongoing ? $now : (int)$e['e'];
		$dur = max(1, $end - (int)$e['s']);
		if (!isset($stations[$w['k']])) {
			$stations[$w['k']] = $w + ['last' => 0, 'dur' => 0, 'live' => false, 'overs' => 0, 'total' => 0];
		}
		$st = &$stations[$w['k']];
		if ($end >= $st['last']) { $st['last'] = $end; $st['dur'] = $dur; $st['live'] = $ongoing; }
		if ($end >= $since) {
			$st['overs']++;
			$st['total'] += $end - max((int)$e['s'], $since);
			$events[] = ['k' => $w['k'], 's' => (int)$e['s'], 'e' => $ongoing ? null : $end];
		}
	};
	foreach ($all as $e) $add($e, false);
	foreach ($live as $e) if (isset($e['n'], $e['s'])) $add($e, true);

	$stations = array_values($stations);
	usort($stations, function ($x, $y) { return ($y['live'] - $x['live']) ?: ($y['last'] - $x['last']); });
	return ['now' => $now, 'since' => $since, 'days' => $days,
		'stations' => array_slice($stations, 0, 60), 'events' => array_slice($events, -3000)];
}
