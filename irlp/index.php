<?php
require_once __DIR__.'/tlb-lib.php';

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$d   = tlb_snapshot();
$set = tlb_load_settings();
$aslNode = preg_match('/^\d{3,7}$/', $set['asl_node']) ? $set['asl_node'] : '';
$viewerAlerts = !empty($set['viewer_alerts']);
$cls = ['IRLP' => 'p-irlp', 'EchoLink' => 'p-echolink', 'RTP' => 'p-rtp'];
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

	<details class="legend">
		<summary>What do the status labels mean?</summary>
		<dl><?php foreach ($d['legend'] as $k => $v) echo '<dt>'.h($k).'</dt><dd>'.h($v).'</dd>'; ?></dl>
		<p class="sub">IRLP nodes need <code>export ALLOW_EXPERIMENTAL_NODES=YES</code> in their environment file.</p>
	</details>

	<div class="foot"><a href="http://experimental.irlp.net" target="_blank" rel="noopener">IRLP experimental reflectors</a></div>
	<div class="foot">Dashboard design by Russell, KV4S &middot; <a href="https://github.com/Russell-KV4S/TheLinkBox-Dashboard" target="_blank" rel="noopener">GitHub</a></div>
</div>
<script src="net.js"></script>
<?php if ($viewerAlerts): ?><script src="alerts.js"></script><?php endif; ?>
<script src="app.js"></script>
<?php if ($aslNode): ?><script src="asl.js"></script><?php endif; ?>
</body>
</html>
