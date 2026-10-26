<?php
require_once __DIR__.'/tlb-lib.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$d   = tlb_snapshot();
$set = tlb_load_settings();
$aslNode = preg_match('/^\d{3,7}$/', $set['asl_node']) ? $set['asl_node'] : '';
$viewerAlerts = !empty($set['viewer_alerts']);
$cls = ['IRLP' => 'p-irlp', 'EchoLink' => 'p-echolink', 'RTP' => 'p-rtp'];
$sys = $set['server_stats'] ? tlb_sysinfo() : null;

// Server health card: label, value, detail line, icon, colour.
function health_card($id, $label, $value, $sub, $icon, $tone) {
	$icons = [
		'cpu'  => '<rect x="5" y="5" width="14" height="14" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/>',
		'mem'  => '<path d="M6 19v-4M10 19v-8M14 19v-11M18 19v-14"/>',
		'disk' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
		'net'  => '<path d="M7 20V4M3 8l4-4 4 4M17 4v16M13 16l4 4 4-4"/>',
		'temp' => '<path d="M14 14.76V3.5a2.5 2.5 0 0 0-5 0v11.26a4.5 4.5 0 1 0 5 0z"/>',
	];
	echo '<div class="hs"><div><small>'.h($label).'</small><b id="hs-'.$id.'">'.h($value).'</b><span class="sub" id="hs-'.$id.'-sub">'.h($sub).'</span></div>',
		'<span class="hic '.$tone.'"><svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$icons[$icon].'</svg></span></div>';
}
function gb($b) { return number_format($b / 1073741824, 2); }
function pct($u) { return $u['total'] ? round($u['used'] / $u['total'] * 100).'%' : '-'; }
function rate($bps) { $k = $bps * 8 / 1000; return $k >= 1000 ? number_format($k / 1000, 1).' Mb/s' : number_format($k, $k < 10 ? 1 : 0).' kb/s'; }
?><!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<noscript><meta http-equiv="refresh" content="5"></noscript>
	<title><?= h($d['conf'] ?: 'TheLinkBox') ?></title>
	<link rel="stylesheet" href="style.css">
	<link rel="manifest" href="manifest.json">
	<link rel="icon" href="icon.svg" type="image/svg+xml">
	<meta name="theme-color" content="#0f1115">
	<meta name="apple-mobile-web-app-capable" content="yes">
