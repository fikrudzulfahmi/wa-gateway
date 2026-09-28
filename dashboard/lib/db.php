<?php
declare(strict_types=1);

/** Koneksi PDO ke MySQL XAMPP. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = config('db');
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo '<pre style="font:13px/1.5 monospace;padding:20px">';
        echo "Gagal konek database '{$c['name']}'.\n\n";
        echo "Pastikan MySQL XAMPP jalan dan skema sudah diimport:\n";
        echo "  &quot;C:\\xampp\\mysql\\bin\\mysql.exe&quot; -u root &lt; " . dirname(__DIR__) . "\\sql\\schema.sql\n\n";
        echo 'Detail: ' . htmlspecialchars($e->getMessage());
        echo '</pre>';
        exit;
    }
    return $pdo;
}

/** Query dengan binding -> array baris. */
function db_all(string $sql, array $params = []): array
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Query -> satu baris (atau null). */
function db_one(string $sql, array $params = []): ?array
{
    $rows = db_all($sql, $params);
    return $rows[0] ?? null;
}

/** Query -> nilai tunggal kolom pertama. */
function db_val(string $sql, array $params = [], mixed $default = null): mixed
{
    $row = db_one($sql, $params);
    if (!$row) {
        return $default;
    }
    return array_values($row)[0] ?? $default;
}

/** INSERT/UPDATE/DELETE -> jumlah baris terpengaruh. */
function db_exec(string $sql, array $params = []): int
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql  = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $table,
        implode(',', array_map(fn($c) => "`$c`", $cols)),
        implode(',', array_fill(0, count($cols), '?'))
    );
    $st = db()->prepare($sql);
    $st->execute(array_values($data));
    return (int) db()->lastInsertId();
}

/** Ambil/isi pengaturan engine. */
function setting_get(string $key, mixed $default = null): mixed
{
    $v = db_val('SELECT `value` FROM wa_settings WHERE `key` = ?', [$key]);
    return $v ?? $default;
}

function setting_set(string $key, mixed $value): void
{
    db_exec(
        'INSERT INTO wa_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
        [$key, $value === null ? null : (string) $value]
    );
}
