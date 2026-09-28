<?php
/**
 * ==== SISI HOSTING: pembantu mengirim pesan dari dalam aplikasi ====
 * Salin ke aplikasi Anda (mis. lib/WaGateway.php), lalu pakai:
 *
 *   WaGateway::kirim('081234567890', 'Ananda hadir pukul 07:01');
 *   WaGateway::kirim('081234567890', 'Kwitansi SPP', ['tipe' => 'document', 'media_url' => 'https://.../spp.pdf']);
 *
 * Fungsi ini hanya MENYISIPKAN baris ke tabel wa_outbox (status 'pending').
 * Gateway lokal akan menarik dan mengirimkannya sendiri (mode PULL),
 * jadi aplikasi hosting tidak butuh IP publik maupun akses ke server lokal.
 */
declare(strict_types=1);

final class WaGateway
{
    /**
     * @param string $nomor     08xx / 628xx
     * @param string $isi       isi pesan (atau caption bila ada lampiran)
     * @param array  $opsi      tipe, media_url, filename, kategori, ref_berkas, scheduled_at
     */
    public static function kirim(string $nomor, string $isi, array $opsi = []): int
    {
        $pdo = $GLOBALS['pdo'] ?? null; // sesuaikan: pakai koneksi PDO aplikasi Anda
        if (!$pdo instanceof PDO) {
            throw new RuntimeException('Koneksi PDO aplikasi belum tersedia.');
        }

        $st = $pdo->prepare(
            'INSERT INTO wa_outbox (nomor, tipe, isi, media_url, filename, kategori, ref_berkas, scheduled_at, status)
             VALUES (:nomor, :tipe, :isi, :media_url, :filename, :kategori, :ref, :scheduled_at, "pending")'
        );
        $st->execute([
            ':nomor'        => self::normalisasi($nomor),
            ':tipe'         => $opsi['tipe'] ?? 'text',
            ':isi'          => $isi,
            ':media_url'    => $opsi['media_url'] ?? null,
            ':filename'     => $opsi['filename'] ?? null,
            ':kategori'     => $opsi['kategori'] ?? null,
            ':ref'          => $opsi['ref_berkas'] ?? null,
            ':scheduled_at' => $opsi['scheduled_at'] ?? null,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /** Kirim ke banyak nomor sekaligus (mis. satu kelas). */
    public static function kirimBanyak(array $nomorList, string $isi, array $opsi = []): int
    {
        $n = 0;
        foreach ($nomorList as $nomor) {
            if (self::normalisasi((string) $nomor) !== '') {
                self::kirim((string) $nomor, $isi, $opsi);
                $n++;
            }
        }
        return $n;
    }

    /** Konversi 08xx/+62/8xx -> 62xx */
    public static function normalisasi(string $raw): string
    {
        $s = preg_replace('/[^\d+]/', '', $raw);
        $s = ltrim((string) $s, '+');
        if ($s === '') {
            return '';
        }
        if (str_starts_with($s, '0')) {
            return '62' . substr($s, 1);
        }
        if (str_starts_with($s, '8')) {
            return '62' . $s;
        }
        if (!str_starts_with($s, '62')) {
            return '62' . ltrim($s, '0');
        }
        return $s;
    }

    /** Status pengiriman satu nomor (untuk ditampilkan di aplikasi). */
    public static function status(int $idOutbox): ?array
    {
        $pdo = $GLOBALS['pdo'] ?? null;
        $st  = $pdo->prepare('SELECT status, wa_message_id, error, updated_at FROM wa_outbox WHERE id = ?');
        $st->execute([$idOutbox]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
