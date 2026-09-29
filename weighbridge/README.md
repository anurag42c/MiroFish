# Weighbridge (PHP + SQLite, RS-232 / RS-485 / TCP, Oracle transfer)

A complete truck weighbridge system in plain PHP 8.1+ (no framework, no Composer).

```
 Indicator ──RS-232 / RS-485 / TCP──►  bin/scale_daemon.php ──►  SQLite  ◄── Web UI (public/)
                                                                   │
                                          bin/sync_oracle.php ─────┴──► Oracle DB (MERGE, store-and-forward)
```

* **Scale daemon** – the only process that touches the serial port. Decodes weight and stability, writes the latest reading to SQLite. A browser can never read a stale or spoofed weight: the server captures the weight itself when you press the button.
* **Web UI** – live weight display, two-pass (gross/tare) tickets, single-pass with stored tare, printing slips, masters, reports + CSV, users/roles, audit log.
* **Setup page** – port, baud, parity, protocol (ASCII continuous / poll command / Modbus RTU), regex parser, stability rules, TCP gateway, Oracle credentials, *Test* buttons.
* **Oracle transfer** – every closed ticket is queued `PENDING` and pushed with an idempotent `MERGE`. If Oracle is down, tickets wait locally (weighing never stops) and are retried automatically.
* **Several scales on one PC** – any number of indicators (each its own COM port / RS-485 line / gateway, protocol, minimum weight and camera). One service, `bin/scale_supervisor.php`, runs a reader per enabled scale and picks up scales you add in Setup. A vehicle can be weighed in on one bridge and out on another.
* **Plate recognition (ANPR)** – per-scale IP camera → your choice of Plate Recognizer, CodeProject.AI or a local command (OpenALPR). Auto-fills the vehicle number, and checks that the truck on the bridge is the truck on the ticket.
* **Simulator mode** – installs in simulator mode so you can try everything with no hardware.

## Files

| Path | Purpose |
|---|---|
| `public/` | Web root (login, weighing, reports, masters, **setup**, ticket slip) |
| `src/` | Db, Settings (secrets encrypted), Scales, Auth, SerialPort, ScaleParser, Modbus, ScaleReader, Camera, Anpr, OracleSync, Weighment |
| `bin/scale_supervisor.php` | **The service to run**: starts/restarts one reader per enabled scale |
| `bin/scale_daemon.php` | Reader for one scale (`--scale=ID`), started by the supervisor |
| `bin/sync_oracle.php` | Oracle push service (`--loop=30`) |
| `sql/oracle_schema.sql` | Oracle table DDL |
| `bin/backup.php` | Online SQLite backup with integrity check |
| `tests/run.php` | Automated tests |
| `docs/COMPARISON.md` | Comparison with public GitHub weighbridge projects |
| `deploy/` | systemd units, Windows NSSM installer |
| `data/` | SQLite DB + encryption key (created on install; **back this up**) |

---

# Step-by-step installation

## Step 1 – Hardware and cabling

1. **Find out what your indicator outputs.** Check its manual for: *serial output mode* (continuous / on-demand / Modbus), baud rate, data bits, parity, stop bits. Common defaults: `9600, 8, N, 1`.
2. **RS-232**: connect indicator TX → PC RX, indicator RX → PC TX, GND ↔ GND (DB9 pins 2, 3, 5). Use a USB-RS232 adapter if the PC has no COM port.
3. **RS-485** (2-wire): use a USB-RS485 adapter. **A→A (D+), B→B (D−)** (labels differ between vendors – if you get nothing, swap the two wires). Add a 120 Ω terminator at the far end of long runs (>50 m). Only one master (this PC).
4. **Serial-to-Ethernet gateway** (Moxa NPort, USR-TCP232, Waveshare…): set it to *TCP Server* mode, same serial parameters, note its IP and port. Choose *TCP/IP* in Setup.
5. Keep the indicator in **continuous output**. If it only replies to a command, put that command in Setup → *Request command* (e.g. `W\r\n`).

## Step 2 – Install PHP

