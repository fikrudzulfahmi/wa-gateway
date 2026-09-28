# WA Gateway Lokal

Gateway WhatsApp untuk **server lokal Windows + XAMPP**, dipakai oleh aplikasi yang ada di
hosting (tanpa IP publik, tanpa port forwarding, tanpa tunnel).

```
Aplikasi hosting (Laravel / PHP)  ──┐
   tabel wa_outbox + 2 endpoint    │  gateway LOKAL menarik job tiap 10 detik (outbound HTTPS)
                                    └──►  engine Node.js (Baileys) ──► WhatsApp ──► penerima
Dashboard PHP (XAMPP)  ◄── baca status, kelola QR, rekap pesan
```

| Bagian | Teknologi | Alamat |
|---|---|---|
| Dashboard | PHP 8 (XAMPP) | http://localhost/wa-gateway |
| Engine WA | Node.js 22 + Baileys (WhatsApp Web multi-device) | http://127.0.0.1:3001 (internal) |
| Basis data | MySQL XAMPP | database `wa_gateway` |
| Integrasi hosting | 2 endpoint + 1 tabel | lihat `clients/README.md` |

---

## 1. Yang perlu sudah ada

- XAMPP (Apache + MySQL) — sudah ✅
- Node.js LTS (v20+) — sudah ✅ (v22 terdeteksi)

## 2. Pemasangan (sekali saja)

```bash
# a. Skema database
"C:\xampp\mysql\bin\mysql.exe" -u root < D:\ApplicationWeb\wa-gateway\sql\schema.sql

# b. Dependensi engine
cd D:\ApplicationWeb\wa-gateway\engine && npm install --allow-git=all

# c. Tautkan dashboard ke htdocs (sudah dilakukan)
#    C:\xampp\htdocs\wa-gateway  ->  D:\ApplicationWeb\wa-gateway\dashboard
```

## 3. Menjalankan

1. **XAMPP Control Panel** → Start **Apache** dan **MySQL**.
2. Dobel klik `start-gateway.bat` (menjalankan engine) — biarkan jendelanya terbuka.
3. Buka **http://localhost/wa-gateway** → login `admin` / `admin123`.
4. Menu **Dashboard** → panel **Scan QR** → di HP: WhatsApp → **Perangkat tertaut** →
   **Tautkan perangkat** → scan.
5. Setelah tersambung, status berubah **Tersambung** dan nomor tampil. Uji dengan menu **Kirim Pesan**.
6. **Ganti password** di menu Pengaturan.

## 4. Fitur

| Menu | Isi |
|---|---|
| Dashboard | status koneksi WA per sesi (nomor, kuota harian, kapan tersambung), **QR otomatis**, ringkasan pesan hari ini |
| Scan QR | otomatis berganti tiap beberapa detik sampai tersambung; tombol **Sambungkan ulang** & **Logout WA** |
| Kirim Pesan | kirim teks/gambar/dokumen + **impor massal CSV** (bisa dari Excel) |
| Rekap Terkirim | filter tanggal/status/aplikasi/nomor/kata kunci, **export CSV (Excel)**, **kirim ulang** yang gagal |
| Antrean | pesan menunggu/sedang kirim/gagal, batal & kirim ulang, hitungan realtime |
| Pesan Masuk | balasan dari penerima (mis. konfirmasi/STOP) |
| Aplikasi Hosting | daftar aplikasi + token + interval tarik job (mode PULL), tombol **Tarik job sekarang** |
| Log Engine | jejak kejadian: koneksi, kirim, gagal, pull |
| Pengaturan | jeda kirim, kuota harian per sesi, jeda semua antrean, ganti password, bersihkan data |
| Panduan Integrasi | kontrak API + contoh kode PHP native & Laravel siap tempel |

Status pesan yang dilacak: `antrean → dikirim → terkirim → sampai (centang 2) → dibaca (centang biru)`,
plus `gagal` beserta alasannya. **Dibaca** hanya muncul bila setelan privasi penerima mengizinkan.

## 5. Keamanan (penting)

- Dashboard hanya untuk jaringan lokal. Jika perlu diakses dari HP di LAN, batasi IP di
  `httpd.conf` dan pakai password kuat.
- `wa_gateway` memakai user MySQL `root` XAMPP (tanpa password). Idealnya dibuat user khusus —
  **terkendala**: tabel sistem `mysql.global_priv` MariaDB di mesin ini korup (`ERROR 1034/1030`),
  sehingga `CREATE USER`/`GRANT` gagal. Perbaiki lebih dulu bila ingin memakai user terpisah.
- Token engine & token tiap aplikasi sebaiknya diganti dari bawaan dan tidak ditulis di kode publik.
- Folder `engine/sessions/` = kunci akun WhatsApp. Jangan dibagikan; cadangkan berkala.

## 6. Operasional

- **Auto-start setelah reboot**: lihat `docs/OPERASIONAL.md` (NSSM / Task Scheduler).
- **Backup**: `engine/sessions/` + dump database `wa_gateway`.
- **Log**: menu Log Engine, atau console jendela engine.
- **QR kedaluwarsa/berhenti**: menu Dashboard → **Sambungkan ulang**.

## 7. Batasan yang harus disadari

1. Inkoneksi ini memakai WhatsApp Web **tidak resmi** (Baileys). Risiko pemblokiran nomor ada —
   pakai **nomor khusus**, jangan blast ribuan pesan sekaligus.
2. `dibaca` tidak selalu bisa dipantau (tergantung privasi penerima); `sampai` adalah bukti terkuat.
3. Mode PULL punya jeda kirim ≤ interval tarik (default 10 detik).
4. Nomor harus didaftarkan WhatsApp dan HP dalam keadaan online saat pemindaian QR.

Dibuat oleh **fikrudzulfahmi**.
