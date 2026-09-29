# Panduan Pemasangan di Server Lokal (Windows + XAMPP)

Panduan ini untuk memasang WA Gateway di **server sekolah** yang diakses **jarak jauh lewat AnyDesk**
(atau teamviewer/SSH) — jadi setiap langkah punya cara memeriksa hasilnya, dan engine dijalankan
sebagai **layanan Windows** supaya tidak mati saat sesi remote ditutup atau server di-restart.

Estimasi waktu: 20–30 menit, sekali saja. Setelah itu pemeliharaan hanya `git pull` + restart layanan.

---

## 0. Ringkasan cepat (kalau tidak mau membaca panjang)

```powershell
# 1. Unduh kode
git clone https://github.com/fikrudzulfahmi/wa-gateway.git D:\wa-gateway

# 2. Jalankan skrip pemasangan (memeriksa prasyarat, impor DB, .env, npm, junction, layanan)
cd D:\wa-gateway
powershell -ExecutionPolicy Bypass -File install-server.ps1

# 3. Setelah selesai: buka http://localhost/wa-gateway  -> login admin/admin123 -> pindai QR
```

Skrip `install-server.ps1` **idempoten** (aman dijalankan berulang) dan punya mode uji tanpa mengubah
apa pun: `powershell -ExecutionPolicy Bypass -File install-server.ps1 -CheckOnly`.

---

## 1. Prasyarat di server sekolah (cek dulu, jangan lompat)

| Kebutuhan | Cara memeriksa | Catatan |
|---|---|---|
| Windows 10/11 atau Server | `winver` | apa saja yang penting stabil & tidak sleep |
| **XAMPP** (Apache + MySQL + PHP 8) | `C:\xampp\php\php.exe -v` | bila belum: unduh dari apachefriends.org (versi PHP 8.x) |
| **Node.js LTS** | `node -v` (harus v20+) | unduh dari nodejs.org; centang *Add to PATH* saat instal |
| **Git** | `git --version` | untuk `git clone`/`git pull` |
| RAM & disk | `systeminfo` | minimal 2 GB RAM bebas, 2 GB disk (tidak ada Chromium — engine ini ringan) |
| **Internet** | lihat §2 | wajib: WhatsApp + menarik pesan dari hosting |
| Waktu & zona waktu | `tzutil /g` | harus `Asia/Jakarta` (jam pesan & kuota harian) |

> **Kalau XAMPP belum ada:** pasang XAMPP lebih dulu (default `C:\xampp`), lalu jalankan
> XAMPP Control Panel → Start **Apache** dan **MySQL**. Baru lanjut ke langkah ini lagi.

---

## 2. Internet di jaringan sekolah — WAJIB diuji lebih dulu

WhatsApp dan penarikan pesan dari hosting berjalan lewat HTTPS. Jaringan sekolah sering memakai
**proxy/filter** yang bisa memblokirnya. Uji dulu sebelum memasang apa pun:

```powershell
Test-NetConnection web.whatsapp.com -Port 443              # WhatsApp (koneksi engine)
Test-NetConnection domain-sample.com -Port 443             # hosting aplikasi (mode PULL)
Test-NetConnection github.com -Port 443                    # unduh kode
```

`TcpTestSucceeded : True` = jalur aman. Kalau **False**, hubungi admin jaringan sekolah
(biasanya perlu pengecualian untuk domain tersebut) — memasang gateway tidak akan berhasil
sebelum ini dibuka. Tidak perlu membuka port masuk apa pun: semua koneksi keluar dari server.

**Cek tambahan (jangan dilewatkan):** pastikan server tidak ikut tidur, karena itu memutus WhatsApp:

```powershell
powercfg /change standby-timeout-ac 0
powercfg /change hibernate-timeout-ac 0
powercfg /change monitor-timeout-ac 15
```

---

## 3. Ambil kode dari GitHub

```powershell
mkdir D:\ 2>$null
cd D:\
git clone https://github.com/fikrudzulfahmi/wa-gateway.git wa-gateway
cd D:\wa-gateway
```

Kalau nanti perlu memperbarui versi: `cd D:\wa-gateway; git pull` (lihat §9).

---

## 4. Jalankan skrip pemasangan

```powershell
cd D:\wa-gateway
powershell -ExecutionPolicy Bypass -File install-server.ps1
```

Yang dikerjakan skrip (semuanya diperiksa dulu, aman diulang):

1. Memeriksa XAMPP, Node.js, Git, MySQL, dan zona waktu.
2. Membuat database `wa_gateway` + 8 tabel + 1 view dari `sql\schema.sql` (bila belum ada).
3. Membuat `engine\.env` dari `engine\.env.example` (bila belum ada) — berisi kredensial DB lokal.
4. Memasang dependensi engine: `npm install --allow-git=all` + `@whiskeysockets/baileys@6.7.24`
   (tanpa `--allow-git=all`, npm 12 menolak dependensi `libsignal`).
