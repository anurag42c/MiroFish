<?php
declare(strict_types=1);
/** Dependency-free tests:  php tests/run.php   (uses a throw-away data dir) */
$tmp = sys_get_temp_dir() . '/wbtest_' . bin2hex(random_bytes(4));
mkdir($tmp);
putenv("WB_DATA=$tmp");
require __DIR__ . '/../src/bootstrap.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$fail = 0; $n = 0;
function t(string $name, bool $ok, string $info = ''): void { global $fail, $n; $n++; if (!$ok) { $fail++; }; echo ($ok ? "  ok   " : "  FAIL ") . $name . ($ok ? '' : "  $info") . "\n"; }
function throws(callable $f): ?string { try { $f(); return null; } catch (Throwable $e) { return $e->getMessage(); } }
function setLive(float $w, bool $stable, string $status = 'RUNNING'): void {
    Db::q('UPDATE live_weight SET weight=?, unit="kg", stable=?, status=?, updated_at=?, heartbeat=? WHERE id=1', [$w, (int)$stable, $status, microtime(true), microtime(true)]);
}

Db::upgrade();
Settings::set('installed', '1');
$d = Settings::DEFAULTS;

echo "Parser\n";
$p = new ScaleParser($d);
foreach ([["ST,GS,+0012340kg\r\n", 12340.0], ["   12340 kg\r\n", 12340.0], ["US,GS,   950 g\r\n", 0.95], ["W 1.5 t\r\n", 1500.0]] as [$in, $exp]) {
    $r = $p->feed($in); t("frame " . trim($in), $r && abs($r['weight'] - $exp) < 0.001, json_encode($r));
}
$c = $d; $c['stable_regex'] = '/\bST\b/'; $c['unstable_regex'] = '/\bUS\b/'; $p = new ScaleParser($c);
$r = $p->feed("US,GS,+0000100kg\r\nST,GS,+0000200kg\r\n"); t('stable flag from last frame', $r['stable'] === true && $r['weight'] == 200.0);
$r = $p->feed("US,GS,+0000300kg\r\n"); t('motion flag', $r['stable'] === false);
$c = $d; $c['divisor'] = '10'; $r = (new ScaleParser($c))->feed("012340\r\n"); t('divisor', $r && $r['weight'] == 1234.0);
$c = $d; $c['terminator'] = '\x03'; $r = (new ScaleParser($c))->feed("\x02 5000 kg\x03"); t('ETX terminator', $r && $r['weight'] == 5000.0);
$p = new ScaleParser($d); $p->feed("  12"); $r = $p->feed("340 kg\r\n"); t('frame split across reads', $r && $r['weight'] == 12340.0);
t('bad regex detected', ScaleParser::regexError('bad(') !== null);
t('good regex ok', ScaleParser::regexError('/ST/') === null);

echo "Modbus\n";
t('request frame + CRC', bin2hex(Modbus::request(1, 3, 0, 2)) === '010300000002c40b');
$b = pack('CCCnn', 1, 3, 4, 1, 0x1F40); $b .= pack('v', Modbus::crc16($b));
t('32-bit big', Modbus::toNumber(Modbus::parse($b, 1, 3, 2), false, 'big') == 73536.0);
t('32-bit little word order', Modbus::toNumber([0x1F40, 1], false, 'little') == 73536.0);
t('signed 16', Modbus::toNumber([0xFFFF], true, 'big') == -1.0);
$bad = $b; $bad[5] = "\x00"; t('CRC error detected', str_contains((string)throws(fn() => Modbus::parse($bad, 1, 3, 2)), 'CRC'));
t('short reply detected', str_contains((string)throws(fn() => Modbus::parse('', 1, 3, 2)), 'short'));

echo "Input validation\n";
t('COM3 ok', valid_port('COM3')); t('ttyUSB0 ok', valid_port('/dev/ttyUSB0'));
t('shell injection rejected', !valid_port('COM1 & calc') && !valid_port('/dev/tty; rm -rf /'));
t('host ok', valid_host('192.168.1.5')); t('host injection rejected', !valid_host('a b;c'));

echo "Settings\n";
Settings::set('ora_pass', 'Secret#1');
t('secret encrypted at rest', str_starts_with((string)Db::val("SELECT v FROM settings WHERE k='ora_pass'"), 'enc:'));
Settings::reset(); t('secret round-trips', Settings::get('ora_pass') === 'Secret#1');

