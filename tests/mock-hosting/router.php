<?php
/**
 * ==== TES: Aplikasi hosting tiruan (Mode PULL) ====
 * Meniru aplikasi di hosting: punya tabel antrean (di sini disimpan sebagai JSON)
 * plus 2 endpoint yang dipanggil gateway: daftar job & laporan balik (ack).
 *
 * Jalankan:  php -S 127.0.0.1:8090 tests/mock-hosting/router.php
 * Daftarkan di dashboard:  Base URL = http://127.0.0.1:8090
 */
declare(strict_types=1);

const TOKEN = 'token-mock-hosting';           // harus sama dengan token di dashboard gateway
const STATE = __DIR__ . '/state.json';        // pengganti tabel wa_outbox di aplikasi

function state(): array
{
    if (!is_file(STATE)) {
        file_put_contents(STATE, json_encode([
            ['id' => 101, 'nomor' => '081234567890', 'isi' => 'TES PULL: presensi ananda hadir pukul 07:01', 'status' => 'pending'],
            ['id' => 102, 'nomor' => '081298765432', 'isi' => 'TES PULL: tagihan SPP bulan ini', 'status' => 'pending'],
            ['id' => 103, 'nomor' => '089999999999', 'isi' => 'TES PULL: sudah diproses (tidak boleh terkirim 2x)', 'status' => 'diproses'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
    return json_decode((string) file_get_contents(STATE), true) ?: [];
}

function save(array $rows): void
{
    file_put_contents(STATE, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function guard(): void
{
    if (($_SERVER['HTTP_X_GATEWAY_TOKEN'] ?? '') !== TOKEN) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'token tidak valid']);
        exit;
    }
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
header('Content-Type: application/json; charset=utf-8');

// ---- GET /api/wa-gateway/jobs : daftar pesan menunggu ----
if ($path === '/api/wa-gateway/jobs') {
    guard();
    $limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));
    $rows  = state();
    $out   = [];
    foreach ($rows as $r) {
        if ($r['status'] === 'pending' && count($out) < $limit) {
            $out[] = ['id' => 'OUTBOX-' . $r['id'], 'to' => $r['nomor'], 'type' => 'text', 'body' => $r['isi']];
        }
    }
    echo json_encode(['data' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---- POST /api/wa-gateway/jobs/ack : laporan status dari gateway ----
if ($path === '/api/wa-gateway/jobs/ack') {
    guard();
    $in  = json_decode((string) file_get_contents('php://input'), true) ?: [];
    $ref = (int) preg_replace('/\D/', '', (string) ($in['ref'] ?? '0'));
    $map = ['queued' => 'diproses', 'sent' => 'terkirim', 'delivered' => 'sampai', 'read' => 'dibaca', 'failed' => 'gagal'];
    $new = $map[$in['status'] ?? ''] ?? null;

    if ($ref <= 0 || $new === null) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'ref/status tidak dikenal']);
        exit;
    }
    $rows = state();
    foreach ($rows as &$r) {
        if ((int) $r['id'] === $ref) {
            $r['status'] = $new;
            $r['wa_message_id'] = $in['wa_message_id'] ?? null;
            $r['error'] = $in['error'] ?? null;
        }
    }
    save($rows);
    echo json_encode(['ok' => true, 'ref' => $ref, 'status' => $new]);
    exit;
}

// ---- GET /api/wa-gateway/health : untuk uji manual ----
if ($path === '/api/wa-gateway/health') {
    echo json_encode(['ok' => true, 'mock' => true, 'data' => state()], JSON_PRETTY_PRINT);
    exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'endpoint mock tidak ada: ' . $path]);