5. Menautkan dashboard ke htdocs: `C:\xampp\htdocs\wa-gateway` → `D:\wa-gateway\dashboard` (junction).
6. Memasang engine sebagai **layanan Windows** `WAGateway` (auto-start) memakai NSSM.

Ingin melihat kondisi tanpa mengubah apa pun:

```powershell
powershell -ExecutionPolicy Bypass -File install-server.ps1 -CheckOnly
```

---

## 5. Engine sebagai layanan Windows (bagian terpenting untuk server remote)

Kenapa layanan, bukan `start-gateway.bat`: jendela konsol mati saat sesi AnyDesk ditutup, user logoff,
atau server restart → pesan berhenti terkirim tanpa ada yang sadar.

Skrip §4 sudah memasangnya bila `nssm.exe` tersedia. Kalau NSSM belum ada, unduh
`nssm.exe` (dari nssm.cc, arsipnya berisi folder win64) → taruh di `D:\wa-gateway\tools\nssm.exe`,
lalu jalankan skrip sekali lagi. Perintah yang dijalankan skrip:

```powershell
nssm install  WAGateway "C:\Program Files\nodejs\node.exe" "src/index.js"
nssm set      WAGateway AppDirectory "D:\wa-gateway\engine"
nssm set      WAGateway AppStdout   "D:\wa-gateway\engine\logs\out.log"
nssm set      WAGateway AppStderr   "D:\wa-gateway\engine\logs\err.log"
nssm set      WAGateway AppRotateFiles 1
nssm set      WAGateway Start SERVICE_AUTO_START
nssm start    WAGateway
```

Perintah harian:

```powershell
nssm status WAGateway      # SERVICE_RUNNING = baik
nssm restart WAGateway     # setelah git pull / ubah .env
nssm stop WAGateway
```

**Apache + MySQL juga harus auto-start.** Paling mudah: XAMPP Control Panel → tombol **Config** →
centang *Autostart* untuk Apache dan MySQL. (Alternatif: `C:\xampp\xampp_start.exe` sebagai task
saat startup.)

> Catatan teknis: kalau engine dijalankan sebagai layanan, dashboard tetap berjalan di Apache dan
> hanya bisa diakses dari server itu sendiri (`http://localhost/wa-gateway`). Itu memang disengaja.

---

## 6. Pemeriksaan setelah pemasangan

```powershell
# engine hidup?
curl.exe -s http://127.0.0.1:3001/api/health
#   harapan: {"ok":true,...,"sessions_total":1,"sessions_connected":0}

# MySQL jalan & tabel lengkap?
C:\xampp\mysql\bin\mysql.exe -u root -e "SELECT COUNT(*) AS tabel FROM information_schema.tables WHERE table_schema='wa_gateway';"
#   harapan: 10 (8 tabel + view + ...)

# dashboard? (buka di browser server)
start http://localhost/wa-gateway

# dashboard hanya untuk localhost (keamanan)
netstat -ano | findstr ":80 " | findstr LISTENING
```

---

## 7. Memindai QR lewat AnyDesk

1. Di server: buka `http://localhost/wa-gateway` → login `admin` / `admin123`.
2. Panel **Scan QR** menampilkan QR yang berganti otomatis tiap beberapa detik.
3. Di HP: **WhatsApp → Perangkat tertaut → Tautkan perangkat** → arahkan ke layar remote.
   - Di AnyDesk, set kualitas **Best quality** / skala **100%** supaya QR tidak buram.
   - Klik di area layar dashboard dulu supaya jendela aktif; QR 340 px aman terbaca dari layar.
   - Kalau kedaluwarsa sebelum sempat dipindai: tunggu QR baru muncul (tidak perlu klik apa pun),
     atau tekan **Sambungkan ulang** di panel Status Koneksi.
4. Setelah berhasil: badge berubah **Tersambung** dan nomor tampil. Uji lewat menu **Kirim Pesan**
   ke nomor HP-mu sendiri sebelum memakai nomor penting.
5. **Ganti password** dashboard di menu **Pengaturan**.

> Kalau nomornya sering berubah, buat sesi WA baru (menu Dashboard → *Tambah sesi WA*) daripada
> memakai tombol **Logout WA** pada nomor yang sedang dipakai.

---

## 8. Menghubungkan aplikasi hosting (mis. SIBER)

1. Ambil **token** aplikasi: dashboard → menu **Aplikasi Hosting**. Kalau belum ada, tambahkan
   (nama, base URL tanpa `/` di akhir, path `/wa-gateway/jobs.php` dan `/wa-gateway/ack.php`,
   interval 10 detik) — token dibuat otomatis.
2. Tempel token yang sama di sisi hosting (`public/wa-gateway/_config.php`) — lihat
   `clients/README.md` untuk pola lengkapnya.
3. Uji dengan tombol **Tarik job sekarang**; kolom *Terakhir/Hasil* harus menampilkan `ok:N`.
   Kalau masih `error: respons bukan daftar JSON`, berarti berkas endpoint belum ada/salah path
   di hosting — engine memang sengaja menolak "sukses palsu".