</head>
<body>
<div class="wrap">
	<div class="top">
		<div class="brand"><span>TheLinkBox <span class="sub"><?= h($d['conf']) ?></span></span></div>
		<div style="display:flex;gap:10px;align-items:center">
			<span class="live" id="live"><span class="dot"></span>Live</span>
			<?php if ($viewerAlerts): ?><button type="button" class="pill" id="alerts-btn" aria-expanded="false" aria-controls="alerts-panel" hidden><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg>Alerts</button><?php endif; ?>
			<a class="pill" href="admin.php">Admin</a>
		</div>
	</div>
	<?php if ($viewerAlerts): ?><div id="alerts-panel" class="card alerts-panel" hidden></div><?php endif; ?>

	<div class="stats">
		<div class="stat"><small>Connected</small><b id="st-count"><?= (int)$d['count'] ?></b></div>
		<div class="stat"><small>Talking now</small><b id="st-talking" style="color:var(--accent)"><?= (int)$d['talking'] ?></b></div>
		<div class="stat"><small>Server uptime</small><b id="st-uptime" style="font-size:15px"><?= h($d['uptime'] ?: '-') ?></b></div>
		<div class="stat"><small>TLB version</small><b id="st-version"><?= h($d['version'] ?: '-') ?></b></div>
	</div>

	<div class="rows" id="rows">
	<?php if (!$d['rows']): ?>
		<div class="empty">Nobody is connected right now.</div>
	<?php endif; foreach ($d['rows'] as $r): $pc = $cls[$r['proto']] ?? 'p-other'; ?>
		<div class="row<?= $r['talking'] ? ' talk' : '' ?>">
			<span class="av <?= h($pc) ?>"><?= h(strtoupper(substr($r['proto'], 0, 2))) ?></span>
			<div>
				<b><?php if ($r['link']): ?><a href="<?= h($r['link']) ?>" target="_blank" rel="noopener"><?= h($r['node']) ?></a><?php else: echo h($r['node']); endif; ?></b>
				<div class="sub"><?= h($r['attrs'] ?: 'Idle') ?></div>
			</div>
			<span class="meta"><span class="chip <?= h($pc) ?>"><?= h($r['proto']) ?></span></span>
			<span class="meta"><?php if ($r['ip']): ?><a href="https://whois.arin.net/ui/query.do?queryinput=<?= h(urlencode($r['ip'])) ?>" target="_blank" rel="noopener"><?= h($r['ip']) ?></a><?php endif; ?></span>
			<span class="<?= $r['talking'] ? 'tk' : 'sub' ?>"><?= $r['talking'] ? 'Talking · ' : '' ?><?= h($r['time']) ?></span>
		</div>
	<?php endforeach; ?>
	</div>

	<?php if ($set['heard_public']): ?>
	<section class="heard" id="heard">
		<h2 class="sec-title">Last heard</h2>
		<div class="card tl-card">
			<div class="tl-head"><b>Last 24 hours</b><span class="sub" id="heard-detail">Tap a bar for details</span></div>
			<div class="tl" id="heard-tl"><div class="empty">Loading&hellip;</div></div>
		</div>
		<div class="rows" id="heard-rows"><div class="empty">Loading&hellip;</div></div>
		<button type="button" class="more" id="heard-more" hidden>Show all</button>
	</section>
	<?php endif; ?>

	<section class="asl">
		<?php if ($aslNode): ?>
		<h2 class="asl-title">AllStar node <?= h($aslNode) ?> <span id="asl-state" class="sub">Loading&hellip;</span></h2>
		<?php else: ?>
		<h2 class="asl-title">Network</h2>
		<?php endif; ?>
		<div class="asl-grid<?= $aslNode ? '' : ' one' ?>">
			<?php if ($aslNode): ?>
			<div id="asl" data-node="<?= h($aslNode) ?>"><div class="rows" id="asl-rows"><div class="empty">Loading&hellip;</div></div></div>
			<?php endif; ?>
			<div class="card side">
				<?php if ($aslNode): ?>
				<div class="tabs" role="tablist">
					<button type="button" class="tab on" role="tab" aria-selected="true" data-tab="net">Live diagram</button>
					<button type="button" class="tab" role="tab" aria-selected="false" data-tab="mapbox">AllStar map</button>
				</div>
				<?php endif; ?>
				<div id="net" class="net"></div>
				<?php if ($aslNode): ?>
				<div id="mapbox" class="mapbox" hidden>
					<img id="asl-map-img" src="asl-map.php" alt="AllStar network map for node <?= h($aslNode) ?>">
					<p id="asl-map-missing" class="sub" hidden>AllStarLink's map isn't available right now. It's drawn by their server and will show here once it's ready.</p>
					<a class="sub" href="https://stats.allstarlink.org/stats/<?= h($aslNode) ?>/" target="_blank" rel="noopener">Node <?= h($aslNode) ?> on AllStarLink</a>
				</div>
				<?php endif; ?>
			</div>
		</div>
	</section>

	<?php if ($sys): ?>
	<section class="health">
		<h2 class="sec-title">Server</h2>
		<div class="hstats">
			<?php health_card('cpu', 'CPU load', number_format($sys['load'], 2), 'Average (1m) · '.$sys['cores'].' core'.($sys['cores'] > 1 ? 's' : ''), 'cpu', 'ic-green');
			if (isset($sys['mem'])) health_card('mem', 'Memory', pct($sys['mem']), gb($sys['mem']['used']).' / '.gb($sys['mem']['total']).' GB', 'mem', 'ic-orange');
			if (isset($sys['disk'])) health_card('disk', 'Disk usage', pct($sys['disk']), gb($sys['disk']['used']).' / '.gb($sys['disk']['total']).' GB', 'disk', 'ic-violet');
			health_card('net', 'Network', isset($sys['net']) ? rate($sys['net']['rx'] + $sys['net']['tx']) : '-', isset($sys['net']) ? '↓ '.rate($sys['net']['rx']).' · ↑ '.rate($sys['net']['tx']) : 'Measuring…', 'net', 'ic-blue');
			if (isset($sys['temp'])) health_card('temp', 'Temperature', round($sys['temp']).'°C', 'CPU temp', 'temp', 'ic-red'); ?>
		</div>
	</section>
	<?php endif; ?>

	<details class="legend">
		<summary>What do the status labels mean?</summary>
		<dl><?php foreach ($d['legend'] as $k => $v) echo '<dt>'.h($k).'</dt><dd>'.h($v).'</dd>'; ?></dl>
		<p class="sub">IRLP nodes need <code>export ALLOW_EXPERIMENTAL_NODES=YES</code> in their environment file.</p>
	</details>

	<div class="foot"><a href="http://experimental.irlp.net" target="_blank" rel="noopener">IRLP experimental reflectors</a><?php if ($set['footer_url'] !== ''): ?><a href="<?= h($set['footer_url']) ?>" target="_blank" rel="noopener"><?= h($set['footer_text'] !== '' ? $set['footer_text'] : preg_replace('#^https?://(www\.)?#i', '', rtrim($set['footer_url'], '/'))) ?></a><?php endif; ?></div>
	<div class="foot">Dashboard design by Russell, KV4S &middot; <a href="https://github.com/Russell-KV4S/TheLinkBox-Dashboard" target="_blank" rel="noopener">GitHub</a> &middot; v<?= h(TLB_VERSION) ?></div>
</div>
<script src="net.js"></script>
<?php if ($viewerAlerts): ?><script src="alerts.js"></script><?php endif; ?>
<script src="app.js"></script>
<?php if ($aslNode): ?><script src="asl.js"></script><?php endif; ?>
<?php if ($set['heard_public']): ?><script src="heard.js"></script><?php endif; ?>
</body>
</html>
