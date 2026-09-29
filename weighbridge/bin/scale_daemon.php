<?php
declare(strict_types=1);
/**
 * Scale reader daemon. Keeps the serial/TCP connection open, decodes weights and publishes the
 * latest reading into SQLite (live_weight). The web UI never touches the serial port itself.
 *
 *   php bin/scale_daemon.php            # run forever (use systemd / NSSM / Task Scheduler)
 *   php bin/scale_daemon.php --debug    # also print every reading
 */
require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
$debug = in_array('--debug', $argv, true);
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $s) { pcntl_signal($s, function () use (&$running) { $running = false; }); }
}

function publish(array $f): void
{
    static $cols = ['weight', 'unit', 'stable', 'raw', 'status', 'message', 'updated_at'];
    $sets = []; $args = [];
    foreach ($f as $k => $v) { $sets[] = "$k = ?"; $args[] = $v; }
    $sets[] = 'heartbeat = ?'; $args[] = microtime(true);
    Db::q('UPDATE live_weight SET ' . implode(', ', $sets) . ' WHERE id = 1', $args);
}

Db::migrate();
$reader = null; $cfgHash = ''; $lastCfgCheck = 0.0; $errUntil = 0.0;
echo "[" . date('c') . "] scale daemon started\n";

while ($running) {
    try {
        if (microtime(true) - $lastCfgCheck > 5) {           // hot-reload settings saved from the Setup page
            $lastCfgCheck = microtime(true);
            Settings::reset();
            $cfg = Settings::all();
            $h = md5(json_encode($cfg));
            if ($h !== $cfgHash) {
                $reader?->close(); $reader = new ScaleReader($cfg); $cfgHash = $h;
                echo "[" . date('c') . "] settings loaded: {$cfg['conn_type']} " . ($cfg['conn_type'] === 'serial' ? "{$cfg['serial_port']} {$cfg['baud']}-{$cfg['data_bits']}{$cfg['parity']}{$cfg['stop_bits']} {$cfg['protocol']}" : '') . "\n";
                publish(['status' => 'CONNECTING', 'message' => 'Opening connection']);
            }
        }
        if (microtime(true) < $errUntil) { publish(['status' => 'ERROR']); usleep(500000); continue; }

        $r = $reader->poll();
        if ($r) {
            publish(['weight' => $r['weight'], 'unit' => 'kg', 'stable' => $r['stable'] ? 1 : 0, 'raw' => $r['raw'],
                     'status' => 'RUNNING', 'message' => null, 'updated_at' => microtime(true)]);
            if ($debug) { echo sprintf("%s  %10.2f kg  %s  | %s\n", date('H:i:s'), $r['weight'], $r['stable'] ? 'STABLE  ' : 'motion  ', $r['raw']); }
        } else {
            publish([]);   // heartbeat only; updated_at stays old so the UI shows "no data"
        }
    } catch (Throwable $e) {
        $reader?->close();
        $reader = new ScaleReader(Settings::all());
        $errUntil = microtime(true) + 3;
        publish(['status' => 'ERROR', 'message' => mb_substr($e->getMessage(), 0, 300)]);
        echo "[" . date('c') . "] ERROR: " . $e->getMessage() . "\n";
    }
}
$reader?->close();
publish(['status' => 'STOPPED', 'message' => 'Daemon stopped']);
echo "[" . date('c') . "] scale daemon stopped\n";
