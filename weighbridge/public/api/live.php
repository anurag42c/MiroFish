<?php
require __DIR__ . '/../_layout.php';
if (!Auth::user()) { http_response_code(401); exit('{}'); }
session_write_close();
header('Content-Type: application/json'); header('Cache-Control: no-store');
$l = Weighment::live();
echo json_encode(['weight' => (float)($l['weight'] ?? 0), 'stable' => (int)($l['stable'] ?? 0), 'fresh' => $l['fresh'],
    'daemon_alive' => $l['daemon_alive'], 'status' => $l['status'] ?? '', 'message' => $l['message'] ?? '', 'raw' => $l['raw'] ?? '']);
