<?php
declare(strict_types=1);

/**
 * Pushes CLOSED tickets to Oracle with an idempotent MERGE (safe to retry).
 * Uses the oci8 extension when present, otherwise PDO_OCI.
 */
final class OracleSync
{
    private $conn = null;      // oci8 resource or PDO
    private bool $useOci8;

    public function __construct(private array $cfg)
    {
        $this->useOci8 = function_exists('oci_connect');
    }

    public static function available(): string
    {
        if (function_exists('oci_connect')) { return 'oci8'; }
        if (extension_loaded('pdo_oci')) { return 'pdo_oci'; }
        return '';
    }

    private function table(): string
    {
        $t = strtoupper((string)$this->cfg['ora_table']);
        if (!preg_match('/^[A-Z][A-Z0-9_$#]{0,29}(\.[A-Z][A-Z0-9_$#]{0,29})?$/', $t)) { throw new RuntimeException('Invalid Oracle table name.'); }
        return $t;
    }

    public function connect(): void
    {
        if (self::available() === '') { throw new RuntimeException('No Oracle PHP driver: install the oci8 (or pdo_oci) extension and Oracle Instant Client. See README step 6.'); }
        $c = $this->cfg;
        if ($c['ora_host'] === '' || $c['ora_service'] === '' || $c['ora_user'] === '') { throw new RuntimeException('Oracle host, service and user are required.'); }
        $dsn = "//{$c['ora_host']}:{$c['ora_port']}/{$c['ora_service']}";
        if ($this->useOci8) {
            $this->conn = @oci_connect($c['ora_user'], $c['ora_pass'], $dsn, 'AL32UTF8');
            if (!$this->conn) { $e = oci_error(); throw new RuntimeException('Oracle connect failed: ' . ($e['message'] ?? 'unknown')); }
        } else {
            try { $this->conn = new PDO("oci:dbname=$dsn;charset=AL32UTF8", $c['ora_user'], $c['ora_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
            catch (PDOException $e) { throw new RuntimeException('Oracle connect failed: ' . $e->getMessage()); }
        }
    }

    private function run(string $sql, array $params = []): array
    {
        if ($this->useOci8) {
            $st = oci_parse($this->conn, $sql);
            foreach ($params as $k => &$v) { oci_bind_by_name($st, ':' . $k, $v, -1); }
            unset($v);
            if (!@oci_execute($st, OCI_COMMIT_ON_SUCCESS)) { $e = oci_error($st); throw new RuntimeException($e['message'] ?? 'Oracle error'); }
            $rows = [];
            if (oci_statement_type($st) === 'SELECT') { oci_fetch_all($st, $rows, 0, -1, OCI_FETCHSTATEMENT_BY_ROW); }
            return $rows;
        }
        $st = $this->conn->prepare($sql);
        foreach ($params as $k => $v) { $st->bindValue(':' . $k, $v); }
        $st->execute();
        return $st->columnCount() ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /** Connectivity + table check for the setup page. */
    public function test(): string
    {
        $this->connect();
        $v = $this->run("SELECT banner FROM v\$version WHERE ROWNUM = 1");
        $banner = $v[0]['BANNER'] ?? 'Oracle';
        try { $this->run('SELECT COUNT(*) AS C FROM ' . $this->table() . ' WHERE 1 = 0'); }
        catch (RuntimeException $e) { return "Connected ($banner) BUT table {$this->table()} is not usable: " . $e->getMessage() . ' - run sql/oracle_schema.sql'; }
        return "Connected OK ($banner). Table {$this->table()} is present.";
    }

    public function push(array $w): void
    {
        $t = $this->table();
        $ts = fn(string $p) => "TO_TIMESTAMP(:$p, 'YYYY-MM-DD HH24:MI:SS')";
        $sql = "MERGE INTO $t d USING (SELECT :ticket_no AS ticket_no FROM dual) s ON (d.TICKET_NO = s.ticket_no)
          WHEN MATCHED THEN UPDATE SET VEHICLE_NO=:vehicle_no, PARTY=:party, MATERIAL=:material, DIRECTION=:direction, DRIVER=:driver,
               CHALLAN_NO=:challan_no, REMARKS=:remarks, GROSS_KG=:gross_kg, TARE_KG=:tare_kg, NET_KG=:net_kg,
               FIRST_WEIGHT_AT={$ts('first_at')}, SECOND_WEIGHT_AT={$ts('second_at')}, STATUS=:status, OPERATOR=:operator,
               LOCAL_ID=:local_id, SYNCED_AT=SYSTIMESTAMP
          WHEN NOT MATCHED THEN INSERT (TICKET_NO, VEHICLE_NO, PARTY, MATERIAL, DIRECTION, DRIVER, CHALLAN_NO, REMARKS, GROSS_KG, TARE_KG, NET_KG,
               FIRST_WEIGHT_AT, SECOND_WEIGHT_AT, STATUS, OPERATOR, LOCAL_ID, SYNCED_AT)
          VALUES (:ticket_no, :vehicle_no, :party, :material, :direction, :driver, :challan_no, :remarks, :gross_kg, :tare_kg, :net_kg,
               {$ts('first_at')}, {$ts('second_at')}, :status, :operator, :local_id, SYSTIMESTAMP)";
        $n = fn($x) => ($x === '' || $x === null) ? null : $x;
        $this->run($sql, [
            'ticket_no' => $w['ticket_no'], 'vehicle_no' => $w['vehicle_no'], 'party' => $n($w['party']), 'material' => $n($w['material']),
            'direction' => $w['direction'], 'driver' => $n($w['driver']), 'challan_no' => $n($w['challan_no']), 'remarks' => $n($w['remarks']),
            'gross_kg' => (string)$w['gross_kg'], 'tare_kg' => (string)$w['tare_kg'], 'net_kg' => (string)$w['net_kg'],
            'first_at' => $w['first_at'], 'second_at' => $w['second_at'], 'status' => $w['status'], 'operator' => $n($w['operator']),
            'local_id' => (string)$w['id'],
        ]);
    }

    /** @return array{ok:int, failed:int, error:?string} */
    public static function syncPending(?int $limit = null): array
    {
        $cfg = Settings::all();
        $res = ['ok' => 0, 'failed' => 0, 'error' => null];
        if ($cfg['ora_enabled'] !== '1') { $res['error'] = 'Oracle transfer is disabled in Setup.'; return $res; }
        $rows = Db::all("SELECT * FROM weighments WHERE status = 'CLOSED' AND sync_status IN ('PENDING','FAILED') ORDER BY id LIMIT ?",
            [$limit ?? (int)$cfg['ora_batch']]);
        if (!$rows) { return $res; }
        $o = new self($cfg);
        try { $o->connect(); }
        catch (Throwable $e) {   // Oracle unreachable: leave everything PENDING, retry next run (store-and-forward)
            $res['error'] = $e->getMessage();
            Db::q("UPDATE weighments SET sync_error = ?, sync_tries = sync_tries + 1 WHERE id IN (" . implode(',', array_map(fn($r) => (int)$r['id'], $rows)) . ')', [$e->getMessage()]);
            return $res;
        }
        foreach ($rows as $w) {
            try {
                $o->push($w);
                Db::q("UPDATE weighments SET sync_status='SYNCED', sync_at=?, sync_error=NULL WHERE id=?", [date('Y-m-d H:i:s'), $w['id']]);
                $res['ok']++;
            } catch (Throwable $e) {
                Db::q("UPDATE weighments SET sync_status='FAILED', sync_tries=sync_tries+1, sync_error=? WHERE id=?", [mb_substr($e->getMessage(), 0, 500), $w['id']]);
                $res['failed']++; $res['error'] = $e->getMessage();
            }
        }
        return $res;
    }
}
