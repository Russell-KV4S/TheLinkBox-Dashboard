<?php
// AllStar panel helpers: cached public stats lookups, optional Allmon3 live-feed discovery,
// and the network map image. Defines functions only.

const TLB_CACHE_DIR = '/var/lib/tlb-admin/cache';
const ASL_STATS_URL = 'https://stats.allstarlink.org/api/stats/';
const ASL_MAP_URL   = 'https://stats.allstarlink.org/stats/%s/networkMap';

function tlb_cache_path($url) { return TLB_CACHE_DIR.'/'.sha1($url); }

function tlb_cache_fresh($url, $ttl) {
	$f = tlb_cache_path($url);
	return is_file($f) && time() - filemtime($f) < $ttl;
}

// GET with a file cache. Returns the body, the last good body if the fetch fails, or null.
// An unknown node (HTTP 404) is cached as "{}" so we don't keep asking. $accept, when given,
// must approve a body before it is cached (so an error page never replaces a good copy).
function tlb_cached_get($url, $ttl, $timeout = 6, $accept = null) {
	if (!empty($GLOBALS['tlb_cache_bypass'])) $ttl = 0;
	$f = tlb_cache_path($url);
	if ($ttl > 0 && tlb_cache_fresh($url, $ttl)) return file_get_contents($f);
	$ctx = stream_context_create(['http' => [
		'timeout' => $timeout, 'ignore_errors' => true,
		'header'  => "User-Agent: TheLinkBox-Dashboard/1.0\r\n",
	]]);
	$body = @file_get_contents($url, false, $ctx);
	$code = 0;
	if (!empty($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) $code = (int)$m[1];
	if ($code === 404 && !$accept) $body = '{}';
	if ($accept && ($body === false || !$accept($body))) $body = false;
	if ($body !== false && $body !== '' && ($code === 404 || ($code >= 200 && $code < 300))) {
		if (!is_dir(TLB_CACHE_DIR)) @mkdir(TLB_CACHE_DIR, 0750, true);
		@file_put_contents($f.'.tmp', $body, LOCK_EX);
		@rename($f.'.tmp', $f);
		return $body;
	}
	return is_file($f) ? file_get_contents($f) : null;
}

// True when the host resolves to a public address (keeps the admin-set URL from reaching internal services).
function tlb_public_host($host) {
	$ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
	if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) return false; // did not resolve
	return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

// What the notifier last read from an Allmon2 stream: ['list' => [{node, desc, mode, time}], 'keyed' => node or '']. Null when stale.
const TLB_ASL_FEED = TLB_CACHE_DIR.'/asl-feed.json';
function tlb_asl_feed_save(array $feed) {
	if (!is_dir(dirname(TLB_ASL_FEED))) @mkdir(dirname(TLB_ASL_FEED), 0750, true);
	@file_put_contents(TLB_ASL_FEED.'.tmp', json_encode($feed), LOCK_EX);
	@rename(TLB_ASL_FEED.'.tmp', TLB_ASL_FEED);
}
function tlb_asl_feed() {
	if (!is_file(TLB_ASL_FEED) || time() - filemtime(TLB_ASL_FEED) > 10) return null;
	$j = json_decode((string)@file_get_contents(TLB_ASL_FEED), true);
	return is_array($j) ? $j : null;
}

// Real-time feed from the node's Allmon3 (['ws' => url]) or Allmon2 (['sse' => url, 'feed' => data]) dashboard.
// Returns null (not set up) or ['error' => text] otherwise.
function tlb_asl_live(array $s) {
	$base = $s['asl_live_base'];
	$node = $s['asl_node'];
	if ($base === '' || $node === '') return null;
	$u = parse_url($base);
	if (!$u || ($u['scheme'] ?? '') !== 'https' || empty($u['host']) || !tlb_public_host($u['host'])) {
		return ['error' => 'The live feed address must be a public https address.'];
	}
	// Allmon2 / Supermon: no WebSocket, a server-sent-events stream that only the notifier reads (browsers get it via asl.php)
	if (preg_match('#/(allmon2|supermon2?)(/|$)#i', $u['path'] ?? '')) {
		$dir = preg_replace('#/[^/]*\.php$#', '', rtrim($u['path'], '/'));
		return ['sse' => 'https://'.$u['host'].(isset($u['port']) ? ':'.$u['port'] : '').$dir.'/server.php?nodes='.$node,
			'feed' => tlb_asl_feed()];
	}
	$j = json_decode((string)tlb_cached_get(rtrim($base, '/').'/master/node/'.$node.'/config', 600), true);
	$port = (int)($j['SUCCESS']['statport'] ?? 0);
	if ($port < 1) return ['error' => 'That Allmon3 does not report a live feed for node '.$node.'.'];
	return ['ws' => 'wss://'.$u['host'].(isset($u['port']) ? ':'.$u['port'] : '').rtrim($u['path'] ?? '', '/').'/ws/'.$port];
}

// AllStar shows an EchoLink station as "3" + its 6-digit EchoLink node number. Returns that number, or ''.
function tlb_echolink_num($n) {
	return preg_match('/^3(\d{6})$/', (string)$n, $m) ? $m[1] : '';
}

// EchoLink node number => [callsign, description] for everyone logged in, from EchoLink's public
// station list. The list (~800 KB) is fetched at most every 10 minutes and kept parsed in the cache;
// a failed fetch keeps the old copy and waits another 10 minutes before trying again.
const EL_LOGINS_URL = 'https://www.echolink.org/logins.jsp';
const TLB_EL_NODES  = TLB_CACHE_DIR.'/echolink-nodes.json';
function tlb_echolink_nodes() {
	static $map = null, $at = 0;   // kept for a minute (the notifier is long-running)
	if ($map !== null && time() - $at < 60) return $map;
	$at = time();
	$f = TLB_EL_NODES;
	if (!is_file($f) || time() - filemtime($f) >= 600) {
		if (!is_dir(TLB_CACHE_DIR)) @mkdir(TLB_CACHE_DIR, 0750, true);
		$lock = @fopen($f.'.lock', 'c');
		if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {   // one fetch at a time; others use the old copy
			clearstatcache(true, $f);
			if (!is_file($f) || time() - filemtime($f) >= 600) {
				$ctx = stream_context_create(['http' => ['timeout' => 10, 'header' => "User-Agent: TheLinkBox-Dashboard/1.0\r\n"]]);
				$body = (string)@file_get_contents(EL_LOGINS_URL, false, $ctx);
				$new = [];
				if (preg_match_all('#<tr>\s*<td>(.*?)</td>\s*<td>(.*?)</td>\s*<td>[^<]*</td>\s*<td[^>]*>[^<]*</td>\s*<td[^>]*>\s*(\d{1,6})\s*</td>#s', $body, $rows, PREG_SET_ORDER)) {
					foreach ($rows as $r) {
						$call = trim(html_entity_decode(strip_tags($r[1]), ENT_QUOTES, 'UTF-8'));
						$desc = trim(html_entity_decode(strip_tags($r[2]), ENT_QUOTES, 'UTF-8'));
						$desc = trim(preg_replace('/\s*\[\d+\/\d+\]$/', '', $desc));   // conference "[users/max]"
						if ($call !== '') $new[sprintf('%06d', $r[3])] = [$call, substr($desc, 0, 60)];
					}
				}
				if (count($new) > 100) { @file_put_contents($f.'.tmp', json_encode($new), LOCK_EX); @rename($f.'.tmp', $f); }
				elseif (is_file($f)) @touch($f);
				else @file_put_contents($f, '{}');
			}
			flock($lock, LOCK_UN);
		}
		if ($lock) fclose($lock);
	}
	$map = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
	if (!is_array($map)) $map = [];
	return $map;
}

// Who and where a node is, from AllStarLink's public stats (cached for 6 hours),
// or for EchoLink nodes from EchoLink's station list ('el' => EchoLink node number).
function tlb_asl_nodeinfo($n) {
	if (($el = tlb_echolink_num($n)) !== '') {
		$r = tlb_echolink_nodes()[$el] ?? ['', ''];
		return ['call' => $r[0], 'where' => '', 'site' => $r[1], 'el' => $el];
	}
	$j = json_decode((string)tlb_cached_get(ASL_STATS_URL.$n, 21600), true);
	$u = $j['stats']['user_node'] ?? ($j['node'] ?? []);
	$srv = $u['server'] ?? [];
	return [
		'call'  => (string)($u['callsign'] ?? ($u['User_ID'] ?? '')),
		'where' => (string)($srv['Location'] ?? ''),
		'site'  => (string)($srv['SiteName'] ?? ''),
	];
}

// Everything the AllStar panel needs, as plain data. Returns null when no node is configured.
function tlb_asl_snapshot(array $s) {
	$node = $s['asl_node'];
	if (!preg_match('/^\d{3,7}$/', $node)) return null;
	$j    = json_decode((string)tlb_cached_get(ASL_STATS_URL.$node, 45), true);
	$data = $j['stats']['data'] ?? null;
	$un   = $j['stats']['user_node'] ?? [];
	$out  = [
		'node'    => $node,
		'ip'      => (string)($un['ipaddr'] ?? ''),
		'desc'    => trim(($un['server']['SiteName'] ?? '').' '.($un['server']['Location'] ?? '')),
		'updated' => $j['stats']['updated_at'] ?? '',
		'links'   => [],
		'live'    => tlb_asl_live($s),
	];
	if (!$data) { $out['error'] = 'AllStarLink has no stats for node '.$node.'.'; return $out; }

	$modes = [];
	foreach (explode(',', (string)($data['nodes'] ?? '')) as $t) {
		if (preg_match('/^([A-Za-z])(\d+)$/', $t, $m)) $modes[$m[2]] = $m[1];
	}
	$modeName = ['T' => 'Transceive', 'R' => 'Receive only', 'C' => 'Connecting'];
	$fresh = 0;
	foreach (array_slice((array)($data['links'] ?? []), 0, 25) as $n) {
		$n = (string)$n;
		$url = ASL_STATS_URL.$n;
		// at most a few new lookups per request so the first load stays quick and polite
		if (tlb_echolink_num($n) === '' && !tlb_cache_fresh($url, 21600) && $fresh++ >= 6) { $info = ['call' => '', 'where' => '', 'site' => '']; }
		else { $info = tlb_asl_nodeinfo($n); }
		$out['links'][] = $info + ['node' => $n, 'mode' => $modeName[$modes[$n] ?? 'T'] ?? ''];
	}
	// EchoLink stations in the Allmon2 feed that AllStarLink's (slower) stats don't list yet
	$out['names'] = [];
	foreach ((array)($out['live']['feed']['list'] ?? []) as $r) {
		$n = (string)($r['node'] ?? '');
		if (tlb_echolink_num($n) !== '' && !in_array($n, (array)($data['links'] ?? []))) $out['names'][$n] = tlb_asl_nodeinfo($n) + ['node' => $n];
	}
	return $out;
}
