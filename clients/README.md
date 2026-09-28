# Perangkat integrasi sisi hosting (Mode PULL)

Gateway lokal **menarik** pesan dari aplikasi hosting, jadi aplikasi tidak perlu IP publik,
port forwarding, atau tunnel. Cukup 3 langkah:

## Langkah pemasangan

1. **Buat tabel antrean di database aplikasi hosting**
   Jalankan `sql/wa_outbox_hosting.sql` (phpMyAdmin cPanel → Import).
   Laravel: pakai migration `laravel/create_wa_outbox_table.php`.

2. **Tempel 2 endpoint di aplikasi hosting** (path boleh disesuaikan):
   - `php-native/jobs.php` → `GET /api/wa-gateway/jobs` (daftar pesan menunggu)
   - `php-native/jobs/ack.php` → `POST /api/wa-gateway/jobs/ack` (laporan status)
   
   Sesuaikan di kedua berkas:
   - `WA_GATEWAY_TOKEN` = token aplikasi dari dashboard → menu **Aplikasi Hosting**
   - path `require` menuju koneksi database aplikasi
   
   Laravel: pakai `laravel/WaGatewayController.php` + entri `routes/api.php` di komentar berkas.

3. **Daftarkan aplikasi di dashboard gateway** → menu **Aplikasi Hosting**:
   - Nama: `SIM PKL`
   - Base URL: `https://pkl.ingintau.my.id` (tanpa `/` di akhir)
   - Path: `/api/wa-gateway/jobs` dan `/api/wa-gateway/jobs/ack`
   - Interval: 10 detik (boleh 5–15)

4. **Kirim pesan dari aplikasi** — cukup sisipkan satu baris:
   ```php
   // PHP native
   WaGateway::kirim('081234567890', 'Ananda hadir pukul 07:01.');
   
   // Laravel
   WaGatewayService::kirim($siswa->hp_wali, 'Ananda hadir pukul 07:01.');
   ```
   Gateway akan menariknya dalam ≤ `poll_interval` detik, mengirim, lalu melaporkan
   status (`diproses → terkirim → sampai → dibaca` / `gagal`) kembali ke tabel yang sama.

## Urutan status di tabel hosting

| Status di hosting | Artinya | Diisi oleh |
|---|---|---|
| `pending` | menunggu ditarik gateway | aplikasi |
| `diproses` | sudah diambil gateway, sedang diantrekan | ack `queued` |
| `terkirim` | WhatsApp server sudah menerima | ack `sent` |
| `sampai` | masuk ke HP penerima (centang 2) | ack `delivered` |
| `dibaca` | dibuka penerima (centang biru) | ack `read` |
| `gagal` | gagal setelah 3 percobaan | ack `failed` |

## Aturan penting

- **Jangan** memakai status `pending` untuk menandai "sedang dikirim" — biarkan gateway
  yang mengubahnya, supaya pesan tidak terkirim dua kali.
- Endpoint `jobs` sebaiknya **hanya** mengembalikan pesan milik aplikasi itu (token sudah memisahkan).
- Endpoint `ack` harus **idempoten** (update biasa, bukan insert).
- Kalau internet lokal mati, pesan tetap `pending` di hosting dan akan terkirim saat gateway hidup lagi.
- Kirim massal (ratusan nomor): pecah per rentang waktu dan pantau menu **Antrean** —
  gateway sudah punya jeda acak 3–8 detik + kuota harian per nomor.
