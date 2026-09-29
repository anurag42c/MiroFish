<?php
require __DIR__ . '/_layout.php';
Auth::require(true);

$tab = $_GET['tab'] ?? 'scales';
$sid = (int)($_GET['id'] ?? 0);
$tabs = ['scales' => 'Scales', 'anpr' => 'Plate recognition', 'oracle' => 'Oracle transfer', 'general' => 'General', 'users' => 'Users', 'diag' => 'Diagnostics'];
if ($tab === 'scale') { if (!Scales::find($sid)) { $tab = 'scales'; } }
elseif (!isset($tabs[$tab])) { $tab = 'scales'; }

$fields = [
    'anpr' => ['anpr_provider', 'anpr_url', 'anpr_key', 'anpr_region', 'anpr_command', 'anpr_min_conf', 'anpr_policy'],
    'oracle' => ['ora_host', 'ora_port', 'ora_service', 'ora_user', 'ora_pass', 'ora_table', 'ora_batch'],
    'general' => ['company_name', 'company_address', 'ticket_prefix'],
];
$checks = ['anpr' => ['anpr_auto'], 'oracle' => ['ora_enabled'], 'general' => ['allow_manual']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    try {
        $do = $_POST['do'] ?? '';
        if ($tab === 'scale' && $do === '') {
            foreach (['weight_regex', 'stable_regex', 'unstable_regex'] as $rx) {
                if (isset($_POST[$rx]) && ($err = ScaleParser::regexError(trim($_POST[$rx])))) { throw new RuntimeException("$rx: $err"); }
            }
            if (($_POST['conn_type'] ?? '') === 'serial' && !valid_port(trim($_POST['serial_port'] ?? ''))) { throw new RuntimeException('Serial port must look like COM3 or /dev/ttyUSB0'); }
            if (($_POST['conn_type'] ?? '') === 'tcp' && !valid_host(trim($_POST['tcp_host'] ?? ''))) { throw new RuntimeException('Invalid gateway host name / IP'); }
            if (($_POST['cam_url'] ?? '') !== '' && !preg_match('#^https?://#i', trim($_POST['cam_url']))) { throw new RuntimeException('Camera URL must start with http:// or https://'); }
            $name = trim($_POST['name'] ?? '') ?: "Scale $sid";
            $cfg = [];
            foreach (Scales::KEYS as $k) { if (isset($_POST[$k])) { $cfg[$k] = in_array($k, ['terminator', 'request_cmd'], true) ? (string)$_POST[$k] : trim((string)$_POST[$k]); } }
            $cfg['modbus_signed'] = isset($_POST['modbus_signed']) ? '1' : '0';
            Scales::save($sid, $name, isset($_POST['enabled']), $cfg);
            Db::audit('scale_save', "$sid $name");
            flash('Saved. The reader picks up changes within 5 seconds (new scales start within 2 seconds).');
        } elseif ($do === 'addscale') {
            $n = trim($_POST['name'] ?? '') ?: 'New scale'; $id = Scales::create($n); flash("Scale added (disabled, Simulator). Configure it, then tick Enabled."); redirect("setup.php?tab=scale&id=$id");
        } elseif ($do === 'delscale') {
            Scales::delete((int)$_POST['id']); Db::audit('scale_delete', (string)(int)$_POST['id']); flash('Scale deleted.');
        } elseif ($do === 'toggle_scale') {
            $x = Scales::find((int)$_POST['id']); Scales::save((int)$x['id'], $x['name'], !$x['enabled'], []); flash('Updated.');
        } elseif (isset($fields[$tab]) && $do === '') {
            if (isset($_POST['ora_table']) && !preg_match('/^[A-Za-z][A-Za-z0-9_$#]{0,29}(\.[A-Za-z][A-Za-z0-9_$#]{0,29})?$/', trim($_POST['ora_table']))) { throw new RuntimeException('Invalid Oracle table name'); }
            if (($_POST['anpr_url'] ?? '') !== '' && !preg_match('#^https?://#i', trim($_POST['anpr_url']))) { throw new RuntimeException('ANPR URL must start with http:// or https://'); }
            foreach ($fields[$tab] as $k) {
                if (!isset($_POST[$k])) { continue; }
                $v = trim((string)$_POST[$k]);
                if (in_array($k, Settings::SECRETS, true) && $v === '') { continue; }        // blank = keep existing secret
                Settings::set($k, $v);
            }
            foreach ($checks[$tab] ?? [] as $k) { Settings::set($k, isset($_POST[$k]) ? '1' : '0'); }
            Db::audit('settings_save', $tab);
            flash('Settings saved.');
        } elseif ($do === 'apitoken') {
            $t = bin2hex(random_bytes(24)); Settings::set('api_token_hash', hash('sha256', $t));
            flash("New API token (shown once, copy it now): $t"); Db::audit('api_token_new');
        } elseif ($do === 'apioff') {
            Settings::set('api_token_hash', ''); flash('API disabled.'); Db::audit('api_token_off');
        } elseif ($do === 'adduser') {
            $u = trim($_POST['username']); if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $u) || strlen($_POST['password']) < 8) { throw new RuntimeException('Username 3-30 chars; password min 8.'); }
            Auth::createUser($u, $_POST['password'], $_POST['role'] === 'admin' ? 'admin' : 'operator'); flash('User added.');
        } elseif ($do === 'toggle') {
            Db::q('UPDATE users SET active = 1 - active WHERE id = ? AND id <> ?', [(int)$_POST['id'], Auth::user()['id']]); flash('Updated.');
        } elseif ($do === 'passwd') {
            if (strlen($_POST['password']) < 8) { throw new RuntimeException('Password min 8 chars.'); }
            Db::q('UPDATE users SET pass_hash = ? WHERE id = ?', [password_hash($_POST['password'], PASSWORD_DEFAULT), (int)$_POST['id']]); flash('Password changed.');
        }
    } catch (Throwable $e) { flash(str_contains($e->getMessage(), 'UNIQUE') ? 'Already exists.' : $e->getMessage(), 'err'); }
    redirect('setup.php?tab=' . $tab . ($tab === 'scale' ? "&id=$sid" : ''));
}