**Windows** (simplest): download PHP 8.2+ *Thread Safe x64* zip from windows.php.net, unzip to `C:\php`. Copy `php.ini-production` to `php.ini` and enable:
```
extension_dir = "ext"
extension=pdo_sqlite
extension=sodium
extension=mbstring
extension=oci8_19        ; only for Oracle (see Step 6)
```
**Ubuntu/Debian**
```bash
sudo apt update
sudo apt install -y php-cli php-fpm php-sqlite3 php-mbstring nginx     # or apache2 libapache2-mod-php
php -m | grep -E "pdo_sqlite|sodium|mbstring"
```

## Step 3 – Copy the application

Linux: copy this `weighbridge/` folder to `/var/www/weighbridge`. Windows: `C:\weighbridge`.
```bash
sudo chown -R www-data:www-data /var/www/weighbridge/data
sudo chmod 750 /var/www/weighbridge/data
```
Only `public/` must be reachable from the web. `data/` (database + key) must **not** be in the web root – it isn't, if you point the web server at `public/`.

## Step 4 – Give access to the serial port (Linux)

```bash
sudo usermod -aG dialout www-data        # user that runs the daemon (see the .service file)
ls -l /dev/ttyUSB0                       # find your device; `dmesg | grep tty` after plugging in
```
For a stable name across reboots use `/dev/serial/by-id/...` in Setup. Windows: find the COM number in Device Manager → Ports.

## Step 5 – Web server

*Quick start / single PC (any OS):*
```bash
php -S 0.0.0.0:8080 -t public          # from the weighbridge folder
```
Production nginx example:
```nginx
server {
    listen 80; server_name weighbridge.local;
    root /var/www/weighbridge/public; index index.php;
    location / { try_files $uri $uri/ =404; }
    location ~ \.php$ { include snippets/fastcgi-php.conf; fastcgi_pass unix:/run/php/php8.2-fpm.sock; }
    location ~ /\. { deny all; }
}
```
Open `http://<server>/install.php`, tick-check the requirement list, choose company name and **admin username/password**, press *Install*. It starts in **Simulator** mode.

## Step 6 – Oracle client (only if you will transfer to Oracle)

