<?php
declare(strict_types=1);

/** Login sederhana berbasis session + tabel wa_users. */
function auth_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    static $u = null;
    if ($u === null) {
        $u = db_one('SELECT id, username, nama FROM wa_users WHERE id = ? AND is_active = 1', [$_SESSION['user_id']]);
    }
    return $u;
}

function auth_attempt(string $username, string $password): bool
{
    $u = db_one('SELECT * FROM wa_users WHERE username = ? AND is_active = 1', [$username]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        // jeda kecil untuk memperlambat brute force
        usleep(300000);
        return false;
    }
    db_exec('UPDATE wa_users SET last_login_at = NOW() WHERE id = ?', [$u['id']]);
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $u['id'];
    return true;
}

function auth_logout(): void
{
    $_SESSION = [];
    session_destroy();
}

function auth_require(): void
{
    if (!auth_user()) {
        redirect('index.php?page=login');
    }
}

/** Buat admin bawaan bila tabel wa_users masih kosong. */
function auth_ensure_admin(): void
{
    $n = (int) db_val('SELECT COUNT(*) FROM wa_users', [], 0);
    if ($n === 0) {
        db_insert('wa_users', [
            'username'      => 'admin',
            'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
            'nama'          => 'Administrator',
        ]);
    }
}
