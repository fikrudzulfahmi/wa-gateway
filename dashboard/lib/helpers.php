<?php
declare(strict_types=1);

/** Pembantu umum: config, escaping, flash message, redirect, format, CSRF. */
function config(?string $key = null, mixed $default = null): mixed
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/../config.php';
    }
    return $key === null ? $cfg : ($cfg[$key] ?? $default);
}

function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function old(string $key, mixed $default = ''): mixed
{
    return $_SESSION['old'][$key] ?? $default;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_check(): void
{
    $t = $_POST['_csrf'] ?? '';
    if (!$t || !hash_equals($_SESSION['csrf'] ?? '', (string) $t)) {
        http_response_code(419);
        exit('Token CSRF tidak valid. Muat ulang halaman.');
    }
}

/** Format nomor Indonesia: 62812xxx -> 0812-xxxx-xxxx */
function fmt_phone(?string $n): string
{
    $n = preg_replace('/\D/', '', (string) $n);
    if ($n === '') {
        return '-';
    }
    if (str_starts_with($n, '62')) {
        $n = '0' . substr($n, 2);
    }
    return trim(chunk_split($n, 4, '-'), '-');
}

function fmt_dt(?string $dt, string $fallback = '-'): string
{
    if (!$dt || str_starts_with($dt, '0000')) {
        return $fallback;
    }
    $ts = strtotime($dt);
    return $ts ? date('d/m/Y H:i:s', $ts) : $fallback;
}

function fmt_ago(?string $dt): string
{
    if (!$dt) {
        return '-';
    }
    $diff = time() - (int) strtotime($dt);
    if ($diff < 60) {
        return $diff . ' dtk lalu';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . ' mnt lalu';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . ' jam lalu';
    }
    return floor($diff / 86400) . ' hari lalu';
}

/** Badge status pesan/sesi (bootstrap-less, pakai CSS sendiri). */
function badge(string $status): string
{
    $map = [
        'connected'    => ['ok', 'Tersambung'],
        'connecting'   => ['warn', 'Menyambung'],
        'qr'           => ['warn', 'Perlu Scan QR'],
        'disconnected' => ['off', 'Terputus'],
        'logged_out'   => ['err', 'Sesi Dicabut'],
        'sent'         => ['info', 'Terkirim'],
        'delivered'    => ['info', 'Sampai'],
        'read'         => ['ok', 'Dibaca'],
        'played'       => ['ok', 'Diputar'],
        'sending'      => ['warn', 'Mengirim'],
        'queued'       => ['off', 'Antrean'],
        'failed'       => ['err', 'Gagal'],
        'cancelled'    => ['off', 'Dibatalkan'],
        'retrying'     => ['warn', 'Coba Lagi'],
    ];
    [$cls, $label] = $map[$status] ?? ['off', $status];
    return '<span class="badge ' . $cls . '">' . e($label) . '</span>';
}

/** Normalisasi nomor ke 62xxx (sama seperti di engine). */
function normalize_phone(?string $raw): string
{
    $s = preg_replace('/[^\d+]/', '', (string) $raw);
    $s = ltrim($s, '+');
    if ($s === '') {
        return '';
    }
    if (str_starts_with($s, '0')) {
        $s = '62' . substr($s, 1);
    } elseif (str_starts_with($s, '8')) {
        $s = '62' . $s;
    } elseif (!str_starts_with($s, '62')) {
        $s = '62' . ltrim($s, '0');
    }
    return $s;
}

/** Panggilan ke engine Node.js. */
function engine(string $method, string $path, array $payload = null, array $query = []): array
{
    $base  = rtrim((string) config('engine_url'), '/');
    $token = (string) (config('engine_token') ?: setting_get('engine_token', ''));
    $url   = $base . $path . ($query ? '?' . http_build_query($query) : '');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => [
            'X-Token: ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);
    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $body === '') {
        return ['ok' => false, 'error' => $err ?: 'engine tidak merespons (apakah Node.js gateway jalan?)'];
    }
    $json = json_decode((string) $body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'respons engine tidak valid: ' . substr((string) $body, 0, 200)];
    }
    if ($code >= 400 && !isset($json['error'])) {
        $json['error'] = 'HTTP ' . $code;
    }
    return $json;
}

/** Apakah engine hidup? */
function engine_alive(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $r = engine('GET', '/api/health');
    return $cache = ($r['ok'] ?? false) ? $r : ['ok' => false, 'error' => $r['error'] ?? 'tidak diketahui'];
}
