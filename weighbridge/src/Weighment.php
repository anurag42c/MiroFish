<?php
declare(strict_types=1);

/** Ticket business rules. Weights are always captured server-side from the daemon's live reading. */
final class Weighment
{
    public static function live(): array
    {
        $r = Db::one('SELECT * FROM live_weight WHERE id = 1') ?? [];
        $age = isset($r['updated_at']) ? microtime(true) - (float)$r['updated_at'] : 9999;
        $beat = isset($r['heartbeat']) ? microtime(true) - (float)$r['heartbeat'] : 9999;
        $r['daemon_alive'] = $beat < 6;
        $r['fresh'] = $r['daemon_alive'] && $age < 3 && ($r['status'] ?? '') === 'RUNNING';
        $r['age'] = round($age, 1);
        return $r;
    }

    /** @return array{0: float, 1: int} [kg, manual?] */
    public static function capture(?string $manualKg): array
    {
        if ($manualKg !== null && $manualKg !== '') {
            if (Settings::get('allow_manual') !== '1') { throw new RuntimeException('Manual weight entry is disabled in Setup.'); }
            $kg = (float)$manualKg;
            if ($kg <= 0) { throw new RuntimeException('Enter a valid manual weight.'); }
            return [round($kg, 2), 1];
        }
        $l = self::live();
        if (!$l['fresh']) { throw new RuntimeException('No live weight from the scale (daemon stopped or scale disconnected). Check Setup > Scale Connection.'); }
        if (!(int)$l['stable']) { throw new RuntimeException('Weight is not stable yet. Wait for STABLE and try again.'); }
        $kg = (float)$l['weight'];
        if ($kg < (float)Settings::get('min_capture_kg')) { throw new RuntimeException("Weight {$kg} kg is below the minimum capture weight."); }
        return [$kg, 0];
    }

    public static function nextTicket(): string
    {
        $prefix = Settings::get('ticket_prefix', 'WB') . date('Ymd');
        $max = Db::val('SELECT MAX(ticket_no) FROM weighments WHERE ticket_no LIKE ?', [$prefix . '%']);
        $seq = $max ? ((int)substr((string)$max, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    }

    private static function remember(string $table, string $name): void
    {
        if ($name !== '') { Db::q("INSERT OR IGNORE INTO $table(" . ($table === 'vehicles' ? 'reg_no' : 'name') . ') VALUES (?)', [$name]); }
    }

    public static function create(array $in, ?string $manualKg): int
    {
        $veh = strtoupper(preg_replace('/\s+/', '', trim((string)($in['vehicle_no'] ?? ''))) ?? '');
        if ($veh === '') { throw new RuntimeException('Vehicle number is required.'); }
        if (Db::val("SELECT COUNT(*) FROM weighments WHERE vehicle_no = ? AND status = 'OPEN'", [$veh])) {
            throw new RuntimeException("Vehicle $veh already has an open ticket. Complete the second weighment instead.");
        }
        $firstType = ($in['first_type'] ?? 'GROSS') === 'TARE' ? 'TARE' : 'GROSS';
        $useStored = !empty($in['use_stored_tare']);
        $stored = $useStored ? Db::val('SELECT tare_kg FROM vehicles WHERE reg_no = ?', [$veh]) : null;
        if ($useStored && !$stored) { throw new RuntimeException('No stored tare for this vehicle. Untick "Use stored tare".'); }

        [$kg, $man] = self::capture($manualKg);
        $now = date('Y-m-d H:i:s');
        $party = trim((string)($in['party'] ?? '')); $mat = trim((string)($in['material'] ?? ''));

        $pdo = Db::pdo(); $pdo->exec('BEGIN IMMEDIATE');   // serialise ticket-number allocation
        try {
            $ticket = self::nextTicket();
            Db::q('INSERT INTO weighments(ticket_no, vehicle_no, party, material, direction, driver, challan_no, remarks, first_type, first_kg, first_at, first_manual, operator, created_at)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                $ticket, $veh, $party, $mat, ($in['direction'] ?? 'INWARD') === 'OUTWARD' ? 'OUTWARD' : 'INWARD',
                trim((string)($in['driver'] ?? '')), trim((string)($in['challan_no'] ?? '')), trim((string)($in['remarks'] ?? '')),
                $useStored ? 'GROSS' : $firstType, $kg, $now, $man, Auth::user()['username'] ?? 'system', $now]);
            $id = Db::id();
            self::remember('vehicles', $veh); self::remember('parties', $party); self::remember('materials', $mat);
            if ($img = Camera::snapshot($ticket, 'in')) { Db::q('UPDATE weighments SET first_img = ? WHERE id = ?', [$img, $id]); }
            if ($useStored) { self::finish($id, (float)$stored, 'STORED TARE', 0, $now, true); }
            Db::audit('ticket_create', $ticket);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->exec('ROLLBACK'); } throw $e; }
        return $id;
    }

    public static function second(int $id, ?string $manualKg): void
    {
        $w = Db::one("SELECT * FROM weighments WHERE id = ? AND status = 'OPEN'", [$id]);
        if (!$w) { throw new RuntimeException('Ticket not found or already closed.'); }
        [$kg, $man] = self::capture($manualKg);
        self::finish($id, $kg, null, $man, date('Y-m-d H:i:s'));
        if ($img = Camera::snapshot($w['ticket_no'], 'out')) { Db::q('UPDATE weighments SET second_img = ? WHERE id = ?', [$img, $id]); }
        Db::audit('ticket_close', $w['ticket_no']);
    }

    private static function finish(int $id, float $kg, ?string $note, int $manual, string $now, bool $inTx = false): void
    {
        $w = Db::one('SELECT * FROM weighments WHERE id = ?', [$id]);
        $first = (float)$w['first_kg'];
        if ($w['first_type'] === 'GROSS') { $gross = $first; $tare = $kg; } else { $gross = $kg; $tare = $first; }
        if ($gross <= $tare) { throw new RuntimeException(sprintf('Gross (%.0f kg) must be greater than tare (%.0f kg). Check first-weight type.', $gross, $tare)); }
        $sync = Settings::get('ora_enabled') === '1' ? 'PENDING' : 'NA';
        Db::q("UPDATE weighments SET second_kg=?, second_at=?, second_manual=?, gross_kg=?, tare_kg=?, net_kg=?, status='CLOSED', sync_status=?,
               remarks = CASE WHEN ? IS NULL THEN remarks ELSE TRIM(COALESCE(remarks,'') || ' [' || ? || ']') END WHERE id=?",
            [$kg, $now, $manual, $gross, $tare, $gross - $tare, $sync, $note, $note, $id]);
        // learn the vehicle's tare for next time
        if ($w['first_type'] === 'TARE' || $note === null) {
            Db::q('UPDATE vehicles SET tare_kg = ? WHERE reg_no = ? AND (tare_kg IS NULL)', [$tare, $w['vehicle_no']]);
        }
    }

    public static function cancel(int $id, string $reason): void
    {
        // A ticket already in Oracle must be re-sent so Oracle also shows it as CANCELLED.
        Db::q("UPDATE weighments SET remarks=TRIM(COALESCE(remarks,'') || ' [CANCELLED: ' || ? || ']'),
               sync_status = CASE WHEN sync_status IN ('SYNCED','PENDING','FAILED') AND gross_kg IS NOT NULL THEN 'PENDING' ELSE 'NA' END,
               status='CANCELLED' WHERE id=? AND status<>'CANCELLED'", [$reason, $id]);
        Db::audit('ticket_cancel', "$id $reason");
    }
}
