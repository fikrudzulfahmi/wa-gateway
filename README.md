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
| Engine WA | Node.js 22 + Baileys 6.7.24 (WhatsApp Web multi-device) | http://127.0.0.1:3001 (internal) |
| Basis data | MySQL XAMPP | database `wa_gateway` |
| Integrasi hosting | 2 endpoint + 1 tabel | lihat [`clients/README.md`](clients/README.md) |

> **Panduan singkat untuk dibaca di layar server:** [`CARA-INSTAL-SINGKAT.txt`](CARA-INSTAL-SINGKAT.txt) —
> isinya sama dengan bagian **Pemasangan** di bawah ini. Versi lengkap + penjelasan tiap langkah:
> [`docs/INSTAL-SERVER.md`](docs/INSTAL-SERVER.md).

---

## 1. Sebelum mulai: yang harus sudah ada di server

| Perlu | Cara memasang |
|---|---|
| **XAMPP** (Apache + MySQL) | https://www.apachefriends.org/download.html |
| **Node.js LTS** (v20+, teruji v22) | https://nodejs.org — pilih **LTS**, centang **Add to PATH** |
| **Git** (untuk mengambil & memperbarui kode) | https://git-scm.com |

Setelah memasang, **tutup lalu buka ulang** jendela Command Prompt yang sedang dipakai.

## 2. Pemasangan — 6 langkah (dobel klik, untuk server sekolah via AnyDesk)

### LANGKAH 1 — Jalankan XAMPP
Buka **XAMPP Control Panel** → klik **Start** pada **Apache** dan **MySQL** — keduanya harus hijau.

### LANGKAH 2 — Ambil kodenya (sekali saja)
Buka **Command Prompt**, jalankan satu per satu:

```bat
cd /d D:\
git clone https://github.com/fikrudzulfahmi/wa-gateway.git
cd wa-gateway
```

Kalau muncul *"git tidak dikenal"* → pasang Git, lalu buka ulang Command Prompt.
Folder bawaan pemasangan: `D:\wa-gateway` (boleh diganti, sesuaikan langkah berikutnya).

### LANGKAH 3 — Periksa dulu (TIDAK mengubah apa pun)
```bat
CEK-SERVER.bat
```
Baca hasilnya. Yang penting: baris **[ OK ]** berarti siap; **[AKAN]** berarti masih akan dipasang
(wajar di server baru); **[GAGAL]** harus dibereskan dulu (pasang yang kurang, lalu ulangi).

### LANGKAH 4 — Pasang (dobel klik)
Dobel klik **`PASANG-GATEWAY.bat`** → Windows minta izin Administrator → **Yes** → tunggu 1–3 menit.
Aman dijalankan berulang. Yang dikerjakan: memastikan skema database `wa_gateway`, membuat
`engine\.env` dari contohnya, `npm install --allow-git=all` + pin Baileys 6.7.24, menautkan dashboard
ke `C:\xampp\htdocs\wa-gateway` (junction), dan memasang **auto-start** engine sebagai layanan `WAGateway`
(NSSM bila ada, jika tidak lewat Task Scheduler sebagai SYSTEM saat Windows menyala).
Akhirnya harus **[ OK ]**; **[WARN]** hanya saran, **[GAGAL]** tidak boleh ada.

### LANGKAH 5 — Pindai QR WhatsApp
1. Di server buka browser: **http://localhost/wa-gateway** → login **`admin` / `admin123`**
2. Panel **Scan QR** menampilkan QR yang berganti otomatis.
3. Di HP: **WhatsApp → Perangkat tertaut → Tautkan perangkat** → arahkan kamera ke QR di layar server.
   (Lewat AnyDesk, set kualitas layar **Best quality** supaya QR tidak buram.)
4. Berhasil bila status berubah **Tersambung** dan nomor tampil. Uji: menu **Kirim Pesan** ke nomor sendiri.
5. **Ganti password** di menu **Pengaturan**.

### LANGKAH 6 — Cek sudah benar-benar jalan
```bat
CEK-SERVER.bat
```
Bagian **VERIFIKASI AKHIR** harus menampilkan `engine menjawab: ok=true …` dan
`dashboard menjawab di http://localhost/wa-gateway`. Setelah itu sesi AnyDesk boleh ditutup —
gateway tetap jalan sendiri (auto-start sudah dipasang).

## 3. Menyambungkan aplikasi di hosting (setelah gateway terpasang)