Settings::reset(); $s = $tab === 'scale' ? Scales::config($sid) : Settings::all();
$sel = fn(string $k, array $opts) => implode('', array_map(fn($v, $l) => '<option value="' . e((string)$v) . '"' . ($s[$k] == $v ? ' selected' : '') . '>' . e($l) . '</option>', array_keys($opts), $opts));
$in = fn(string $k, string $type = 'text', string $extra = '') => '<input name="' . $k . '" type="' . $type . '" value="' . ($type === 'password' ? '' : e($s[$k])) . '" ' . $extra . '>';

page_head('Setup');
echo '<div class="tabs">'; foreach ($tabs as $k => $l) { echo '<a href="?tab=' . $k . '"' . (($k === $tab || ($tab === 'scale' && $k === 'scales')) ? ' class="on"' : '') . '>' . $l . '</a>'; } echo '</div>';

if ($tab === 'scales'): ?>
<div class="card"><h2>Scales on this PC</h2>
<table><tr><th>#</th><th>Name</th><th>Connection</th><th>Camera</th><th>Reader</th><th></th></tr>
<?php foreach (Scales::all() as $sc): $c = Scales::config((int)$sc['id']); $l = Weighment::live((int)$sc['id']); ?>
<tr><td><?= (int)$sc['id'] ?></td><td><b><?= e($sc['name']) ?></b> <?= $sc['enabled'] ? '' : '<span class="badge">disabled</span>' ?></td>
  <td><?= e(['serial' => $c['serial_port'] . ' @' . $c['baud'], 'tcp' => $c['tcp_host'] . ':' . $c['tcp_port'], 'simulator' => 'Simulator'][$c['conn_type']] ?? '') ?> &middot; <?= e($c['protocol']) ?></td>
  <td><?= $c['cam_url'] !== '' ? 'yes' : '-' ?></td>
  <td><?php if ($sc['enabled']): ?><span class="badge <?= $l['fresh'] ? 'ok' : ($l['daemon_alive'] ? 'warn' : 'bad') ?>"><?= $l['fresh'] ? number_format((float)$l['weight']) . ' kg' : ($l['daemon_alive'] ? e($l['status'] ?? '') : 'NOT RUNNING') ?></span><?php endif; ?></td>
  <td style="display:flex;gap:4px"><a class="btn sec" href="?tab=scale&id=<?= (int)$sc['id'] ?>">Edit</a>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="toggle_scale"><input type="hidden" name="id" value="<?= (int)$sc['id'] ?>"><button class="sec"><?= $sc['enabled'] ? 'Disable' : 'Enable' ?></button></form>
    <form method="post" onsubmit="return confirm('Delete this scale? Past tickets keep their data.')"><?= csrf_field() ?><input type="hidden" name="do" value="delscale"><input type="hidden" name="id" value="<?= (int)$sc['id'] ?>"><button class="sec">Delete</button></form></td></tr>
