<?php
require __DIR__ . '/../_layout.php';
Auth::require(true);
Auth::checkCsrf();
session_write_close();
header('Content-Type: application/json');
set_time_limit(30);

/** Saved settings overlaid with unsaved form values, so "Test" works before "Save". */
function cfg_from_post(): array
{
    $c = Settings::all();
    foreach ($_POST as $k => $v) {
        if (array_key_exists($k, Settings::DEFAULTS) && is_string($v) && !(in_array($k, Settings::SECRETS, true) && $v === '')) { $c[$k] = $v; }
    }
    return $c;
}
function out(bool $ok, string $msg, array $extra = []): never { echo json_encode(['ok' => $ok, 'message' => $msg] + $extra); exit; }

try {
    $c = cfg_from_post();
    switch ($_POST['action'] ?? '') {
        case 'serial':
            $l = Weighment::live();
            if ($l['daemon_alive'] && $c['conn_type'] !== 'simulator') {
                out(false, 'The scale daemon is running and owns the port. Watch "Live data from daemon" on this page, or stop the daemon (systemctl stop weighbridge-scale / stop the service) and test again.');
            }
            if ($c['conn_type'] === 'simulator') { out(true, 'Simulator mode - no hardware to test.'); }
            $reader = null; $lines = [];
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
            $parser = new ScaleParser($c); $parsed = null; $tmp = $raw;
            $r = $parser->feed($raw);
            out(true, strlen($raw) . ' bytes received. ' . ($r ? "Parsed weight: {$r['weight']} kg" : 'Could not parse a weight - adjust terminator / weight regex.'),
                ['data' => substr($pretty, 0, 1500), 'hex' => strtoupper(bin2hex(substr($raw, 0, 120)))]);

        case 'oracle':
            out(true, (new OracleSync($c))->test());
        case 'sync':
            $r = OracleSync::syncPending(500);
            out(!$r['error'], "Sent {$r['ok']}, failed {$r['failed']}" . ($r['error'] ? ': ' . $r['error'] : ''));
        default: out(false, 'Unknown action');
    }
} catch (Throwable $e) { out(false, $e->getMessage()); }
