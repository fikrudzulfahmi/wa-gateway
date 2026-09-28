<?php
/**
 * ==== SISI HOSTING (PHP native) ====
 * api/wa-gateway/jobs/ack.php — menerima laporan status dari gateway lokal.
 * Harus IDEMPOTEN: gateway memanggil berkali-kali (queued -> sent -> delivered -> read).
 */
declare(strict_types=1);

const WA_GATEWAY_TOKEN = 'GANTI_DENGAN_TOKEN_DARI_DASHBOARD';

require __DIR__ . '/../../../koneksi.php'; // sesuaikan path koneksi database aplikasi Anda

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['HTTP_X_GATEWAY_TOKEN'] ?? '') !== WA_GATEWAY_TOKEN) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'token tidak valid']);
    exit;
}

$raw = file_get_contents('php://input');
$in  = json_decode((string) $raw, true) ?: [];

$ref    = (int) preg_replace('/\D/', '', (string) ($in['ref'] ?? '0'));
$status = (string) ($in['status'] ?? '');

$map = [
    'queued'    => 'diproses',
    'sent'      => 'terkirim',
    'delivered' => 'sampai',
    'read'      => 'dibaca',
    'failed'    => 'gagal',
];

if ($ref <= 0 || !isset($map[$status])) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'ref atau status tidak dikenal']);
    exit;
}

$st = $pdo->prepare(
    'UPDATE wa_outbox
        SET status = ?, wa_message_id = COALESCE(?, wa_message_id), error = ?, updated_at = NOW()
      WHERE id = ?'
);
$st->execute([
    $map[$status],
    $in['wa_message_id'] ?? null,
    $in['error'] ?? null,
    $ref,
]);

echo json_encode(['ok' => true]);

// ------------------- OPSIONAL: tindak lanjut otomatis -------------------
// Contoh: kalau gagal, kirim notifikasi ke admin aplikasi, atau kalau 'gagal'
// karena nomor tidak valid, tandai nomor siswa agar tidak dicoba lagi.