<?php endforeach; ?></table>
<p class="hint">Each enabled scale gets its own reader process, started by <code>bin/scale_supervisor.php</code>. Every scale needs its own COM/tty port (or gateway address).</p></div>
<div class="card" style="max-width:480px"><h2>Add a scale</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="addscale">
<label>Name (e.g. "Gate 2 bridge")</label><input name="name" required><p><button>Add</button></p></form></div>

<?php elseif ($tab === 'scale'): $ports = SerialPort::listPorts(); $sc = Scales::find($sid); ?>
<form id="f" method="post"><?= csrf_field() ?><input type="hidden" name="scale_id" value="<?= $sid ?>">
<div class="grid g2">
<div class="card"><h2>Scale #<?= $sid ?></h2>
  <div class="row"><div><label>Name</label><input name="name" value="<?= e($sc['name']) ?>" required></div>
  <div><label>Status</label><label style="text-transform:none;font-weight:400"><input type="checkbox" name="enabled" value="1" <?= $sc['enabled'] ? 'checked' : '' ?>> Enabled</label></div></div>
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
  <h2 style="margin-top:18px">Camera for this scale (optional)</h2>
  <label>Snapshot URL (JPEG)</label><?= $in('cam_url') ?><div class="hint">e.g. Hikvision <code>http://192.168.1.64/ISAPI/Streaming/channels/101/picture</code>. Used for the evidence photo and plate recognition.</div>
  <div class="row"><div><label>Camera user</label><?= $in('cam_user', 'text', 'autocomplete="off"') ?></div><div><label>Camera password <?= $s['cam_pass'] !== '' ? '(saved - blank keeps)' : '' ?></label><input name="cam_pass" type="password" autocomplete="new-password"></div></div>
</div>

<div class="card ascii"><h2>ASCII frame &amp; weight parsing</h2>
  <label>Frame terminator</label><?= $in('terminator') ?><div class="hint">Escapes allowed: \r\n = CR LF, \n, \r, \x03 (ETX).</div>
  <label>Request command (optional - poll-mode indicators)</label><?= $in('request_cmd') ?><div class="hint">Sent before each read, e.g. <code>W\r\n</code>. Leave empty for continuous output.</div>
  <label>Weight regex (group 1 = number, group 2 = unit)</label><?= $in('weight_regex') ?>
  <div class="hint">Default handles <code>ST,GS,+0012340kg</code>, <code>  12340 kg</code>, <code>=0012340</code>. Use the test below to check.</div>
  <div class="row"><div><label>Stable regex</label><?= $in('stable_regex') ?></div><div><label>Motion regex</label><?= $in('unstable_regex') ?></div></div>
  <div class="hint">If your indicator sends a flag, e.g. Stable <code>/\bST\b/</code>, Motion <code>/\bUS\b/</code>. Otherwise leave both empty and the software detects stability itself:</div>
  <div class="row"><div><label>Same reading N times</label><?= $in('stable_count', 'number', 'min="2"') ?></div><div><label>Tolerance (kg)</label><?= $in('stable_tolerance', 'number', 'step="any"') ?></div></div>
  <div class="row"><div><label>Divisor (implied decimals)</label><?= $in('divisor', 'number', 'step="any"') ?></div><div><label>Default unit</label><select name="unit"><?= $sel('unit', ['kg' => 'kg', 'g' => 'g', 't' => 't']) ?></select></div></div>
