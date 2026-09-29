<?php
require __DIR__ . '/../_layout.php';
Auth::require(true);
Auth::checkCsrf();
session_write_close();
header('Content-Type: application/json');
set_time_limit(40);

/** Saved config overlaid with unsaved form values, so "Test" works before "Save". */
function cfg_from_post(int $scaleId): array
{
    $c = $scaleId ? (Scales::config($scaleId) ?? Settings::all()) : Settings::all();
    foreach ($_POST as $k => $v) {
        if (!is_string($v) || !array_key_exists($k, Settings::DEFAULTS)) { continue; }
        if (in_array($k, Settings::SECRETS, true) && $v === '') { continue; }
        $c[$k] = $v;
    }
    return $c;
}
function out(bool $ok, string $msg, array $extra = []): never { echo json_encode(['ok' => $ok, 'message' => $msg] + $extra); exit; }

try {
    if (in_array($_POST['action'] ?? '', ['gate_open', 'gate_close'], true)) {
        $gid = (int)($_POST['gate_id'] ?? 0);
        $c = Gates::config($gid) ?? out(false, 'Unknown gate.');
        foreach ($_POST as $k => $v) {
            if (is_string($v) && array_key_exists($k, Gates::DEFAULTS) && !(in_array($k, Gates::SECRET_KEYS, true) && $v === '')) { $c[$k] = $v; }
        }
        $c['auto_open'] = isset($_POST['auto_open']) ? '1' : '0'; $c['expect_reply'] = isset($_POST['expect_reply']) ? '1' : '0';
        $act = $_POST['action'] === 'gate_open' ? 'open' : 'close';
        Gates::execute($c, $act);
        Gates::log($gid, $c['gate_name'], 'test-' . $act, 'setup', true, 'OK (unsaved settings)');
        out(true, "Sent $act to '{$c['gate_name']}'. Watch the barrier. (State is not changed by a test.)");
    }
    $sid = (int)($_POST['scale_id'] ?? 0);
    $c = cfg_from_post($sid);
    switch ($_POST['action'] ?? '') {
        case 'serial':
            $l = Weighment::live($sid);
            if ($l['daemon_alive'] && $c['conn_type'] !== 'simulator') {
                out(false, 'This scale\'s reader is running and owns the port. Watch "Live data" below, or disable the scale (Setup > Scales), wait 10 s and test again.');
            }
            if ($c['conn_type'] === 'simulator') { out(true, 'Simulator mode - no hardware to test.'); }
            $lines = [];
            if ($c['protocol'] === 'modbus_rtu') {
                $reader = new ScaleReader($c);
                for ($i = 0; $i < 3; $i++) { $r = $reader->poll(); $lines[] = $r['raw'] . ' => ' . $r['weight'] . ' kg'; }
                $reader->close();
                out(true, 'Modbus reply OK.', ['data' => implode("\n", $lines)]);
            }
            $p = new SerialPort($c); $p->open();
            $cmd = ScaleParser::decodeEscapes($c['request_cmd']);
            $raw = ''; $end = microtime(true) + 3;
            while (microtime(true) < $end) { if ($cmd !== '') { $p->write($cmd); } $raw .= $p->read(250); }
            $p->close();
            if ($raw === '') { out(false, 'Port opened but NO data received in 3 s. Check cable/wiring (TX/RX/GND, A/B), baud & parity, that the indicator is set to continuous output, or set a Request command for poll-mode indicators.'); }
            $pretty = preg_replace_callback('/[^\x20-\x7E]/', fn($m) => sprintf('<%02X>', ord($m[0])), $raw);
            $r = (new ScaleParser($c))->feed($raw);
            out(true, strlen($raw) . ' bytes received. ' . ($r ? "Parsed weight: {$r['weight']} kg" : 'Could not parse a weight - adjust terminator / weight regex.'),
                ['data' => substr($pretty, 0, 1500), 'hex' => strtoupper(bin2hex(substr($raw, 0, 120)))]);

        case 'camera':
            $img = Camera::fetch($c);
            if ($img === null) { out(false, 'No JPEG from the camera URL (check URL, user/password, that it is a snapshot URL, and that curl is enabled).'); }
            if (!Anpr::enabled()) { out(true, 'Camera OK (' . number_format(strlen($img)) . ' bytes). ANPR is off - enable it in Setup > Plate recognition.'); }
            $r = Anpr::recognize($img);
            out($r['error'] === null && $r['plate'] !== null, $r['error'] ?? ($r['plate'] !== null ? "Camera OK. Plate: {$r['plate']} (" . round(($r['confidence'] ?? 1) * 100) . '%)' : 'Camera OK but no plate found in the picture.'));

        case 'anpr':
            $f = $_FILES['sample'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) { out(false, 'Choose a JPEG file first.'); }
            $img = (string)file_get_contents($f['tmp_name']);
            if (!str_starts_with($img, "\xFF\xD8") || strlen($img) > 8 * 1024 * 1024) { out(false, 'Not a JPEG (or larger than 8 MB).'); }
            $r = Anpr::recognize($img, array_intersect_key($c, array_flip(['anpr_provider', 'anpr_url', 'anpr_key', 'anpr_region', 'anpr_command'])));
            if ($r['error']) { out(false, $r['error']); }
            out($r['plate'] !== null, $r['plate'] !== null ? "Plate: {$r['plate']}" . ($r['confidence'] !== null ? ' (confidence ' . round($r['confidence'] * 100) . '%)' : '') : 'Service answered but found no plate.');

        case 'oracle':
            out(true, (new OracleSync($c))->test());
        case 'sync':
            $r = OracleSync::syncPending(500);
            out(!$r['error'], "Sent {$r['ok']}, failed {$r['failed']}" . ($r['error'] ? ': ' . $r['error'] : ''));
        default: out(false, 'Unknown action');
    }
} catch (Throwable $e) { out(false, $e->getMessage()); }
