<?php
/**
 * Read-only REST API for ERP integration.
 *   GET /api/v1/tickets.php?since_id=0&limit=100        Authorization: Bearer <token>
 * Token is created in Setup > General (stored only as a hash).
 */
require_once __DIR__ . '/../../../src/bootstrap.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
$hash = Settings::get('api_token_hash');
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if ($hash === '' || !preg_match('/^Bearer\s+(\S+)$/', $auth, $m) || !hash_equals((string)$hash, hash('sha256', $m[1]))) {
    http_response_code(401); exit('{"error":"unauthorized"}');
}
$since = max(0, (int)($_GET['since_id'] ?? 0)); $limit = max(1, min(500, (int)($_GET['limit'] ?? 100)));
$rows = Db::all("SELECT id, ticket_no, vehicle_no, party, material, direction, driver, challan_no, gross_kg, tare_kg, net_kg,
                 first_at, second_at, status, operator FROM weighments WHERE id > ? AND status IN ('CLOSED','CANCELLED') ORDER BY id LIMIT ?", [$since, $limit]);
echo json_encode(['count' => count($rows), 'tickets' => $rows], JSON_UNESCAPED_UNICODE);