Aplikasi hosting menyediakan **2 endpoint** + **1 tabel antrean** (contoh siap pakai untuk PHP native
dan Laravel ada di [`clients/`](clients/) dan menu **Panduan Integrasi** di dashboard).

Di dashboard → menu **Aplikasi Hosting → Tambah aplikasi**:

| Kolom | Isi |
|---|---|
| Nama aplikasi | mis. `SIBER` |
| Base URL | alamat aplikasi, **tanpa** garis miring di akhir, mis. `https://siber.pondokminggirsari.com` |
| Path daftar pesan | path berkas yang benar-benar ada, mis. **`/wa-gateway/jobs.php`** |
| Path laporan balik | mis. **`/wa-gateway/ack.php`** |
| Sesi WA | `(otomatis: sesi pertama)` |
| Interval / Jumlah per tarik | `10` detik / `20` pesan |
| Token | 1 token per aplikasi — **harus sama** dengan `WA_GATEWAY_TOKEN` di berkas `.php` aplikasi |
| Aktif | ✅ |

Klik **Uji koneksi** pada baris itu: dashboard memanggil URL **persis** seperti engine, lalu memberi
putusan yang bisa langsung ditindak:

| Putusan | Artinya |
|---|---|
| **BERHASIL** (JSON) | endpoint benar — siap |
| **TOKEN TIDAK COCOK** (401/403) | samakan kolom Token dengan `WA_GATEWAY_TOKEN` di aplikasi |
| **PATH SALAH / ENDPOINT BELUM ADA** | balasan bukan JSON (mis. halaman HTML) → path/berkas salah |
| **GAGAL terhubung** | Base URL atau koneksi internet server bermasalah |

> ⚠️ **Kesalahan yang paling sering:** kolom Path dibiarkan kosong atau tanpa akhiran berkas.
> Bila dikosongkan, sistem memakai nilai bawaan `/api/wa-gateway/jobs` (bukan berkas Anda);
> menuliskan `/wa-gateway/jobs` **tanpa `.php`** juga gagal — aplikasi menjawab halaman HTML lalu
> engine melaporkan *"respons bukan daftar JSON"*. Selalu tulis lengkap: `/wa-gateway/jobs.php`.

Setelah itu klik **Tarik job sekarang** untuk uji, atau biarkan: gateway menarik sendiri tiap
**10 detik** dan mengirim (jeda acak 3–8 detik antar pesan; kuota 300 pesan/hari/nomor).

## 4. Fitur

| Menu | Isi |
|---|---|
| Dashboard | status koneksi WA per sesi (nomor, kuota harian, kapan tersambung), **QR otomatis**, ringkasan pesan hari ini |
| Scan QR | otomatis berganti tiap beberapa detik sampai tersambung; tombol **Sambungkan ulang** & **Logout WA** |
| Kirim Pesan | kirim teks/gambar/dokumen + **impor massal CSV** (bisa dari Excel) |
| Rekap Terkirim | filter tanggal/status/aplikasi/nomor/kata kunci, **export CSV (Excel)**, **kirim ulang** yang gagal |
| Antrean | pesan menunggu/sedang kirim/gagal, batal & kirim ulang, hitungan realtime |
| Pesan Masuk | balasan dari penerima (mis. konfirmasi/STOP) |
| Aplikasi Hosting | daftar aplikasi + token + interval tarik job (mode PULL), tombol **Uji koneksi** & **Tarik job sekarang** |
| Log Engine | jejak kejadian: koneksi, kirim, gagal, pull |
| Pengaturan | jeda kirim, kuota harian per sesi, jeda semua antrean, ganti password, bersihkan data |
| Panduan Integrasi | kontrak API + contoh kode PHP native & Laravel siap tempel |

Status pesan yang dilacak: `antrean → dikirim → terkirim → sampai (centang 2) → dibaca (centang biru)`,
plus `gagal` beserta alasannya. **Dibaca** hanya muncul bila setelan privasi penerima mengizinkan.

## 5. Kalau ada masalah

| Gejala | Tindakan |
|---|---|
| `engine belum menjawab` | jalankan `schtasks /run /tn WAGateway` (atau `start-gateway.bat`) |
| `dashboard belum bisa dibuka` | XAMPP → Start Apache; periksa junction `C:\xampp\htdocs\wa-gateway` |
| Status WA terus **Perlu Scan QR** | ulangi Langkah 5; pastikan server bisa membuka `web.whatsapp.com` |
| Pesan menumpuk di **Antrean** | cek status sesi (harus **Tersambung**) dan menu Pengaturan |
| Panel aplikasi menulis *"respons bukan daftar JSON"* | klik **Uji koneksi** → biasanya kolom Path tanpa `.php` (lihat bagian 3) |
| Jendela engine dibanjiri `Bad MAC` / `Failed to decrypt` | normal (pesan masuk gagal didekripsi), sudah diredam otomatis; **tapi** pastikan tidak ada dua engine memakai sesi yang sama — lihat [`docs/OPERASIONAL.md`](docs/OPERASIONAL.md) bagian 8 |

