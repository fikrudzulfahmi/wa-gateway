# Uji integrasi SIBER ↔ WA Gateway

Perkakas uji untuk integrasi `D:\ApplicationWeb\siber` (cronjob → tabel `outbox_wa` →
endpoint `public/wa-gateway/*.php` → gateway lokal). **Tidak ada yang menyentuh produksi**:
semua uji memakai salinan database produksi lokal `siber_prod_copy` (XAMPP).

## 1. `probe.php` — uji helper antrean (13 pemeriksaan)

```bash
cd D:\ApplicationWeb\wa-gateway
SIBER_DB_NAME=siber_prod_copy SIBER_DB_USER=root SIBER_DB_PASS= \
  C:\xampp\php\php.exe tests\siber-integration\probe.php
```

Yang diperiksa: normalisasi nomor (`08xx`/`+62`/`8xx` → `62xx`), `wa_enqueue()` menulis
baris `status='pending'`, penolakan nomor tidak valid & pesan kosong, helper tidak pernah
melempar fatal, dan baris uji dibersihkan sendiri (by id) di akhir.

Hasil terakhir: **SEMUA PASS** (13/13).

## 2. Uji endpoint (read-only, tanpa menulis apa pun)

Jalankan endpoint SIBER secara lokal di atas `siber_prod_copy`:

```bash
cd D:\ApplicationWeb\siber\public
SIBER_DB_NAME=siber_prod_copy SIBER_DB_USER=root SIBER_DB_PASS= \
  C:\xampp\php\php.exe -S 127.0.0.1:8091 -t .
```

Lalu (token ada di `public/wa-gateway/_config.php`):

| Uji | Harapan | Hasil terakhir |
|---|---|---|
| `GET /wa-gateway/jobs.php` tanpa token | 401 | ✅ 401 |
| `GET …?limit=5` dengan token | 200 + kontrak `{ok,data,total,rekap}` | ✅ 200, `total:0` |
| `GET` dengan token salah | 401 | ✅ 401 |
| `POST /wa-gateway/ack.php` tanpa token | 401 | ✅ 401 |
| `POST` ref/status tak dikenal | 422 | ✅ 422 |
| `POST` ref tidak ada di DB | 404 | ✅ 404 |

Bukti penting: dengan filter `WA_MULAI_DARI` (default `2026-09-28 00:00:00`), **68 pesan
lama (April–Juli 2026) tidak ikut ditarik** — `total: 0` walau `rekap` menunjukkan
`pending: 68`.

## 3. Uji rantai penuh (cronjob → antrean → gateway) — BELUM dijalankan

Uji ini perlu MENULIS lalu MENGHAPUS baris uji di `siber_prod_copy`, jadi menunggu izin
pemilik data. Langkahnya (aman, hanya salinan lokal):

1. Salin salah satu cronjob ke `tests/siber-integration/tmp/` dengan kredensial lokal
   (`dbName=siber_prod_copy`, `dbUser=root`, `dbPass=''`) dan salin juga `_wa_outbox.php`
   ke folder yang sama (berkas cronjob memanggil `require_once __DIR__ . '/_wa_outbox.php'`).
2. Jalankan: `php -r '$_GET["key"]="KUNCI-CRONJOB-ANDA"; include "…/tmp/local_rekap_walas.php";'`
   (kunci asli cukup diketahui dari `_config.php`/URL cronjob Anda — jangan ditulis di repo)
3. Cek `outbox_wa`: baris baru berstatus `pending` dengan nomor sudah `62xx`.
4. Tarik dari gateway: tombol **Tarik job sekarang** di menu *Aplikasi Hosting* (atau
   `POST /api/pull-now` dengan header `X-Token`).
5. Harapan: baris di `outbox_wa` berubah `pending → in_queue` (ack `queued` diterima),
   dan `wa_outbox` gateway berisi pesan itu (status `queued`, menunggu sesi WA tersambung).
6. Bersihkan: hapus baris uji dari `siber_prod_copy`.

## Catatan

- `api_wa.php` (jembatan versi lama, tanpa token) **tidak disentuh** oleh integrasi ini —
  kecuali bila nanti kamu ingin menambahkan tokennya.
- `tmp/` berisi salinan cronjob ber-kredensial lokal; **jangan di-commit/unggah ke hosting**.
