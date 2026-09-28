<?php
/**
 * ==== SISI HOSTING (Laravel): model + layanan kirim ====
 * app/Models/WaOutbox.php  &  app/Services/WaGatewayService.php
 */
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaOutbox extends Model
{
    protected $table = 'wa_outbox';
    protected $guarded = [];

    protected $casts = ['scheduled_at' => 'datetime'];
}

// ---------------------------------------------------------------------
namespace App\Services;

use App\Models\WaOutbox;

final class WaGatewayService
{
    /** Kirim satu pesan. */
    public static function kirim(string $nomor, string $isi, array $opsi = []): WaOutbox
    {
        return WaOutbox::create([
            'nomor'        => self::normalisasi($nomor),
            'tipe'         => $opsi['tipe'] ?? 'text',
            'isi'          => $isi,
            'media_url'    => $opsi['media_url'] ?? null,
            'filename'     => $opsi['filename'] ?? null,
            'kategori'     => $opsi['kategori'] ?? null,
            'ref_berkas'   => $opsi['ref_berkas'] ?? null,
            'scheduled_at' => $opsi['scheduled_at'] ?? null,
            'status'       => 'pending',
        ]);
    }

    /** Kirim ke banyak nomor (mis. satu kelas / semua wali murid). */
    public static function kirimBanyak(iterable $nomorList, string $isi, array $opsi = []): int
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

    public static function normalisasi(string $raw): string
    {
        $s = ltrim((string) preg_replace('/[^\d+]/', '', $raw), '+');
        if ($s === '') {
            return '';
        }
        if (str_starts_with($s, '0')) {
            return '62' . substr($s, 1);
        }
        if (str_starts_with($s, '8')) {
            return '62' . $s;
        }
        return str_starts_with($s, '62') ? $s : '62' . ltrim($s, '0');
    }
}

/*
| Contoh pemakaian di controller aplikasi:
|
|   use App\Services\WaGatewayService;
|
|   // presensi masuk
|   WaGatewayService::kirim($siswa->hp_wali, "Ananda {$siswa->nama} hadir pukul 07:01.");
|
|   // tagihan + lampiran
|   WaGatewayService::kirim($siswa->hp_wali, 'Kwitansi SPP bulan ini', [
|       'tipe' => 'document',
|       'media_url' => url("files/kwitansi-{$tagihan->id}.pdf"),
|       'filename'  => "kwitansi-{$tagihan->id}.pdf",
|       'kategori'  => 'tagihan',
|   ]);
|
|   // terjadwal: pengumuman besok pagi 07:00
|   WaGatewayService::kirimBanyak($kelas->waliHp(), 'Besok libur.', ['scheduled_at' => now()->addDay()->setTime(7, 0)]);
*/
