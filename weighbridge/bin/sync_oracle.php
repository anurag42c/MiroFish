<?php
declare(strict_types=1);
/** Push pending tickets to Oracle. Run from cron / Task Scheduler every minute, or loop with --loop=30 */
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }

Db::migrate();
$loop = 0;
foreach ($argv as $a) { if (preg_match('/^--loop=(\d+)$/', $a, $m)) { $loop = max(5, (int)$m[1]); } }
do {
    Settings::reset();
    $r = OracleSync::syncPending();
    echo sprintf("[%s] synced=%d failed=%d%s\n", date('c'), $r['ok'], $r['failed'], $r['error'] ? ' error=' . $r['error'] : '');
    if ($loop) { sleep($loop); }
} while ($loop);