</div>

<div class="card modbus"><h2>Modbus RTU (RS-485)</h2>
  <div class="row"><div><label>Slave ID</label><?= $in('modbus_slave', 'number') ?></div><div><label>Function</label><select name="modbus_func"><?= $sel('modbus_func', [3 => '03 Holding regs', 4 => '04 Input regs']) ?></select></div></div>
  <div class="row"><div><label>Start address</label><?= $in('modbus_addr', 'number') ?></div><div><label>Registers</label><select name="modbus_regs"><?= $sel('modbus_regs', [1 => '1 (16-bit)', 2 => '2 (32-bit)']) ?></select></div></div>
  <div class="row"><div><label>Word order (32-bit)</label><select name="modbus_word_order"><?= $sel('modbus_word_order', ['big' => 'High word first', 'little' => 'Low word first']) ?></select></div><div><label>Poll every (ms)</label><?= $in('modbus_poll_ms', 'number') ?></div></div>
  <label style="text-transform:none;font-weight:400"><input type="checkbox" name="modbus_signed" value="1" <?= $s['modbus_signed'] === '1' ? 'checked' : '' ?>> Signed value</label>
  <div class="hint">Divisor and stability settings are in the ASCII panel (switch protocol to edit). Address is 0-based (register 40001 = address 0).</div>
</div>
</div>
<p><button>Save</button> <a class="btn sec" href="?tab=scales">Back</a> <button type="button" class="sec" data-test="serial">Test connection (3 s)</button> <button type="button" class="sec" data-test="camera">Test camera + plate read</button></p>
<pre class="log" id="out" style="display:none"></pre>
<div class="card"><h2>Live data from this scale's reader</h2>
  <div class="display" data-live="weight" data-scale="<?= $sid ?>" style="font-size:36px">---<small>kg</small></div>
  <div class="statusline"><span class="badge" data-live="stable">...</span><span data-live="msg"></span><span style="margin-left:auto">Raw: <code data-live="raw"></code></span></div></div>
</form>

<?php elseif ($tab === 'anpr'): ?>
<form id="f" method="post" enctype="multipart/form-data"><?= csrf_field() ?>
<div class="card" style="max-width:720px"><h2>Automatic number-plate recognition (ANPR)</h2>
  <p class="hint">A camera per scale (Setup &gt; Scales) supplies the picture. The recognition itself runs in a service you choose:</p>
  <label>Provider</label><select name="anpr_provider" id="anpr_provider"><?= $sel('anpr_provider', ['off' => 'Off', 'platerecognizer' => 'Plate Recognizer (cloud API or on-prem Snapshot SDK)', 'codeproject' => 'CodeProject.AI Server (free, self-hosted, ALPR module)', 'command' => 'Local command (e.g. OpenALPR)']) ?></select>
  <div class="p_url"><label>Service URL (leave blank for the default)</label><?= $in('anpr_url') ?>
    <div class="hint">Plate Recognizer default <code>https://api.platerecognizer.com/v1/plate-reader/</code> (on-prem: <code>http://host:8080/v1/plate-reader/</code>). CodeProject.AI default <code>http://127.0.0.1:32168/v1/vision/alpr</code>.</div></div>
  <div class="p_key"><div class="row"><div><label>API token <?= $s['anpr_key'] !== '' ? '(saved - blank keeps)' : '' ?></label><input name="anpr_key" type="password" autocomplete="new-password"></div><div><label>Region hint (e.g. in, us, gb)</label><?= $in('anpr_region') ?></div></div></div>
  <div class="p_cmd"><label>Command</label><?= $in('anpr_command') ?><div class="hint">Run without a shell. <code>{image}</code> is replaced by the picture path. Prints either JSON (OpenALPR: <code>alpr -c in -n 1 -j {image}</code>) or just <code>PLATE</code> / <code>PLATE 0.93</code>.</div></div>
  <div class="row"><div><label>Minimum confidence (0-1)</label><?= $in('anpr_min_conf', 'number', 'step="0.05" min="0" max="1"') ?></div>
    <div><label>When plate differs from ticket vehicle</label><select name="anpr_policy"><?= $sel('anpr_policy', ['off' => 'Ignore', 'warn' => 'Flag ticket (MISMATCH) + audit', 'block' => 'Block; admin can override']) ?></select></div></div>
  <label style="text-transform:none;font-weight:400"><input type="checkbox" name="anpr_auto" value="1" <?= $s['anpr_auto'] === '1' ? 'checked' : '' ?>> Auto-read the plate when a truck settles on the selected scale and the vehicle box is empty</label>
  <p><button>Save</button></p>
  <h2 style="margin-top:18px">Test</h2>
  <label>Upload a sample picture of a plate (JPEG)</label><input type="file" name="sample" accept="image/jpeg">
  <p><button type="button" class="sec" data-test="anpr">Recognise sample (uses the values above, even if unsaved)</button></p>
  <pre class="log" id="out" style="display:none"></pre>
  <p class="hint">Accuracy depends on camera position, lighting and the ANPR service. Test with your own gate pictures before relying on <i>Block</i>.</p></div>