Cara tercepat melapor: kirim **tangkapan layar hasil `CEK-SERVER.bat`**.

## 6. Keamanan (penting)

- Dashboard hanya untuk jaringan lokal. Jika perlu diakses dari HP di LAN, batasi IP di
  `httpd.conf` dan pakai password kuat.
- `wa_gateway` memakai user MySQL `root` XAMPP (tanpa password). Idealnya dibuat user khusus —
  **terkendala**: tabel sistem `mysql.global_priv` MariaDB di mesin ini korup (`ERROR 1034/1030`),
  sehingga `CREATE USER`/`GRANT` gagal. Perbaiki lebih dulu bila ingin memakai user terpisah.
- Token engine & token tiap aplikasi sebaiknya diganti dari bawaan dan tidak ditulis di kode publik.
- Folder `engine/sessions/` = kunci akun WhatsApp. Jangan dibagikan; cadangkan berkala.

## 7. Operasional

- **Auto-start setelah reboot**: dipasang otomatis oleh `PASANG-GATEWAY.bat` sebagai `WAGateway`
  (NSSM/service atau Task Scheduler); penjelasan & cara memeriksa: [`docs/OPERASIONAL.md`](docs/OPERASIONAL.md).
- **Memperbarui versi terpasang di server**: `git pull` lalu restart engine
  (`schtasks /end /tn WAGateway` → `schtasks /run /tn WAGateway`). `npm install` ulang hanya bila
  `package.json` berubah.
- **Backup**: `engine/sessions/` + dump database `wa_gateway`.
- **Memindahkan server**: salin `engine\sessions\` dari server lama agar tidak perlu memindai QR lagi.
- **Kredensial dashboard/DB berbeda?** Jangan ubah `dashboard/config.php` (berkas yang di-commit);
  buat `dashboard/config.local.php` (sudah di-ignore) berisi mis.
  `<?php return ['db' => ['user' => 'wa_gateway', 'pass' => 'rahasia-anda']];`

## 8. Batasan yang harus disadari

1. Integrasi ini memakai WhatsApp Web **tidak resmi** (Baileys). Risiko pemblokiran nomor ada —
   pakai **nomor khusus**, jangan blast ribuan pesan sekaligus.
2. `dibaca` tidak selalu bisa dipantau (tergantung privasi penerima); `sampai` adalah bukti terkuat.
3. Mode PULL punya jeda kirim ≤ interval tarik (default 10 detik).
4. Nomor harus didaftarkan WhatsApp dan HP dalam keadaan online saat pemindaian QR.

## 9. Lampiran: pemasangan manual (untuk pengembangan di mesin ini)

Mesin pengembang memakai folder `D:\ApplicationWeb\wa-gateway` dan langkah manual (setara isi
`install-server.ps1`):

```bat
git clone https://github.com/fikrudzulfahmi/wa-gateway.git D:\ApplicationWeb\wa-gateway
cd /d D:\ApplicationWeb\wa-gateway

:: 1. Skema database
"C:\xampp\mysql\bin\mysql.exe" -u root < sql\schema.sql

:: 2. Konfigurasi engine (.env TIDAK ikut repo)
cd engine
copy .env.example .env

:: 3. Dependensi (WAJIB --allow-git=all: ada dependensi git "libsignal")
npm install --allow-git=all

:: 4. Tautkan dashboard ke htdocs (junction, jangan disalin)
powershell -NoProfile -Command "New-Item -ItemType Junction -Path 'C:\xampp\htdocs\wa-gateway' -Target 'D:\ApplicationWeb\wa-gateway\dashboard'"

:: 5. Jalankan
start-gateway.bat
```

Versi baris perintah dari pemasangan otomatis (skrip yang sama dengan `PASANG-GATEWAY.bat`):

```bat
powershell -ExecutionPolicy Bypass -File install-server.ps1 -CheckOnly   :: hanya periksa
powershell -ExecutionPolicy Bypass -File install-server.ps1              :: pasang
```

---

Dibuat oleh **fikrudzulfahmi**.
