<?php
require __DIR__ . '/_layout.php';
Auth::require(true);

$tab = $_GET['tab'] ?? 'scale';
$tabs = ['scale' => 'Scale connection', 'oracle' => 'Oracle transfer', 'general' => 'General', 'users' => 'Users', 'diag' => 'Diagnostics'];
if (!isset($tabs[$tab])) { $tab = 'scale'; }

$fields = [
    'scale' => ['conn_type', 'serial_port', 'baud', 'data_bits', 'parity', 'stop_bits', 'tcp_host', 'tcp_port', 'protocol', 'terminator', 'request_cmd',
                'weight_regex', 'stable_regex', 'unstable_regex', 'stable_count', 'stable_tolerance', 'divisor', 'unit',
                'modbus_slave', 'modbus_func', 'modbus_addr', 'modbus_regs', 'modbus_word_order', 'modbus_poll_ms', 'min_capture_kg'],
    'oracle' => ['ora_host', 'ora_port', 'ora_service', 'ora_user', 'ora_pass', 'ora_table', 'ora_batch'],
    'general' => ['company_name', 'company_address', 'ticket_prefix', 'cam_url', 'cam_user', 'cam_pass'],
];
$checks = ['scale' => ['modbus_signed'], 'oracle' => ['ora_enabled'], 'general' => ['allow_manual']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    try {
        if (isset($fields[$tab]) && !isset($_POST['do'])) {
            foreach (['weight_regex', 'stable_regex', 'unstable_regex'] as $rx) {
                if (isset($_POST[$rx]) && ($err = ScaleParser::regexError(trim($_POST[$rx])))) { throw new RuntimeException("$rx: $err"); }
            }
            if (isset($_POST['serial_port']) && !valid_port(trim($_POST['serial_port']))) { throw new RuntimeException('Serial port must look like COM3 or /dev/ttyUSB0'); }
            if (isset($_POST['tcp_host']) && !valid_host(trim($_POST['tcp_host']))) { throw new RuntimeException('Invalid gateway host name / IP'); }
            if (isset($_POST['ora_table']) && !preg_match('/^[A-Za-z][A-Za-z0-9_$#]{0,29}(\.[A-Za-z][A-Za-z0-9_$#]{0,29})?$/', trim($_POST['ora_table']))) { throw new RuntimeException('Invalid Oracle table name'); }
            foreach ($fields[$tab] as $k) {
                if (!isset($_POST[$k])) { continue; }
                $v = trim((string)$_POST[$k]);
                if (in_array($k, Settings::SECRETS, true) && $v === '') { continue; }        // blank = keep existing secret
                if (in_array($k, ['terminator', 'request_cmd'], true)) { $v = (string)$_POST[$k]; }
                Settings::set($k, $v);
            }
            foreach ($checks[$tab] ?? [] as $k) { Settings::set($k, isset($_POST[$k]) ? '1' : '0'); }
            Db::audit('settings_save', $tab);
            flash('Settings saved. The scale daemon picks up changes within 5 seconds.');
        } elseif (($_POST['do'] ?? '') === 'apitoken') {
            $t = bin2hex(random_bytes(24)); Settings::set('api_token_hash', hash('sha256', $t));
            flash("New API token (shown once, copy it now): $t"); Db::audit('api_token_new');
        } elseif (($_POST['do'] ?? '') === 'apioff') {
            Settings::set('api_token_hash', ''); flash('API disabled.'); Db::audit('api_token_off');
        } elseif (($_POST['do'] ?? '') === 'adduser') {
            $u = trim($_POST['username']); if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $u) || strlen($_POST['password']) < 8) { throw new RuntimeException('Username 3-30 chars; password min 8.'); }
            Auth::createUser($u, $_POST['password'], $_POST['role'] === 'admin' ? 'admin' : 'operator'); flash('User added.');
        } elseif (($_POST['do'] ?? '') === 'toggle') {
            Db::q('UPDATE users SET active = 1 - active WHERE id = ? AND id <> ?', [(int)$_POST['id'], Auth::user()['id']]); flash('Updated.');
        } elseif (($_POST['do'] ?? '') === 'passwd') {
            if (strlen($_POST['password']) < 8) { throw new RuntimeException('Password min 8 chars.'); }
            Db::q('UPDATE users SET pass_hash = ? WHERE id = ?', [password_hash($_POST['password'], PASSWORD_DEFAULT), (int)$_POST['id']]); flash('Password changed.');
        }
    } catch (Throwable $e) { flash(str_contains($e->getMessage(), 'UNIQUE') ? 'Username exists.' : $e->getMessage(), 'err'); }
    redirect("setup.php?tab=$tab");
}

