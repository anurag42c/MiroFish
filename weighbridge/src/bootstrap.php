<?php
declare(strict_types=1);

define('WB_ROOT', dirname(__DIR__));
define('WB_DATA', WB_ROOT . '/data');
define('WB_DB', WB_DATA . '/weighbridge.sqlite');

date_default_timezone_set(getenv('WB_TZ') ?: 'Asia/Kolkata');

foreach (['Db', 'Settings', 'Auth', 'SerialPort', 'ScaleParser', 'Modbus', 'ScaleReader', 'OracleSync', 'Weighment'] as $c) {
    require_once __DIR__ . "/$c.php";
}

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function is_installed(): bool { return is_file(WB_DB) && filesize(WB_DB) > 0 && Settings::get('installed') === '1'; }

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}

function redirect(string $url): never { header('Location: ' . $url); exit; }
