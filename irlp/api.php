<?php
// JSON feed for the dashboard's live updates. Same information the public page already shows.
require_once __DIR__.'/tlb-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$s = tlb_snapshot();
unset($s['legend']);
if (tlb_load_settings()['server_stats']) $s['sys'] = tlb_sysinfo();
echo json_encode($s);