Settings::reset(); $s = Settings::all();
$sel = fn(string $k, array $opts) => implode('', array_map(fn($v, $l) => '<option value="' . e((string)$v) . '"' . ($s[$k] == $v ? ' selected' : '') . '>' . e($l) . '</option>', array_keys($opts), $opts));
$in = fn(string $k, string $type = 'text', string $extra = '') => '<input name="' . $k . '" type="' . $type . '" value="' . ($type === 'password' ? '' : e($s[$k])) . '" ' . $extra . '>';

page_head('Setup');
echo '<div class="tabs">'; foreach ($tabs as $k => $l) { echo '<a href="?tab=' . $k . '"' . ($k === $tab ? ' class="on"' : '') . '>' . $l . '</a>'; } echo '</div>';
?>
<form id="f" method="post"><?= csrf_field() ?>
<?php if ($tab === 'scale'): $ports = SerialPort::listPorts(); ?>
<div class="grid g2">
<div class="card"><h2>1. How is the indicator connected?</h2>
  <label>Connection type</label><select name="conn_type" id="conn_type"><?= $sel('conn_type', ['serial' => 'Serial port - RS-232 / RS-485 (COM / ttyUSB)', 'tcp' => 'TCP/IP (serial-to-Ethernet gateway)', 'simulator' => 'Simulator (no hardware, for testing)']) ?></select>
  <div class="ser">
    <label>Serial port</label><input name="serial_port" list="ports" value="<?= e($s['serial_port']) ?>">
    <datalist id="ports"><?php foreach ($ports as $p) echo '<option value="' . e($p) . '">'; ?></datalist>
    <div class="hint">Windows: COM3. Linux: /dev/ttyUSB0 (detected: <?= $ports ? e(implode(', ', $ports)) : 'none' ?>). RS-485 works the same through a USB-RS485 converter.</div>
    <div class="row"><div><label>Baud</label><select name="baud"><?= $sel('baud', array_combine([1200, 2400, 4800, 9600, 19200, 38400, 57600, 115200], [1200, 2400, 4800, 9600, 19200, 38400, 57600, 115200])) ?></select></div>
      <div><label>Data bits</label><select name="data_bits"><?= $sel('data_bits', [7 => 7, 8 => 8]) ?></select></div>
      <div><label>Parity</label><select name="parity"><?= $sel('parity', ['N' => 'None', 'E' => 'Even', 'O' => 'Odd']) ?></select></div>
      <div><label>Stop bits</label><select name="stop_bits"><?= $sel('stop_bits', [1 => 1, 2 => 2]) ?></select></div></div>
  </div>
  <div class="tcp"><div class="row"><div><label>Gateway IP / host</label><?= $in('tcp_host') ?></div><div><label>TCP port</label><?= $in('tcp_port', 'number') ?></div></div>
    <div class="hint">Set the gateway to "TCP server" mode with the same baud/parity as the indicator.</div></div>
  <label>Protocol</label><select name="protocol" id="protocol"><?= $sel('protocol', ['ascii' => 'ASCII continuous output (most indicators)', 'modbus_rtu' => 'Modbus RTU (RS-485 transmitters / load-cell amplifiers)']) ?></select>
  <label>Minimum weight to accept (kg)</label><?= $in('min_capture_kg', 'number', 'step="any"') ?>
</div>

