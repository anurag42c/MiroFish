<?php
require __DIR__ . '/_layout.php';
Auth::require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    try {
        $manual = ($_POST['manual_kg'] ?? '') !== '' ? (string)$_POST['manual_kg'] : null;
        switch ($_POST['do'] ?? '') {
            case 'create':
                $id = Weighment::create($_POST, $manual);
                $w = Db::one('SELECT status, ticket_no FROM weighments WHERE id = ?', [$id]);
                flash("Ticket {$w['ticket_no']} created" . ($w['status'] === 'CLOSED' ? ' and completed (stored tare).' : '. Vehicle can leave and return for the second weighment.'));
                if ($w['status'] === 'CLOSED') { redirect("ticket.php?id=$id"); }
                break;
            case 'second':
                Weighment::second((int)$_POST['id'], $manual);
                flash('Second weighment recorded.'); redirect('ticket.php?id=' . (int)$_POST['id']);
            case 'cancel':
                Auth::require(true);
                Weighment::cancel((int)$_POST['id'], trim($_POST['reason'] ?? '') ?: 'no reason');
                flash('Ticket cancelled.');
                break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('index.php');
}

$open = Db::all("SELECT * FROM weighments WHERE status = 'OPEN' ORDER BY id DESC");
$recent = Db::all("SELECT * FROM weighments WHERE status <> 'OPEN' ORDER BY id DESC LIMIT 8");
$manualOk = Settings::get('allow_manual') === '1';
page_head('Weighing');
?>
<div class="grid g2">
  <div>
    <div class="card">
      <div class="display" data-live="weight">---<small>kg</small></div>
      <div class="statusline"><span class="badge" data-live="stable">...</span><span data-live="msg"></span><span style="margin-left:auto">Raw: <code data-live="raw"></code></span></div>
    </div>

    <div class="card"><h2>New ticket &mdash; first weighment</h2>
      <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="do" value="create">
        <div class="row">
          <div><label>Vehicle no *</label><input name="vehicle_no" list="dl_veh" required style="text-transform:uppercase"></div>
          <div><label>Direction</label><select name="direction"><option value="INWARD">Inward (receive)</option><option value="OUTWARD">Outward (dispatch)</option></select></div>
        </div>
        <div class="row">
          <div><label>Party</label><input name="party" list="dl_party"></div>
          <div><label>Material</label><input name="material" list="dl_mat"></div>
        </div>
        <div class="row">
          <div><label>Driver</label><input name="driver"></div>
          <div><label>Challan / PO no</label><input name="challan_no"></div>
        </div>
        <div class="row">
          <div><label>This weighment is</label><select name="first_type"><option value="GROSS">GROSS (loaded) - tare later</option><option value="TARE">TARE (empty) - gross later</option></select></div>
          <div><label>Options</label><label style="text-transform:none;font-weight:400"><input type="checkbox" name="use_stored_tare" value="1"> Use stored tare (single pass, gross now)</label></div>
        </div>
        <?php if ($manualOk): ?><label>Manual weight kg (only if scale is down)</label><input name="manual_kg" type="number" step="0.01" min="0"><?php endif; ?>
        <label>Remarks</label><input name="remarks">
        <p><button data-needs-stable disabled>Capture weight &amp; create ticket</button></p>
      </form>
    </div>
  </div>

  <div>
    <div class="card"><h2>Open tickets (waiting for second weighment)</h2>
      <?php if (!$open): ?><p class="hint">None.</p><?php else: ?>
      <table><tr><th>Ticket</th><th>Vehicle</th><th class="n">1st kg</th><th></th></tr>
      <?php foreach ($open as $w): ?>
        <tr><td><?= e($w['ticket_no']) ?><div class="hint"><?= e($w['party']) ?> <?= e($w['material']) ?></div></td>
          <td><?= e($w['vehicle_no']) ?></td>
          <td class="n"><?= number_format((float)$w['first_kg']) ?><div class="hint"><?= e($w['first_type']) ?></div></td>
          <td><form method="post" style="display:flex;gap:4px;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
            <?php if ($manualOk): ?><input name="manual_kg" type="number" step="0.01" placeholder="manual" style="width:90px"><?php endif; ?>
            <button name="do" value="second" data-needs-stable disabled>2nd weight</button>
            <?php if (Auth::isAdmin()): ?><button name="do" value="cancel" class="sec" onclick="var r=prompt('Cancel reason?');if(!r)return false;var i=document.createElement('input');i.type='hidden';i.name='reason';i.value=r;this.form.appendChild(i)">Cancel</button><?php endif; ?>
          </form></td></tr>
      <?php endforeach; ?></table><?php endif; ?>
    </div>

    <div class="card"><h2>Recent tickets</h2>
      <table><tr><th>Ticket</th><th>Vehicle</th><th class="n">Net kg</th><th>Oracle</th></tr>
      <?php foreach ($recent as $w): ?>
        <tr><td><a href="ticket.php?id=<?= (int)$w['id'] ?>"><?= e($w['ticket_no']) ?></a></td><td><?= e($w['vehicle_no']) ?></td>
          <td class="n"><?= $w['status'] === 'CANCELLED' ? '<s>cancelled</s>' : number_format((float)$w['net_kg']) ?></td>
          <td><span class="badge <?= ['SYNCED' => 'ok', 'FAILED' => 'bad', 'PENDING' => 'warn'][$w['sync_status']] ?? '' ?>"><?= e($w['sync_status']) ?></span></td></tr>
      <?php endforeach; ?></table>
    </div>
  </div>
</div>
<datalist id="dl_veh"><?php foreach (Db::all('SELECT reg_no FROM vehicles WHERE active=1 ORDER BY reg_no') as $r) echo '<option value="' . e($r['reg_no']) . '">'; ?></datalist>
<datalist id="dl_party"><?php foreach (Db::all('SELECT name FROM parties WHERE active=1 ORDER BY name') as $r) echo '<option value="' . e($r['name']) . '">'; ?></datalist>
<datalist id="dl_mat"><?php foreach (Db::all('SELECT name FROM materials WHERE active=1 ORDER BY name') as $r) echo '<option value="' . e($r['name']) . '">'; ?></datalist>
<?php page_foot();
