<?php
/**
 * ==== SISI HOSTING (PHP native) ====
 * api/wa-gateway/jobs.php — daftar pesan menunggu untuk ditarik gateway lokal.
 * Letakkan sesuai path "Path daftar pesan" yang diisi di dashboard > Aplikasi Hosting.
 *
 * Keamanan: WAJIB samakan token di bawah dengan token aplikasi di dashboard gateway.
 */
declare(strict_types=1);

const WA_GATEWAY_TOKEN = 'GANTI_DENGAN_TOKEN_DARI_DASHBOARD';

require __DIR__ . '/../../koneksi.php'; // sesuaikan path koneksi database aplikasi Anda

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['HTTP_X_GATEWAY_TOKEN'] ?? '') !== WA_GATEWAY_TOKEN) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'token tidak valid']);
    exit;
}

$limit = (int) ($_GET['limit'] ?? 20);
$limit = max(1, min(100, $limit));

$st = $pdo->prepare(
    "SELECT id, nomor, tipe, isi, media_url, filename, scheduled_at
       FROM wa_outbox
      WHERE status = 'pending'
        AND (scheduled_at IS NULL OR scheduled_at <= NOW())
      ORDER BY id
      LIMIT {$limit}"
);
$st->execute();

$data = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $data[] = [
        'id'           => 'OUTBOX-' . $r['id'],
        'to'           => $r['nomor'],
        'type'         => $r['tipe'],
        'body'         => $r['isi'],
        'media_url'    => $r['media_url'],
        'filename'     => $r['filename'],
        'scheduled_at' => $r['scheduled_at'],
    ];
}

echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE);