<div class="card ascii"><h2>2. ASCII frame &amp; weight parsing</h2>
  <label>Frame terminator</label><?= $in('terminator') ?><div class="hint">Escapes allowed: \r\n = CR LF, \n, \r, \x03 (ETX).</div>
  <label>Request command (optional - poll-mode indicators)</label><?= $in('request_cmd') ?><div class="hint">Sent before each read, e.g. <code>W\r\n</code>. Leave empty for continuous output.</div>
  <label>Weight regex (group 1 = number, group 2 = unit)</label><?= $in('weight_regex') ?>
  <div class="hint">Default handles <code>ST,GS,+0012340kg</code>, <code>  12340 kg</code>, <code>=0012340</code>. Use the test below to check.</div>
  <div class="row"><div><label>Stable regex</label><?= $in('stable_regex') ?></div><div><label>Motion regex</label><?= $in('unstable_regex') ?></div></div>
  <div class="hint">If your indicator sends a flag, e.g. Stable <code>/\bST\b/</code>, Motion <code>/\bUS\b/</code>. Otherwise leave both empty and the software detects stability itself:</div>
  <div class="row"><div><label>Same reading N times</label><?= $in('stable_count', 'number', 'min="2"') ?></div><div><label>Tolerance (kg)</label><?= $in('stable_tolerance', 'number', 'step="any"') ?></div></div>
  <div class="row"><div><label>Divisor (implied decimals)</label><?= $in('divisor', 'number', 'step="any"') ?></div><div><label>Default unit</label><select name="unit"><?= $sel('unit', ['kg' => 'kg', 'g' => 'g', 't' => 't']) ?></select></div></div>
</div>

<div class="card modbus"><h2>2. Modbus RTU (RS-485)</h2>
  <div class="row"><div><label>Slave ID</label><?= $in('modbus_slave', 'number') ?></div><div><label>Function</label><select name="modbus_func"><?= $sel('modbus_func', [3 => '03 Holding regs', 4 => '04 Input regs']) ?></select></div></div>
  <div class="row"><div><label>Start address</label><?= $in('modbus_addr', 'number') ?></div><div><label>Registers</label><select name="modbus_regs"><?= $sel('modbus_regs', [1 => '1 (16-bit)', 2 => '2 (32-bit)']) ?></select></div></div>
  <div class="row"><div><label>Word order (32-bit)</label><select name="modbus_word_order"><?= $sel('modbus_word_order', ['big' => 'High word first', 'little' => 'Low word first']) ?></select></div><div><label>Poll every (ms)</label><?= $in('modbus_poll_ms', 'number') ?></div></div>
  <label style="text-transform:none;font-weight:400"><input type="checkbox" name="modbus_signed" value="1" <?= $s['modbus_signed'] === '1' ? 'checked' : '' ?>> Signed value</label>
  <div class="row"><div><label>Divisor</label><?= $in('divisor', 'number', 'step="any" disabled') ?></div></div>
  <div class="hint">Divisor and stability settings are shared with the ASCII panel (use the same values; switch protocol to edit). Address is 0-based (register 40001 = address 0).</div>
</div>
</div>
<p><button>Save</button> <button type="button" class="sec" data-test="serial">Test connection (3 s)</button></p>
<pre class="log" id="out" style="display:none"></pre>

<div class="card"><h2>Live data from daemon</h2>
  <div class="display" data-live="weight" style="font-size:36px">---<small>kg</small></div>
  <div class="statusline"><span class="badge" data-live="stable">...</span><span data-live="msg"></span><span style="margin-left:auto">Raw: <code data-live="raw"></code></span></div></div>

