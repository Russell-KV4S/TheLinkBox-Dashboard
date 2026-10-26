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

// Real-time feed from the node's Allmon3 dashboard. Returns null (not set up), ['ws' => url], or ['error' => text].
function tlb_asl_live(array $s) {
	$base = $s['asl_live_base'];
	$node = $s['asl_node'];
	if ($base === '' || $node === '') return null;
	$u = parse_url($base);
	if (!$u || ($u['scheme'] ?? '') !== 'https' || empty($u['host']) || !tlb_public_host($u['host'])) {
		return ['error' => 'The live feed address must be a public https address.'];
	}
	$j = json_decode((string)tlb_cached_get(rtrim($base, '/').'/master/node/'.$node.'/config', 600), true);
	$port = (int)($j['SUCCESS']['statport'] ?? 0);
	if ($port < 1) return ['error' => 'That Allmon3 does not report a live feed for node '.$node.'.'];
	return ['ws' => 'wss://'.$u['host'].(isset($u['port']) ? ':'.$u['port'] : '').rtrim($u['path'] ?? '', '/').'/ws/'.$port];
}

// Who and where a node is, from AllStarLink's public stats (cached for 6 hours).
function tlb_asl_nodeinfo($n) {
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
		if (!tlb_cache_fresh($url, 21600) && $fresh++ >= 6) { $info = ['call' => '', 'where' => '', 'site' => '']; }
		else { $info = tlb_asl_nodeinfo($n); }
		$out['links'][] = $info + ['node' => $n, 'mode' => $modeName[$modes[$n] ?? 'T'] ?? ''];
	}
	return $out;
}
