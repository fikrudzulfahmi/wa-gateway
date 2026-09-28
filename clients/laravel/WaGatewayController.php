<?php
/**
 * ==== SISI HOSTING (Laravel) ====
 * app/Http/Controllers/WaGatewayController.php
 *
 * routes/api.php:
 *   Route::middleware('throttle:120,1')->group(function () {
 *       Route::get('wa-gateway/jobs',     [WaGatewayController::class, 'jobs']);
 *       Route::post('wa-gateway/jobs/ack',[WaGatewayController::class, 'ack']);
 *   });
 *
 * config/services.php:
 *   'wa_gateway' => ['token' => env('WA_GATEWAY_TOKEN')],
 *
 * .env (hosting):
 *   WA_GATEWAY_TOKEN=isi_token_dari_dashboard_gateway
 */
namespace App\Http\Controllers;

use App\Models\WaOutbox;
use Illuminate\Http\Request;

class WaGatewayController extends Controller
{
    private function authorize(Request $r): void
    {
        abort_unless(
            hash_equals((string) config('services.wa_gateway.token'), (string) $r->header('X-Gateway-Token')),
            401,
            'Token gateway tidak valid'
        );
    }

    /** Daftar pesan menunggu -> ditarik gateway lokal (mode PULL). */
    public function jobs(Request $r)
    {
        $this->authorize($r);
        $limit = max(1, min(100, (int) $r->query('limit', 20)));

        $jobs = WaOutbox::where('status', 'pending')
            ->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (WaOutbox $o) => [
                'id'           => 'OUTBOX-' . $o->id,
                'to'           => $o->nomor,
                'type'         => $o->tipe,
                'body'         => $o->isi,
                'media_url'    => $o->media_url,
                'filename'     => $o->filename,
                'scheduled_at' => optional($o->scheduled_at)->format('Y-m-d H:i:s'),
            ]);

        return response()->json(['data' => $jobs]);
    }

    /** Laporan balik status dari gateway (idempoten). */
    public function ack(Request $r)
    {
        $this->authorize($r);

        $ref = (int) preg_replace('/\D/', '', (string) $r->input('ref'));
        $map = [
            'queued'    => 'diproses',
            'sent'      => 'terkirim',
            'delivered' => 'sampai',
            'read'      => 'dibaca',
            'failed'    => 'gagal',
        ];
        $status = $map[$r->input('status')] ?? null;
        abort_if($ref <= 0 || $status === null, 422, 'ref/status tidak dikenal');

        WaOutbox::whereKey($ref)->update([
            'status'        => $status,
            'wa_message_id' => $r->input('wa_message_id'),
            'error'         => $r->input('error'),
        ]);

        return ['ok' => true];
    }
}