</form>

<?php elseif ($tab === 'oracle'): $drv = OracleSync::available(); ?>
<form id="f" method="post"><?= csrf_field() ?>
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
</div></form>

<?php elseif ($tab === 'general'): ?>
<form id="f" method="post"><?= csrf_field() ?>
<div class="card" style="max-width:560px"><h2>General</h2>
  <label>Company name</label><?= $in('company_name') ?><label>Address (printed on slip)</label><textarea name="company_address" rows="2"><?= e($s['company_address']) ?></textarea>
  <label>Ticket prefix</label><?= $in('ticket_prefix') ?>
  <label style="text-transform:none;font-weight:400"><input type="checkbox" name="allow_manual" value="1" <?= $s['allow_manual'] === '1' ? 'checked' : '' ?>> Allow manual weight entry when scale is unavailable (flagged on the slip)</label>
  <p><button>Save</button></p></div>
<div class="card" style="max-width:560px"><h2>ERP REST API</h2>
  <p class="hint">Read-only endpoint <code>api/v1/tickets.php?since_id=N</code> with header <code>Authorization: Bearer &lt;token&gt;</code>. Status: <b><?= $s['api_token_hash'] !== '' ? 'token set' : 'disabled' ?></b></p>
  <button name="do" value="apitoken">Generate new token</button> <button name="do" value="apioff" class="sec">Disable API</button></div>
</form>

<?php elseif ($tab === 'users'): ?>
<div class="grid g2"><div class="card"><h2>Users</h2><table><tr><th>User</th><th>Role</th><th>Active</th><th></th></tr>
<?php foreach (Db::all('SELECT * FROM users ORDER BY username') as $u): ?>
<tr><td><?= e($u['username']) ?></td><td><?= e($u['role']) ?></td><td><?= $u['active'] ? 'yes' : 'no' ?></td><td style="display:flex;gap:4px">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="toggle"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="sec">Enable/Disable</button></form>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="passwd"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="password" value=""><button class="sec" onclick="var p=prompt('New password (min 8)');if(!p)return false;this.form.password.value=p">Set password</button></form>
</td></tr><?php endforeach; ?></table></div>
<div class="card"><h2>Add user</h2><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="adduser">
<label>Username</label><input name="username" required><label>Password</label><input name="password" type="password" required minlength="8">
<label>Role</label><select name="role"><option value="operator">Operator</option><option value="admin">Admin</option></select><p><button>Add</button></p></form></div></div>

