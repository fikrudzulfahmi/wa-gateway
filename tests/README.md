# Folder uji

## `mock-hosting/router.php` — aplikasi hosting tiruan

Dipakai untuk menguji jalur **Mode PULL** tanpa perlu aplikasi hosting sungguhan.
Berkas ini meniru aplikasi di hosting: punya tabel antrean (disimpan sebagai `state.json`)
dan 2 endpoint yang dipanggil gateway (`GET .../jobs`, `POST .../jobs/ack`).

### Cara pakai

```bash
# 1. jalankan aplikasi tiruan
cd D:\ApplicationWeb\wa-gateway
C:\xampp\php\php.exe -S 127.0.0.1:8090 tests\mock-hosting\router.php

# 2. daftarkan di dashboard gateway -> menu "Aplikasi Hosting"
#    Nama       : Mock SIM PKL
#    Base URL   : http://127.0.0.1:8090
#    Token      : token-mock-hosting
#    Interval   : 5 detik
#    (atau tambahkan langsung lewat API engine seperti pada langkah uji otomatis)

# 3. klik "Tarik job sekarang" di menu Aplikasi Hosting
# 4. lihat hasilnya: menu Antrean (2 pesan uji masuk) dan berkas
#    tests\mock-hosting\state.json (status berubah pending -> diproses)
```

Yang divalidasi oleh uji ini:

1. Gateway menarik **hanya** pesan berstatus `pending`.
2. Nomor `08xx` dinormalkan menjadi `62xx`.
3. Setelah diambil, gateway mengirim ack `queued` → aplikasi menandai `diproses`
   (anti dobel-kirim saat siklus tarik berikutnya).
4. Siklus tarik berulang **tidak** menghasilkan duplikat (kunci: pasangan `client_id` + `ref`).

Hapus `state.json` untuk mengulang uji dari nol. Uji ini tidak mengirim WhatsApp sungguhan —
pesan hanya sampai di antrean sampai sesi WA dipindai QR-nya.