---

## 9. Perawatan rutin (jalankan dari sesi AnyDesk)

```powershell
# perbarui versi gateway
cd D:\wa-gateway; git pull
nssm restart WAGateway

# (hanya bila package.json berubah)
cd D:\wa-gateway\engine; npm install --allow-git=all

# cek cepat: engine, sesi WA, pesan menunggu
curl.exe -s http://127.0.0.1:3001/api/health
C:\xampp\mysql\bin\mysql.exe -u root wa_gateway -e "SELECT name,status FROM wa_sessions; SELECT COUNT(*) AS antrean FROM wa_outbox WHERE status IN ('queued','sending');"
```

**Backup mingguan** (folder sesi WA = kunci akun; hilang berarti harus memindai QR ulang):

```powershell
$tgl = Get-Date -Format 'yyyyMMdd'
robocopy D:\wa-gateway\engine\sessions D:\backup\wa-sessions-$tgl /MIR
C:\xampp\mysql\bin\mysqldump.exe -u root wa_gateway > D:\backup\wa_gateway-$tgl.sql
```

Jadikan ini Task Scheduler mingguan supaya tidak bergantung pada ingatan.

---

## 10. Memindahkan sesi dari komputer lama (tanpa pindai QR lagi)

1. Di komputer lama: **hentikan engine**, lalu salin folder `engine\sessions\` (mis. ke flashdisk
   atau lewat AnyDesk file transfer).
2. Hentikan layanan di server baru, timpa `D:\wa-gateway\engine\sessions\` dengan folder itu.
3. `nssm restart WAGateway` → status sesi harus langsung `connected` tanpa QR.
4. Jangan menyalakan dua server dengan folder sesi yang sama dalam waktu bersamaan (sesi bisa
   saling menendang). Pastikan yang lama sudah dimatikan.

---

## 11. Kalau ada masalah (khas di server sekolah)

| Gejala | Penyebab umum | Tindakan |
|---|---|---|
| `health` tidak merespons | layanan tidak jalan / Node bermasalah | `nssm status WAGateway`, lihat `engine\logs\err.log` |
| Status WA kembali `qr` terus | nomor belum dipindai, atau jaringan memblokir WhatsApp | uji §2; pindai ulang §7 |
| `Sesi Dicabut` (`logged_out`) | sesi dihapus dari HP (WhatsApp → Perangkat tertaut) | tombol **Logout WA**, lalu pindai QR baru |
| Pesan menumpuk di **Antrean** | worker dijeda, kuota harian habis, atau sesi tidak tersambung | menu **Pengaturan** & **Dashboard** |
| Aplikasi hosting tidak menarik pesan | token/path salah, atau endpoint belum diunggah | menu **Aplikasi Hosting** → **Tarik job sekarang** |
| Server mati mendadak → WhatsApp putus | listrik/power option | `nssm` auto-start sudah menangani; aktifkan juga auto-start Apache/MySQL & matikan sleep (§2) |
| Dashboard tidak bisa dibuka dari komputer lain | memang sengaja (localhost saja) | akses lewat AnyDesk, atau lihat §12 |

---

## 12. Opsional: dashboard bisa dibuka dari komputer lain di LAN sekolah

Default: dashboard **hanya** dari server itu sendiri (aman). Bila operator perlu memantau dari PC
lain di sekolah, batasi ke jaringan internal saja:

1. `C:\xampp\apache\conf\extra\httpd-vhosts.conf` — tambahkan:
   ```apache
   <Directory "C:/xampp/htdocs/wa-gateway">
       Require ip 127.0.0.1
       Require ip 192.168.1.0/24      # sesuaikan subnet sekolah
       Require ip 10.0.0.0/8
   </Directory>
   ```
2. `C:\xampp\apache\conf\httpd.conf` → `Listen 80` (sudah default), restart Apache.
3. Buka dari PC lain: `http://<IP-server>/wa-gateway` (pakai password kuat — jangan root XAMPP default).
4. **Jangan** membuka port 80 ke internet.

---

## 13. Checklist akhir (centang sebelum ditinggalkan)

- [ ] `Test-NetConnection web.whatsapp.com -Port 443` → True
- [ ] XAMPP Apache & MySQL auto-start aktif
- [ ] Layanan `WAGateway` berstatus `SERVICE_RUNNING` dan start-mode automatic
- [ ] `curl.exe -s http://127.0.0.1:3001/api/health` → `"ok":true`
- [ ] Dashboard: status WA **Tersambung**, nomor benar
- [ ] Uji kirim ke nomor sendiri berhasil (status naik ke **Terkirim/Sampai**)
- [ ] Password dashboard sudah diganti dari `admin123`
- [ ] Aplikasi hosting terdaftar + token sudah sama dengan sisi hosting
- [ ] Backup `engine\sessions\` + dump DB dijadwalkan
- [ ] AnyDesk: **Unattended Access** aktif (agar bisa masuk lagi tanpa bantuan orang di lokasi)
