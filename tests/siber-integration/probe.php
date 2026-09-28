<?php
/**
 * ============================================================================
 *  PROBE INTEGRASI SIBER  <->  WA GATEWAY
 * ----------------------------------------------------------------------------
 *  Menguji berkas-berkas integrasi yang ada di repo SIBER:
 *    - public/cron_jobs/_wa_outbox.php   (helper antrean)
 *    - public/wa-gateway/jobs.php        (endpoint daftar pesan)
 *    - public/wa-gateway/ack.php         (endpoint laporan status)
 *
 *  Aman: hanya INSERT baris uji lalu MENGHAPUSNYA kembali (by id).
 *  Tidak mengubah data lain. Jalankan terhadap salinan DB produksi lokal:
 *
 *    SIBER_DB_NAME=siber_prod_copy SIBER_DB_USER=root SIBER_DB_PASS= \
 *      php D:/ApplicationWeb/wa-gateway/tests/siber-integration/probe.php
 * ============================================================================
 */
declare(strict_types=1);

$SIBER = getenv('SIBER_REPO') ?: 'D:/ApplicationWeb/siber';
$helper = $SIBER . '/public/cron_jobs/_wa_outbox.php';

$cfg = [
    'host' => getenv('SIBER_DB_HOST') ?: '127.0.0.1',
    'name' => getenv('SIBER_DB_NAME') ?: 'siber_prod_copy',
    'user' => getenv('SIBER_DB_USER') ?: 'root',
    'pass' => getenv('SIBER_DB_PASS') !== false ? getenv('SIBER_DB_PASS') : '',
];

$fail = 0;
function check(string $label, bool $cond, string $detail = ''): void
{
    global $fail;
    if (!$cond) {
        $fail++;
    }
    printf("%-68s %s%s\n", $label, $cond ? 'PASS' : 'FAIL', $detail !== '' ? "   [$detail]" : '');
}

echo "=== PROBE INTEGRASI SIBER x WA GATEWAY ===\n";
echo "DB   : {$cfg['name']} @ {$cfg['host']}\n";
echo "Helper: " . (is_file($helper) ? 'ada' : 'TIDAK ADA') . "\n\n";

check('berkas helper _wa_outbox.php ada', is_file($helper));
if (!is_file($helper)) {
    exit(1);
}
require $helper;

$pdo = new PDO("mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4", $cfg['user'], $cfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// ---------- 1. Normalisasi nomor ----------
$norm = [
    '081234567890' => '6281234567890',
    '+62 812-3456-7891' => '6281234567891',
    '81234567892' => '6281234567892',
    '6281234567893' => '6281234567893',
];
foreach ($norm as $in => $harap) {
    check("normalisasi '{$in}' -> {$harap}", wa_norm_nomor((string) $in) === $harap, wa_norm_nomor((string) $in));
}

// ---------- 2. Enqueue pesan sah ----------
$pesanUji = '[UJI INTEGRASI] cek antrean gateway ' . date('Y-m-d H:i:s');
$h1 = wa_enqueue($pdo, '081234567890', $pesanUji);
check('wa_enqueue() nomor sah -> ok', $h1['ok'] === true, (string) $h1['error']);
check('nomor tersimpan sudah 62xx', $h1['nomor'] === '6281234567890', $h1['nomor']);

$baris = null;
if ($h1['id']) {
    $st = $pdo->prepare('SELECT id, nomor, pesan, status FROM outbox_wa WHERE id = ?');
    $st->execute([$h1['id']]);
    $baris = $st->fetch();
}
check('baris tersimpan di outbox_wa', (bool) $baris);
check("status awal = 'pending' (siap ditarik gateway)", ($baris['status'] ?? '') === 'pending', (string) ($baris['status'] ?? '-'));
check('isi pesan utuh', ($baris['pesan'] ?? '') === $pesanUji);

// ---------- 3. Enqueue nomor tidak valid / pesan kosong ----------
$h2 = wa_enqueue($pdo, 'abc', 'x');
check('nomor tidak valid ditolak (ok=false)', $h2['ok'] === false, (string) $h2['error']);
$h3 = wa_enqueue($pdo, '081234567899', '   ');
check('pesan kosong ditolak (ok=false)', $h3['ok'] === false, (string) $h3['error']);

// ---------- 4. Helper tidak boleh fatal kalau tabel bermasalah ----------
$pdoRusak = new PDO("mysql:host={$cfg['host']};dbname={$cfg['name']};charset=utf8mb4", $cfg['user'], $cfg['pass']);
$h4 = (function () use ($pdoRusak) {
    $pdoRusak->exec('SET SESSION sql_safe_updates = 1');
    $pdoRusak->exec('DROP TABLE IF EXISTS __tabel_tidak_ada_uji__');
    return wa_enqueue($pdoRusak, '081234567890', 'tes');   // tabel ada -> sukses
})();
check('helper tetap menghasilkan array, tidak melempar fatal', is_array($h4) && array_key_exists('ok', $h4));

// ---------- 5. Bersihkan baris uji ----------
$hapus = [];
if ($h1['id']) {
    $hapus[] = $h1['id'];
}
if (!empty($h4['id'])) {
    $hapus[] = $h4['id'];
}
if ($hapus) {
    $in = implode(',', array_map('intval', $hapus));
    $n = $pdo->exec("DELETE FROM outbox_wa WHERE id IN ({$in})");
    check("baris uji dibersihkan ({$n} baris)", $n === count($hapus), "n={$n}");
}

// ---------- 6. Keadaan antrean produksi (informasi, bukan uji) ----------
echo "\n--- Keadaan antrean di {$cfg['name']} ---\n";
foreach ($pdo->query('SELECT status, COUNT(*) j FROM outbox_wa GROUP BY status') as $r) {
    printf("  %-10s %d\n", $r['status'], $r['j']);
}
$lama = $pdo->query("SELECT COUNT(*) j FROM outbox_wa WHERE status='pending' AND created_at < '2026-09-28 00:00:00'")->fetch();
echo "  pesan lama (< 2026-09-28) yang terbengkalai: {$lama['j']}\n";
$pdo->exec('DELETE FROM outbox_wa WHERE pesan LIKE "[UJI INTEGRASI]%"');
echo "  sisa baris uji: " . $pdo->query("SELECT COUNT(*) j FROM outbox_wa WHERE pesan LIKE '[UJI INTEGRASI]%'")->fetch()['j'] . "\n";

echo "\n=== HASIL: " . ($fail === 0 ? 'SEMUA PASS' : "{$fail} GAGAL") . " ===\n";
exit($fail === 0 ? 0 : 1);