<?php elseif ($tab === 'diag'): ?>
<div class="card"><h2>System</h2><table>
<tr><td>PHP</td><td><?= PHP_VERSION ?> (<?= PHP_OS_FAMILY ?>)</td></tr>
<tr><td>Extensions</td><td>pdo_sqlite <?= extension_loaded('pdo_sqlite') ? '&#10003;' : '&#10007;' ?> &middot; sodium <?= extension_loaded('sodium') ? '&#10003;' : '&#10007;' ?> &middot; curl <?= function_exists('curl_init') ? '&#10003;' : '&#10007; (needed for camera/ANPR)' ?> &middot; oci8 <?= function_exists('oci_connect') ? '&#10003;' : '&#10007;' ?> &middot; pdo_oci <?= extension_loaded('pdo_oci') ? '&#10003;' : '&#10007;' ?></td></tr>
<?php foreach (Scales::all() as $sc): $l = Weighment::live((int)$sc['id']); ?>
<tr><td>Scale <?= (int)$sc['id'] ?>: <?= e($sc['name']) ?></td><td><span class="badge <?= !$sc['enabled'] ? '' : ($l['daemon_alive'] ? 'ok' : 'bad') ?>"><?= !$sc['enabled'] ? 'DISABLED' : ($l['daemon_alive'] ? 'READER RUNNING' : 'READER NOT RUNNING') ?></span> <?= e($l['status'] ?? '') ?> <?= e($l['message'] ?? '') ?></td></tr>
<?php endforeach; ?>
<tr><td>ANPR</td><td><?= Anpr::enabled() ? e(Settings::get('anpr_provider')) . ', policy ' . e(Settings::get('anpr_policy')) : 'off' ?></td></tr>
<tr><td>Pending / failed Oracle</td><td><?= (int)Db::val("SELECT COUNT(*) FROM weighments WHERE status IN ('CLOSED','CANCELLED') AND sync_status IN ('PENDING','FAILED')") ?></td></tr>
<tr><td>Last sync error</td><td><?= e((string)Db::val("SELECT sync_error FROM weighments WHERE sync_error IS NOT NULL ORDER BY id DESC LIMIT 1")) ?></td></tr>
<tr><td>Database file</td><td><?= e(WB_DB) ?> (<?= number_format(filesize(WB_DB) / 1024) ?> KB)</td></tr></table></div>
<div class="card"><h2>Recent audit</h2><table><?php foreach (Db::all('SELECT * FROM audit ORDER BY id DESC LIMIT 25') as $a) echo '<tr><td>' . e($a['at']) . '</td><td>' . e($a['user']) . '</td><td>' . e($a['action']) . '</td><td>' . e($a['detail']) . '</td></tr>'; ?></table></div>
<?php endif; ?>

<script>
const $ = s => document.querySelector(s), $$ = s => document.querySelectorAll(s);
function vis() {
  const c = $('#conn_type'), p = $('#protocol');
  if (c) {
    $$('.ser').forEach(e => e.style.display = c.value === 'serial' ? '' : 'none');
    $$('.tcp').forEach(e => e.style.display = c.value === 'tcp' ? '' : 'none');
    $$('.ascii').forEach(e => e.style.display = p.value === 'ascii' ? '' : 'none');
    $$('.modbus').forEach(e => e.style.display = p.value === 'modbus_rtu' ? '' : 'none');
  }
  const a = $('#anpr_provider');
  if (a) {
    $$('.p_url').forEach(e => e.style.display = ['platerecognizer', 'codeproject'].includes(a.value) ? '' : 'none');
    $$('.p_key').forEach(e => e.style.display = a.value === 'platerecognizer' ? '' : 'none');
    $$('.p_cmd').forEach(e => e.style.display = a.value === 'command' ? '' : 'none');
  }
}
document.addEventListener('change', vis); vis();
$$('[data-test]').forEach(b => b.addEventListener('click', async () => {
  const out = $('#out'); out.style.display = 'block'; out.textContent = 'Working...'; b.disabled = true;
  const fd = new FormData($('#f')); fd.set('action', b.dataset.test); fd.set('csrf', '<?= e(Auth::csrf()) ?>');
  try {
    const r = await (await fetch('api/setup_test.php', {method: 'POST', body: fd})).json();
    out.textContent = (r.ok ? 'OK: ' : 'FAILED: ') + r.message + (r.data ? '\n\n' + r.data : '') + (r.hex ? '\n\nHEX: ' + r.hex : '');
    out.style.borderLeft = '4px solid ' + (r.ok ? '#178a4a' : '#c62828');
  } catch (e) { out.textContent = 'Request failed: ' + e; }
  b.disabled = false;
}));
</script>
<?php page_foot();
