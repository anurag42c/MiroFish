<?php
// Unauthenticated, minimal: for uptime monitors. 200 = healthy, 503 = scale daemon down or DB error.
require_once __DIR__ . '/../../src/bootstrap.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
try {
    $l = Weighment::live();
    $pending = (int)Db::val("SELECT COUNT(*) FROM weighments WHERE status IN ('CLOSED','CANCELLED') AND sync_status IN ('PENDING','FAILED')");
    $ok = $l['daemon_alive'];
    http_response_code($ok ? 200 : 503);
    echo json_encode(['ok' => $ok, 'scale_daemon' => $l['daemon_alive'], 'scale_status' => $l['status'] ?? null, 'oracle_pending' => $pending]);
} catch (Throwable) { http_response_code(503); echo '{"ok":false}'; }