1. Download **Oracle Instant Client Basic** (matching PHP's 32/64-bit) from oracle.com and unzip (`/opt/oracle/instantclient_19_x` or `C:\oracle\instantclient_19_x`).
2. Linux: `sudo apt install libaio1`, then
   ```bash
   echo /opt/oracle/instantclient_19_x | sudo tee /etc/ld.so.conf.d/oracle.conf && sudo ldconfig
   sudo apt install php-dev php-pear build-essential
   sudo pecl install oci8            # answer: instantclient,/opt/oracle/instantclient_19_x
   echo "extension=oci8.so" | sudo tee /etc/php/8.2/mods-available/oci8.ini && sudo phpenmod oci8
   sudo systemctl restart php8.2-fpm
   ```
   Windows: add the Instant Client folder to `PATH`, download the matching `php_oci8_19.dll` (PECL) into `C:\php\ext`, enable `extension=oci8_19`, restart services.
3. Verify: `php -m | grep -i oci8`.
4. On Oracle run `sql/oracle_schema.sql` (creates `WEIGHBRIDGE_TICKETS`). Use a dedicated user with only CREATE SESSION + rights on that table. Make sure the PC can reach the listener (`1521`) – `tnsping` or `telnet host 1521`.

## Step 7 – Configure the scale in the Setup page

Log in as admin → **Setup → Scale connection**:

1. **Connection type**: Serial / TCP / Simulator. Pick the port (e.g. `COM3`, `/dev/ttyUSB0`), baud, data bits, parity, stop bits.
2. **Protocol**
   * *ASCII continuous* – set **terminator** (usually `\r\n`), and the **weight regex** (default handles `ST,GS,+0012340kg`, `  12340 kg`). If your indicator sends stable/motion flags, fill *Stable regex* (`/\bST\b/`) and *Motion regex* (`/\bUS\b/`); else leave empty and the software declares stability after *N identical readings* (± tolerance).
     Use **Divisor** when the indicator omits the decimal point (e.g. `012340` meaning 1234.0 → divisor 10).
   * *Modbus RTU* (RS-485 transmitters) – slave ID, function 03/04, start address (0-based), 1 or 2 registers, word order, signed, divisor.
3. Press **Save**, then **Test connection (3 s)** – you see the raw bytes (`<0D><0A>` = CR LF) and the weight the parser extracted. Adjust terminator/regex until the parsed weight is right.
   *(The test needs the daemon stopped, because the daemon owns the port. Once the daemon runs, the "Live data from daemon" panel on the same page shows what it receives.)*
4. **Setup → Oracle transfer**: host, port, **service name**, user, password (stored encrypted), table. *Test Oracle connection* → tick *Enable automatic transfer* → Save.
5. **Setup → General**: company name/address for the slip, ticket prefix, optional manual weight entry.

## Step 8 – Run the services

**Linux (systemd)**
```bash
sudo cp deploy/*.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now weighbridge-scale weighbridge-oracle-sync
journalctl -u weighbridge-scale -f                      # supervisor messages; per-scale logs are in data/logs/scale_<id>.log
```
**Windows**: install NSSM, edit paths in `deploy\windows-install.bat`, run as Administrator (also installs the web UI as a service).
**Manual test**: `php bin/scale_daemon.php --scale=1 --debug` prints every reading of scale 1 (stop the supervisor first if that scale uses a real port).
**Alternative to the sync service**: cron `* * * * * php /var/www/weighbridge/bin/sync_oracle.php`.

## Step 8b – Add more scales (optional)

Setup → **Scales** → *Add a scale* → name it → Edit: choose its own port (`COM4`, `/dev/ttyUSB1` … or a gateway IP:port), baud/parity, protocol and minimum weight → tick **Enabled** → Save. Within ~2 s the supervisor starts a reader for it; watch the *Live data* panel on the same page. The software refuses two enabled scales on the same port/gateway. On Linux give each USB-RS485 adapter a stable name (`/dev/serial/by-id/...`), because `ttyUSB0/1` can swap after a reboot.
The Weighing screen shows one live display per scale; click the one you are standing at (remembered per PC). A ticket started on one bridge can be finished on another.

## Step 8c – Plate recognition (ANPR, optional)

1. **Camera**: Setup → Scales → Edit → *Camera*: a JPEG snapshot URL (Hikvision `/ISAPI/Streaming/channels/101/picture`, Dahua `/cgi-bin/snapshot.cgi`, most cameras have one) plus user/password. Point the camera at the plate, ~5–8 m, shutter ≥1/500 s, IR/light at night. *Test camera + plate read* checks it.
2. **Recognition service** – pick one in Setup → *Plate recognition*:
   * **CodeProject.AI Server** (free, self-hosted): install it, install its *ALPR* module, URL `http://127.0.0.1:32168/v1/vision/alpr`.
   * **Plate Recognizer** (cloud API token, or their on-prem Snapshot SDK container): set the token, optional region hint (`in`, `us`, `gb`…).
   * **Local command**: any program that prints JSON or `PLATE [confidence]`, e.g. OpenALPR: `alpr -c in -n 1 -j {image}`. It is run without a shell.
   Upload a sample photo on the same page to try it before saving.
3. **Policy**: *Ignore*, *Flag ticket (MISMATCH) + audit*, or *Block* (an admin ticks *Override* to continue). Minimum confidence default 0.6; below it the plate counts as UNREAD, never as a wrong plate.
4. **Use**: press **Read** next to the vehicle box, or enable *auto-read* (reads once when a truck settles on the selected scale and the box is empty). The plate is matched to your vehicle master (stored tare, open ticket warning). On the 2nd weighing the system checks the truck on the bridge is the one on the ticket; the result is stored as OK / MISMATCH / UNREAD / NOIMG on the ticket, in reports and in Oracle.
Plate comparison ignores spaces/dashes and OCR look-alikes (0/O, 1/I, 5/S, 8/B, 2/Z) and tolerates one wrong character on plates of 6+ characters.

## Step 9 – Daily use

1. **Weighing** page: the green display shows live weight and STABLE/MOTION. Buttons enable only when stable.
2. Enter vehicle (autocompletes), party, material → *Capture weight & create ticket* (first weighment: choose GROSS or TARE).
3. When the vehicle returns, press **2nd weight** on the open ticket → slip opens → *Print*.
4. Vehicles with a stored tare: tick *Use stored tare* for a single-pass ticket.
5. **Reports**: filter, CSV export, see Oracle status per ticket; admin can *Send to Oracle now* / re-queue failures.

## Step 10 – Go-live checklist

- [ ] Change Setup → Scale from Simulator to real port and verify weight with a known test weight/calibrated vehicle.
- [ ] Serve over HTTPS or keep the PC on an isolated LAN; default cookie is HttpOnly/SameSite.
- [ ] Back up `data/` daily (SQLite file **and** `app.key`; without the key the saved Oracle password can't be decrypted).
- [ ] Set the PC timezone; optional `WB_TZ=Asia/Kolkata` environment variable.
- [ ] Legal-for-trade note: if used for commercial billing, the indicator must be approved/sealed; this software only records what the indicator sends.

## Production readiness

**Verified here (automated, `php tests/run.php` - 101 checks):** ASCII parsing (split frames, ETX, flags, divisor), Modbus RTU CRC/decoding, ticket rules (stability, minimum weight, gross>tare, stored tare, duplicate open ticket, cancel-after-sync), secret encryption, login lockout, input validation; multi-scale isolation and a supervisor that starts/stops real reader processes, the v1→v3 data upgrade, the ANPR clients against mock Plate Recognizer / CodeProject.AI / OpenALPR-style services, ticket rules with plate OK/MISMATCH/UNREAD/NOIMG under warn and block policies, plus an HTTP smoke test of every page and API, a read from a real tty device (pseudo-terminal), and backup + integrity check.

**Not verified by the author - do these on site before go-live:** real indicator/RS-485 wiring and Modbus map, **plate-reading accuracy on your camera and trucks (only the client code is tested, against mock services - try your own pictures in Setup > Plate recognition; do not use *Block* until you have),** several real ports at once, a real Oracle instance (`oci8`, the MERGE statement and table), Windows service scripts, and load with your camera. Run the go-live checklist above with a known test weight.

Added for production: login lockout, CSP/security headers, allow-listed port/host values, `BEGIN IMMEDIATE` ticket numbering, cancellation propagated to Oracle, optional camera snapshot per weighment, read-only ERP REST API (`Setup > General`), health endpoint `api/health.php` (HTTP 503 when the scale daemon is down - point your uptime monitor at it), daily backup script:

```bash
php bin/backup.php --dir=/backup/weighbridge --keep=30        # cron: 15 2 * * *
php tests/run.php                                              # run after every upgrade
```
Also keep a copy of `data/app.key` (not inside the same backup folder if that folder is shared).
See `docs/COMPARISON.md` for how this compares with public GitHub projects.

## Troubleshooting

| Symptom | Fix |
|---|---|
| "Serial device not found" / "stty failed" | Wrong port; add the service user to `dialout`; unplug/replug adapter |
| Test: *no data in 3 s* | TX/RX swapped, A/B swapped (RS-485), wrong baud/parity, indicator not in continuous mode → use *Request command* |
| Bytes arrive but "could not parse" | Fix terminator (`\r`, `\n`, `\r\n`, `\x03`) and weight regex |
| Weight never STABLE | Set stable regex from your indicator's flag, or raise tolerance / lower N |
| Value 10× too large/small | Adjust *Divisor* |
| Modbus "short/no response" | Check slave ID, baud/parity, A/B, address base (0-based) |
| Modbus CRC error | Wrong parity/baud, noisy line, missing terminator resistor |
| `No Oracle PHP driver` | Step 6; confirm `php -m` for the *same* PHP the web server/daemon uses (`php --ini`) |
| `ORA-12541`/`ORA-12514` | Listener/host/port or service name wrong (use service name, not SID) |
| `ORA-00942` | Run `sql/oracle_schema.sql`; grant rights to the sync user |
| Tickets stuck `PENDING` | Sync service not running, or Oracle unreachable – see Setup → Diagnostics |
| Windows: port busy | Another program (indicator utility) holds the COM port |
| Scale shows *Reader not running* | Supervisor service not running (`journalctl -u weighbridge-scale`), or scale not Enabled |
| Second scale: *already uses /dev/ttyUSB0* | Each scale needs its own port; use by-id names |
| Plate always UNREAD / NOIMG | Camera URL wrong (use *Test camera*), service down, or picture too dark/angled; check `curl` extension is enabled |
| Many MISMATCH flags | Camera angle/lighting; lower *policy* to Flag, raise minimum confidence, or test other provider |

## Security notes

Passwords are bcrypt/argon hashed; all queries are prepared; forms carry CSRF tokens; the Oracle password is encrypted with libsodium (key in `data/app.key`, mode 0600). Setup and user management are admin-only. Weight is captured server-side only.