<?php elseif ($tab === 'oracle'): $drv = OracleSync::available(); ?>
<div class="card" style="max-width:640px"><h2>Oracle database</h2>
  <p>PHP Oracle driver: <span class="badge <?= $drv ? 'ok' : 'bad' ?>"><?= e($drv ?: 'NOT INSTALLED') ?></span>
  <?php if (!$drv) echo '<span class="hint"> Install oci8 + Instant Client (README step 6) before enabling transfer.</span>'; ?></p>
  <label style="text-transform:none;font-weight:400"><input type="checkbox" name="ora_enabled" value="1" <?= $s['ora_enabled'] === '1' ? 'checked' : '' ?>> Enable automatic transfer of completed tickets to Oracle</label>
  <div class="row"><div><label>Host</label><?= $in('ora_host') ?></div><div><label>Port</label><?= $in('ora_port', 'number') ?></div></div>
  <label>Service name (not SID)</label><?= $in('ora_service') ?>
  <div class="row"><div><label>User</label><?= $in('ora_user', 'text', 'autocomplete="off"') ?></div>
    <div><label>Password <?= $s['ora_pass'] !== '' ? '(saved - leave blank to keep)' : '' ?></label><input name="ora_pass" type="password" autocomplete="new-password"></div></div>
  <div class="row"><div><label>Target table</label><?= $in('ora_table') ?></div><div><label>Batch size</label><?= $in('ora_batch', 'number') ?></div></div>
  <div class="hint">Create the table with <code>sql/oracle_schema.sql</code>. Tickets are pushed with MERGE keyed on TICKET_NO, so retries never duplicate. If Oracle is down tickets stay PENDING and are sent when it returns.</div>
  <p><button>Save</button> <button type="button" class="sec" data-test="oracle">Test Oracle connection</button> <button type="button" class="ok" data-test="sync">Send pending now</button></p>
  <pre class="log" id="out" style="display:none"></pre>
</div>

<?php elseif ($tab === 'general'): ?>
<div class="card" style="max-width:560px"><h2>General</h2>
  <label>Company name</label><?= $in('company_name') ?><label>Address (printed on slip)</label><textarea name="company_address" rows="2"><?= e($s['company_address']) ?></textarea>
  <label>Ticket prefix</label><?= $in('ticket_prefix') ?>
  <label style="text-transform:none;font-weight:400"><input type="checkbox" name="allow_manual" value="1" <?= $s['allow_manual'] === '1' ? 'checked' : '' ?>> Allow manual weight entry when scale is unavailable (flagged on the slip)</label>
  <h2 style="margin-top:18px">Camera snapshot (optional)</h2>
  <label>Snapshot URL (JPEG)</label><?= $in('cam_url') ?><div class="hint">e.g. Hikvision <code>http://192.168.1.64/ISAPI/Streaming/channels/101/picture</code>. A photo is saved with each weighment as evidence.</div>
  <div class="row"><div><label>Camera user</label><?= $in('cam_user', 'text', 'autocomplete="off"') ?></div><div><label>Camera password <?= $s['cam_pass'] !== '' ? '(saved)' : '' ?></label><input name="cam_pass" type="password" autocomplete="new-password"></div></div>
  <p><button>Save</button></p></div>
<div class="card" style="max-width:560px"><h2>ERP REST API</h2>
  <p class="hint">Read-only endpoint <code>api/v1/tickets.php?since_id=N</code> with header <code>Authorization: Bearer &lt;token&gt;</code>. Status: <b><?= $s['api_token_hash'] !== '' ? 'token set' : 'disabled' ?></b></p>
  <button name="do" value="apitoken" formaction="setup.php?tab=general">Generate new token</button> <button name="do" value="apioff" class="sec" formaction="setup.php?tab=general">Disable API</button></div>
<?php endif; ?>
</form>

<?php if ($tab === 'users'): ?>
<div class="grid g2"><div class="card"><h2>Users</h2><table><tr><th>User</th><th>Role</th><th>Active</th><th></th></tr>
<?php foreach (Db::all('SELECT * FROM users ORDER BY username') as $u): ?>
<tr><td><?= e($u['username']) ?></td><td><?= e($u['role']) ?></td><td><?= $u['active'] ? 'yes' : 'no' ?></td><td style="display:flex;gap:4px">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="sec">Enable/Disable</button></form>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="passwd"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="password" value=""><button class="sec" onclick="var p=prompt('New password (min 8)');if(!p)return false;this.form.password.value=p">Set password</button></form>
</td></tr><?php endforeach; ?></table></div>
<div class="card"><h2>Add user</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="adduser">
<label>Username</label><input name="username" required><label>Password</label><input name="password" type="password" required minlength="8">
<label>Role</label><select name="role"><option value="operator">Operator</option><option value="admin">Admin</option></select><p><button>Add</button></p></form></div></div>

