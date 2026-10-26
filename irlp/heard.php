<?php
// JSON feed for the "Last heard" section (written by the tlb-notify service).
require_once __DIR__.'/tlb-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$s = tlb_load_settings();
echo json_encode($s['heard_public'] ? tlb_heard_feed($s) : ['off' => true]);
