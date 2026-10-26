<?php
// AllStar network map image for the configured node.
// AllStarLink can take a minute or more to draw it (or time out), so visitors always get the last good
// copy straight away and a background process refreshes it every 10 minutes with a long timeout.
require_once __DIR__.'/tlb-lib.php';

const MAP_MAX_AGE  = 600;  // refresh after 10 minutes
const MAP_LOCK_AGE = 180;  // one refresh attempt at a time

$isJpeg = function ($b) { return substr((string)$b, 0, 2) === "\xFF\xD8"; };

// Background refresh: php asl-map.php --refresh <node>
if (PHP_SAPI === 'cli') {
	if (($argv[1] ?? '') === '--refresh' && preg_match('/^\d{3,7}$/', $argv[2] ?? '')) {
		tlb_cached_get(sprintf(ASL_MAP_URL, $argv[2]), 0, 120, $isJpeg);
		@unlink(tlb_cache_path(sprintf(ASL_MAP_URL, $argv[2])).'.lock');
	}
	exit;
}

$s = tlb_load_settings();
$node = $s['asl_node'];
if (!preg_match('/^\d{3,7}$/', $node)) { http_response_code(404); exit; }

$url  = sprintf(ASL_MAP_URL, $node);
$file = tlb_cache_path($url);
$lock = $file.'.lock';
if (!tlb_cache_fresh($url, MAP_MAX_AGE) && !(is_file($lock) && time() - filemtime($lock) < MAP_LOCK_AGE)) {
	if (!is_dir(TLB_CACHE_DIR)) @mkdir(TLB_CACHE_DIR, 0750, true);
	@touch($lock);
	exec('php '.escapeshellarg(__FILE__).' --refresh '.escapeshellarg($node).' > /dev/null 2>&1 &');
}

$img = is_file($file) ? file_get_contents($file) : null;
if (!$isJpeg($img)) { http_response_code(404); exit; }
header('Content-Type: image/jpeg');
header('Cache-Control: public, max-age=300');
header('Last-Modified: '.gmdate('D, d M Y H:i:s', filemtime($file)).' GMT');
echo $img;
