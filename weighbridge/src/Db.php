<?php
declare(strict_types=1);

/** SQLite access + schema. WAL mode lets the serial daemon and web requests share the file. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            if (!is_dir(WB_DATA)) { mkdir(WB_DATA, 0775, true); }
            $p = new PDO('sqlite:' . WB_DB);
            $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $p->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON; PRAGMA synchronous=NORMAL;');
            self::$pdo = $p;
        }
        return self::$pdo;
    }

    public static function q(string $sql, array $args = []): PDOStatement
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        return $st;
    }

    public static function all(string $sql, array $args = []): array { return self::q($sql, $args)->fetchAll(); }
    public static function one(string $sql, array $args = []): ?array { return self::q($sql, $args)->fetch() ?: null; }
    public static function val(string $sql, array $args = []): mixed { $r = self::q($sql, $args)->fetchColumn(); return $r === false ? null : $r; }
    public static function id(): int { return (int)self::pdo()->lastInsertId(); }

    public static function migrate(): void
    {
        self::pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, pass_hash TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'operator', active INTEGER NOT NULL DEFAULT 1, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE IF NOT EXISTS vehicles (
  id INTEGER PRIMARY KEY, reg_no TEXT UNIQUE NOT NULL, tare_kg REAL, transporter TEXT, active INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS parties (id INTEGER PRIMARY KEY, name TEXT UNIQUE NOT NULL, active INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS materials (id INTEGER PRIMARY KEY, name TEXT UNIQUE NOT NULL, active INTEGER NOT NULL DEFAULT 1);
CREATE TABLE IF NOT EXISTS weighments (
  id INTEGER PRIMARY KEY,
  ticket_no TEXT UNIQUE NOT NULL,
  vehicle_no TEXT NOT NULL, party TEXT, material TEXT, direction TEXT NOT NULL DEFAULT 'INWARD',
  driver TEXT, challan_no TEXT, remarks TEXT,
  first_type TEXT NOT NULL,              -- GROSS or TARE
  first_kg REAL, first_at TEXT, first_manual INTEGER DEFAULT 0,
  second_kg REAL, second_at TEXT, second_manual INTEGER DEFAULT 0,
  gross_kg REAL, tare_kg REAL, net_kg REAL,
  status TEXT NOT NULL DEFAULT 'OPEN',   -- OPEN, CLOSED, CANCELLED
  operator TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  sync_status TEXT NOT NULL DEFAULT 'NA',-- NA, PENDING, SYNCED, FAILED
  sync_tries INTEGER NOT NULL DEFAULT 0, sync_at TEXT, sync_error TEXT);
CREATE INDEX IF NOT EXISTS ix_w_status ON weighments(status);
CREATE INDEX IF NOT EXISTS ix_w_sync ON weighments(sync_status);
CREATE INDEX IF NOT EXISTS ix_w_created ON weighments(created_at);
CREATE TABLE IF NOT EXISTS live_weight (
  id INTEGER PRIMARY KEY CHECK (id = 1),
  weight REAL, unit TEXT, stable INTEGER DEFAULT 0, raw TEXT,
  status TEXT, message TEXT, updated_at REAL, heartbeat REAL);
INSERT OR IGNORE INTO live_weight(id, status) VALUES (1, 'STOPPED');
CREATE TABLE IF NOT EXISTS audit (id INTEGER PRIMARY KEY, at TEXT DEFAULT CURRENT_TIMESTAMP, user TEXT, action TEXT, detail TEXT);
SQL);
    }

    /** Idempotent upgrades so an existing data/ folder keeps working after code updates. */
    public static function upgrade(): void
    {
        self::migrate();
        self::pdo()->exec('CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY, username TEXT, ip TEXT, at INTEGER)');
        self::pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS scales (id INTEGER PRIMARY KEY, name TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, cfg TEXT NOT NULL DEFAULT '{}');
CREATE TABLE IF NOT EXISTS live_scale (
  scale_id INTEGER PRIMARY KEY, weight REAL, unit TEXT, stable INTEGER DEFAULT 0, raw TEXT,
  status TEXT, message TEXT, updated_at REAL, heartbeat REAL);
SQL);
        self::pdo()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS gates (id INTEGER PRIMARY KEY, name TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1,
  role TEXT NOT NULL DEFAULT 'ENTRY', scale_id INTEGER, cfg TEXT NOT NULL DEFAULT '{}');
CREATE TABLE IF NOT EXISTS gate_state (gate_id INTEGER PRIMARY KEY, state TEXT NOT NULL DEFAULT 'UNKNOWN', updated_at REAL, close_at REAL);
CREATE TABLE IF NOT EXISTS gate_events (id INTEGER PRIMARY KEY, at TEXT, gate_id INTEGER, gate_name TEXT, action TEXT, source TEXT,
  user TEXT, ok INTEGER, message TEXT);
CREATE INDEX IF NOT EXISTS ix_ge_at ON gate_events(id);
CREATE TABLE IF NOT EXISTS gate_entries (
  id INTEGER PRIMARY KEY, entry_no TEXT UNIQUE NOT NULL, vehicle_no TEXT NOT NULL, driver TEXT, driver_phone TEXT,
  party TEXT, material TEXT, purpose TEXT NOT NULL DEFAULT 'DELIVERY', challan_no TEXT, remarks TEXT,
  status TEXT NOT NULL DEFAULT 'INSIDE',            -- INSIDE, EXITED, CANCELLED
  in_at TEXT, in_gate_id INTEGER, out_at TEXT, out_gate_id INTEGER,
  plate_in TEXT, plate_out TEXT, plate_flag TEXT, img_in TEXT, img_out TEXT,
  operator TEXT, exit_operator TEXT, override_note TEXT);
CREATE INDEX IF NOT EXISTS ix_gentry_status ON gate_entries(status, vehicle_no);
SQL);
        $vc = array_column(self::all('PRAGMA table_info(vehicles)'), 'name');
        foreach (['blocked' => 'INTEGER NOT NULL DEFAULT 0', 'block_reason' => 'TEXT'] as $c => $t) {
            if (!in_array($c, $vc, true)) { self::pdo()->exec("ALTER TABLE vehicles ADD COLUMN $c $t"); }
        }
        $cols = array_column(self::all('PRAGMA table_info(weighments)'), 'name');
        foreach (['gate_entry_id' => 'INTEGER', 'first_img' => 'TEXT', 'second_img' => 'TEXT', 'scale_id' => 'INTEGER', 'second_scale_id' => 'INTEGER',
                  'plate_in' => 'TEXT', 'plate_in_conf' => 'REAL', 'plate_out' => 'TEXT', 'plate_out_conf' => 'REAL', 'plate_flag' => 'TEXT'] as $c => $t) {
            if (!in_array($c, $cols, true)) { self::pdo()->exec("ALTER TABLE weighments ADD COLUMN $c $t"); }
        }
        Scales::ensureDefault();   // v1 -> v3: the single scale becomes "Scale 1" keeping its settings
    }

    public static function audit(string $action, string $detail = ''): void
    {
        self::q('INSERT INTO audit(user, action, detail) VALUES (?,?,?)', [$_SESSION['user']['username'] ?? 'system', $action, $detail]);
    }
}