echo "Auth\n";
Auth::createUser('op', 'password1', 'operator');
t('login ok', Auth::login('op', 'password1'));
for ($i = 0; $i < 8; $i++) { Auth::login('nobody', 'x'); }
t('lockout after repeated failures', str_contains((string)throws(fn() => Auth::login('nobody', 'x')), 'Too many'));

echo "Weighment flow\n";
Settings::set('ora_enabled', '1'); Settings::set('min_capture_kg', '20');
setLive(30000, true);
t('daemon fresh', Weighment::live()['fresh'] === true);
$id = Weighment::create(['vehicle_no' => 'mh 12 ab 1234', 'party' => 'ACME', 'material' => 'Coal', 'first_type' => 'GROSS'], null);
$w = Db::one('SELECT * FROM weighments WHERE id=?', [$id]);
t('ticket created OPEN, vehicle normalised', $w['status'] === 'OPEN' && $w['vehicle_no'] === 'MH12AB1234' && $w['first_kg'] == 30000.0);
t('ticket number format', (bool)preg_match('/^WB\d{8}0001$/', $w['ticket_no']));
t('duplicate open ticket blocked', str_contains((string)throws(fn() => Weighment::create(['vehicle_no' => 'MH12AB1234'], null)), 'open ticket'));
setLive(9000, false);
t('unstable weight rejected', str_contains((string)throws(fn() => Weighment::second($id, null)), 'not stable'));
setLive(9000, true, 'ERROR');
t('scale error rejected', str_contains((string)throws(fn() => Weighment::second($id, null)), 'No live weight'));
setLive(5, true);
t('below minimum rejected', str_contains((string)throws(fn() => Weighment::second($id, null)), 'minimum'));
setLive(35000, true);
t('gross<=tare rejected', str_contains((string)throws(fn() => Weighment::second($id, null)), 'must be greater'));
setLive(9000, true); Weighment::second($id, null);
$w = Db::one('SELECT * FROM weighments WHERE id=?', [$id]);
t('closed with correct net', $w['status'] === 'CLOSED' && $w['gross_kg'] == 30000.0 && $w['tare_kg'] == 9000.0 && $w['net_kg'] == 21000.0);
t('queued for Oracle', $w['sync_status'] === 'PENDING');
t('vehicle tare learned', Db::val("SELECT tare_kg FROM vehicles WHERE reg_no='MH12AB1234'") == 9000.0);
t('manual weight disabled by default', str_contains((string)throws(fn() => Weighment::capture('1234')), 'disabled'));

setLive(31000, true);
$id2 = Weighment::create(['vehicle_no' => 'MH12AB1234', 'use_stored_tare' => '1'], null);
$w2 = Db::one('SELECT * FROM weighments WHERE id=?', [$id2]);
t('stored-tare single pass', $w2['status'] === 'CLOSED' && $w2['net_kg'] == 22000.0 && str_ends_with($w2['ticket_no'], '0002'));
t('stored tare missing blocked', str_contains((string)throws(fn() => Weighment::create(['vehicle_no' => 'NEWVEH1', 'use_stored_tare' => '1'], null)), 'No stored tare'));

setLive(8000, true);
$id3 = Weighment::create(['vehicle_no' => 'TARE1', 'first_type' => 'TARE'], null);
setLive(20000, true); Weighment::second($id3, null);
t('tare-first flow', Db::val('SELECT net_kg FROM weighments WHERE id=?', [$id3]) == 12000.0);

Db::q("UPDATE weighments SET sync_status='SYNCED' WHERE id=?", [$id]);
Weighment::cancel($id, 'wrong party');
$w = Db::one('SELECT status, sync_status FROM weighments WHERE id=?', [$id]);
t('cancelling a synced ticket re-queues it', $w['status'] === 'CANCELLED' && $w['sync_status'] === 'PENDING');
setLive(9000, true);
$idc = Weighment::create(['vehicle_no' => 'OPENCANCEL', 'first_type' => 'GROSS'], null);
Weighment::cancel($idc, 'x');
t('cancelling an open ticket needs no sync', Db::val('SELECT sync_status FROM weighments WHERE id=?', [$idc]) === 'NA');

echo "Oracle (no driver expected in CI)\n";
$r = OracleSync::syncPending(10);
t('sync degrades gracefully without driver', OracleSync::available() !== '' || ($r['error'] !== null && Db::val("SELECT COUNT(*) FROM weighments WHERE sync_status='PENDING'") >= 2));

echo "\n$n checks, $fail failed\n";
array_map('unlink', glob("$tmp/*.*") ?: []); @unlink("$tmp/app.key"); @rmdir($tmp);
exit($fail ? 1 : 0);
