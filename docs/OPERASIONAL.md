# Operasional — Menjalankan Gateway Otomatis di Windows

## 1. Menjalankan manual (paling sederhana)

1. XAMPP Control Panel → Start **Apache** + **MySQL**.
2. Dobel klik `D:\ApplicationWeb\wa-gateway\start-gateway.bat`.
3. Biarkan jendela itu terbuka (boleh di-minimize). Tutup = gateway berhenti.

## 2. Otomatis saat Windows menyala — Opsi A: Task Scheduler (tanpa instalasi tambahan)

1. Buka **Task Scheduler** → **Create Task**.
2. Tab **General**: nama `WA Gateway Engine`, centang **Run whether user is logged on or not**,
   centang **Run with highest privileges**.
3. Tab **Triggers**: New → **At startup** (atau *At log on* bila ingin terlihat) → OK.
4. Tab **Actions**: New →
   - Program/script: `C:\Program Files\nodejs\node.exe` (cek dengan `where node`)
   - Add arguments: `src/index.js`
   - Start in: `D:\ApplicationWeb\wa-gateway\engine`
5. Tab **Settings**: centang **If the task fails, restart every 1 minute** (3 kali).
6. OK, lalu klik kanan → **Run** untuk menguji. Cek: `http://127.0.0.1:3001/api/health` harus `"ok":true`.

## 3. Otomatis sebagai Windows Service — Opsi B: NSSM

```powershell
# unduh nssm.exe (https://nssm.cc/download) lalu:
nssm install WAGateway "C:\Program Files\nodejs\node.exe" "src/index.js"
nssm set WAGateway AppDirectory "D:\ApplicationWeb\wa-gateway\engine"
nssm set WAGateway AppStdout "D:\ApplicationWeb\wa-gateway\engine\logs\out.log"
nssm set WAGateway AppStderr "D:\ApplicationWeb\wa-gateway\engine\logs\err.log"
nssm set WAGateway Start SERVICE_AUTO_START
nssm start WAGateway
```

Pastikan folder `engine\logs\` ada lebih dulu. Hentikan: `nssm stop WAGateway`.

> Catatan: XAMPP Apache/MySQL juga harus otomatis. Cara paling mudah: XAMPP Control Panel →
> tombol **Config** → centang *Autostart* untuk Apache & MySQL, atau pakai service bawaan XAMPP.

## 4. Pemeriksaan kesehatan (health check)

```bash
curl http://127.0.0.1:3001/api/health
# {"ok":true, ..., "sessions_total":1, "sessions_connected":1, "queue_pending":0}
```

Bisa dipasang sebagai cron/task terjadwal dengan pola "watchdog": kirim peringatan hanya bila
`sessions_connected` = 0 atau engine tidak merespons.

## 5. Backup & pemulihan

| Yang dicadangkan | Alasan |
|---|---|
| `engine\sessions\` | kunci sesi WhatsApp — hilang = harus scan QR ulang |
| dump database `wa_gateway` | riwayat + rekap pesan |
| `dashboard\uploads\` | lampiran yang dikirim |

Contoh backup harian (Task Scheduler, 23:00):

```bat
"C:\xampp\mysql\bin\mysqldump.exe" -u root wa_gateway > "D:\backup\wa_gateway_%date:~-4%%date:~3,2%%date:~0,2%.sql"
robocopy "D:\ApplicationWeb\wa-gateway\engine\sessions" "D:\backup\wa-sessions" /MIR
```

## 6. Kalau ada masalah

| Gejala | Penyebab umum | Tindakan |
|---|---|---|
| Dashboard: "Engine mati" | jendela engine tertutup / Node tidak jalan | jalankan `start-gateway.bat`, cek `http://127.0.0.1:3001/api/health` |
| Badge "Perlu Scan QR" terus | belum dipindai / QR kedaluwarsa | Dashboard → **Sambungkan ulang**, pindai ulang |
| Badge "Sesi Dicabut" | sesi dihapus dari HP (WhatsApp → Perangkat tertaut) | tombol **Logout WA** lalu pindai QR baru |
| Pesan tetap "Antrean" | worker dimatikan / kuota harian habis / sesi terputus | Pengaturan → aktifkan pengiriman; cek kuota & status sesi |
| Status "Gagal: nomor tidak terdaftar WhatsApp" | nomor bukan pengguna WA | perbaiki data nomor di aplikasi |
| Pesan dari hosting tidak masuk | endpoint hosting salah token/URL | menu Aplikasi Hosting → **Tarik job sekarang**, lihat kolom *Terakhir/Hasil* |
| Port 3001 dipakai proses lain | bentrok | ubah `ENGINE_PORT` di `engine/.env` |

## 7. Tips agar nomor aman

- Pakai nomor khusus untuk gateway (bukan nomor pribadi/kantor utama).
- Biarkan jeda kirim 3–8 detik (default) atau lebih; jangan set 0.
- Batasi kuota harian per sesi (300 pesan/hari sudah cukup agresif).
- Untuk pengumuman massal, kirim bertahap dan pantau menu Antrean.
- Hindari mengirim tautan yang sama persis ke ratusan nomor dalam waktu singkat.

## 8. Jendela engine dibanjiri "Bad MAC" / "Failed to decrypt"

Gejala: jendela engine menampilkan bertubi-tubi

```
Failed to decrypt message with any known session...
Session error:Error: Bad MAC Error: Bad MAC
    at Object.verifyMAC (...\libsignal\src\crypto.js:87:15)
```

**Artinya:** ada pesan **MASUK** yang tidak bisa didekripsi. Ini **tidak mempengaruhi
pengiriman keluar** — menu Antrean/Rekap tetap normal.

Dua kemungkinan, urut dari yang paling penting:

1. **Ada DUA engine memakai sesi WA yang sama** (mis. dijalankan dua kali: dari
   Task Scheduler/layanan *dan* dari `start-gateway.bat`). Dua proses berbagi kredensial
   yang sama akan saling merusak kunci dekripsi → banjir Bad MAC terus-menerus.
   Periksa di server:

   ```bat
   tasklist | findstr /i node.exe
   netstat -ano | findstr :3001
   ```

   Harus **tepat satu** proses node dan satu pendengar di port 3001. Kalau ada dua,
   matikan yang kelebihan: `taskkill /F /PID <pid>`.

2. **Kebisingan biasa** dari pesan yang terkirim sebelum sesi selesai ditautkan
   (khas setelah pindai QR / saat ada perangkat tertaut lain menyinkronkan riwayat).

Sejak versi ini, baris kebisingan itu **diredam otomatis** dan diringkas maksimum
sekali per menit:

```
[redam] N baris kebisingan libsignal disembunyikan (pesan masuk gagal didekripsi ...)
```

Mau melihat semuanya kembali (untuk penelusuran)? set `WA_LOG_NOISE=off` di `engine/.env`
lalu jalankan ulang engine.