<?php elseif ($tab === 'diag'): $l = Weighment::live(); ?>
<div class="card"><h2>System</h2><table>
<tr><td>PHP</td><td><?= PHP_VERSION ?> (<?= PHP_OS_FAMILY ?>)</td></tr>
<tr><td>Extensions</td><td>pdo_sqlite <?= extension_loaded('pdo_sqlite') ? '&#10003;' : '&#10007;' ?> &middot; sodium <?= extension_loaded('sodium') ? '&#10003;' : '&#10007;' ?> &middot; oci8 <?= function_exists('oci_connect') ? '&#10003;' : '&#10007;' ?> &middot; pdo_oci <?= extension_loaded('pdo_oci') ? '&#10003;' : '&#10007;' ?></td></tr>
<tr><td>Scale daemon</td><td><span class="badge <?= $l['daemon_alive'] ? 'ok' : 'bad' ?>"><?= $l['daemon_alive'] ? 'RUNNING' : 'NOT RUNNING' ?></span> status=<?= e($l['status'] ?? '') ?> <?= e($l['message'] ?? '') ?></td></tr>
<tr><td>Pending / failed Oracle</td><td><?= (int)Db::val("SELECT COUNT(*) FROM weighments WHERE status IN ('CLOSED','CANCELLED') AND sync_status IN ('PENDING','FAILED')") ?></td></tr>
<tr><td>Last sync error</td><td><?= e((string)Db::val("SELECT sync_error FROM weighments WHERE sync_error IS NOT NULL ORDER BY id DESC LIMIT 1")) ?></td></tr>
<tr><td>Database file</td><td><?= e(WB_DB) ?> (<?= number_format(filesize(WB_DB) / 1024) ?> KB)</td></tr></table></div>
<div class="card"><h2>Recent audit</h2><table><?php foreach (Db::all('SELECT * FROM audit ORDER BY id DESC LIMIT 25') as $a) echo '<tr><td>' . e($a['at']) . '</td><td>' . e($a['user']) . '</td><td>' . e($a['action']) . '</td><td>' . e($a['detail']) . '</td></tr>'; ?></table></div>
<?php endif; ?>

<script>
const $ = s => document.querySelector(s), $$ = s => document.querySelectorAll(s);
function vis() {
  const c = $('#conn_type'), p = $('#protocol'); if (!c) return;
  $$('.ser').forEach(e => e.style.display = c.value === 'serial' ? '' : 'none');
  $$('.tcp').forEach(e => e.style.display = c.value === 'tcp' ? '' : 'none');
  $$('.ascii').forEach(e => e.style.display = p.value === 'ascii' ? '' : 'none');
  $$('.modbus').forEach(e => e.style.display = p.value === 'modbus_rtu' ? '' : 'none');
}
document.addEventListener('change', vis); vis();
$$('[data-test]').forEach(b => b.addEventListener('click', async () => {
  const out = $('#out'); out.style.display = 'block'; out.textContent = 'Working...'; b.disabled = true;
  const fd = new FormData($('#f')); fd.set('action', b.dataset.test);
  try {
    const r = await (await fetch('api/setup_test.php', {method: 'POST', body: fd})).json();
    out.textContent = (r.ok ? 'OK: ' : 'FAILED: ') + r.message + (r.data ? '\n\n' + r.data : '') + (r.hex ? '\n\nHEX: ' + r.hex : '');
    out.style.borderLeft = '4px solid ' + (r.ok ? '#178a4a' : '#c62828');
  } catch (e) { out.textContent = 'Request failed: ' + e; }
  b.disabled = false;
}));
</script>
<?php page_foot();
