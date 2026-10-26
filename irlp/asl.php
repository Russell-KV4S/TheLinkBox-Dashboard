<?php
// JSON feed for the AllStar panel on the dashboard (public; derived from the node number saved in admin).
require_once __DIR__.'/tlb-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode(tlb_asl_snapshot(tlb_load_settings()));
