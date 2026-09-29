<?php
declare(strict_types=1);

final class Auth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_name('wbsess');
            session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'lifetime' => 0]);
            session_start();
        }
    }

    public static function login(string $u, string $p): bool
    {
        $row = Db::one('SELECT * FROM users WHERE username = ? AND active = 1', [$u]);
        if (!$row || !password_verify($p, $row['pass_hash'])) { return false; }
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$row['id'], 'username' => $row['username'], 'role' => $row['role']];
        Db::audit('login');
        return true;
    }

    public static function user(): ?array { return $_SESSION['user'] ?? null; }
    public static function isAdmin(): bool { return (self::user()['role'] ?? '') === 'admin'; }

    public static function require(bool $admin = false): void
    {
        if (!self::user()) { redirect('login.php'); }
        if ($admin && !self::isAdmin()) { http_response_code(403); exit('Admin access required'); }
    }

    public static function csrf(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    }

    public static function checkCsrf(): void
    {
        $t = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
        if (!hash_equals(self::csrf(), (string)$t)) { http_response_code(400); exit('Bad CSRF token'); }
    }

    public static function createUser(string $u, string $p, string $role): void
    {
        Db::q('INSERT INTO users(username, pass_hash, role) VALUES (?,?,?)', [$u, password_hash($p, PASSWORD_DEFAULT), $role]);
    }
}
