<?php
/** Panduan integrasi aplikasi hosting dengan gateway (mode PULL). */
$clients = db_all('SELECT name, base_url, jobs_path, ack_path, token FROM wa_clients ORDER BY id');
$c0 = $clients[0] ?? ['name' => 'Aplikasi Anda', 'base_url' => 'https://app.ingintau.my.id', 'jobs_path' => '/api/wa-gateway/jobs', 'ack_path' => '/api/wa-gateway/jobs/ack', 'token' => '<token aplikasi>'];
?>
<div class="panel">
    <div class="panel-head"><h2>Cara kerja (mode PULL, tanpa IP publik)</h2></div>
    <ol class="steps">
        <li>Aplikasi di hosting menyimpan pesan yang mau dikirim ke tabel antrean (<code>wa_outbox</code>) &mdash; biasanya dipicu oleh event: presensi, tagihan, pengumuman.</li>
        <li>Gateway lokal setiap <b><?= (int) setting_get('pull_interval', 10) ?> detik</b> menembak <code>GET <?= e($c0['base_url']) ?><?= e($c0['jobs_path']) ?></code> ke hosting (koneksi keluar dari lokal, jadi tidak butuh port forward / IP publik).</li>
        <li>Gateway mengirim pesan lewat WhatsApp, lalu melaporkan hasilnya dengan <code>POST</code> ke <code><?= e($c0['ack_path']) ?></code> saat status berubah menjadi <b>sent</b>, <b>delivered</b>, <b>read</b>, atau <b>failed</b>.</li>
    </ol>
</div>

<div class="panel">
    <div class="panel-head"><h2>Kontrak API di sisi hosting</h2></div>
    <h3>1) Daftar pesan menunggu</h3>
    <pre class="code">GET <?= e($c0['jobs_path']) ?>?limit=20
Header: X-Gateway-Token: <?= e($c0['token']) ?>

{
  "data": [
    { "id": "PRESENSI-2026-0001", "to": "081234567890", "type": "text", "body": "Ananda hadir..." },
    { "id": "TAGIHAN-881",       "to": "6281234567891", "type": "document",
      "body": "Kwitansi SPP", "media_url": "https://app.ingintau.my.id/files/spp-881.pdf",
      "filename": "spp-881.pdf", "scheduled_at": "2026-10-01 07:00:00" }
  ]
}</pre>
    <p class="muted">Kirim <b>hanya</b> pesan berstatus baru/pending. Setelah gateway mengambilnya, gateway langsung mengirim laporan <code>status: "queued"</code> supaya aplikasi menandainya "sedang diproses" dan tidak terkirim dua kali.</p>

    <h3>2) Laporan balik status</h3>
    <pre class="code">POST <?= e($c0['ack_path']) ?>
Header: X-Gateway-Token: <?= e($c0['token']) ?>

{
  "ref": "PRESENSI-2026-0001",
  "status": "queued|sent|delivered|read|failed",
  "wa_message_id": "3EB0ABCD...",
  "error": null,
  "sent_at": "2026-09-28T15:02:11.000Z"
}</pre>
    <p class="muted">Endpoint ini harus <b>idempoten</b> (dipanggil berkali-kali dengan status naik level: queued &rarr; sent &rarr; delivered &rarr; read).</p>
</div>

<div class="panel">
    <div class="panel-head"><h2>Contoh kode sisi hosting (PHP native)</h2></div>
    <pre class="code">&lt;?php
// ==== api/wa-gateway/jobs.php  (daftar pesan menunggu) ====
$TOKEN = '<?= e($c0['token']) ?>';
if (($_SERVER['HTTP_X_GATEWAY_TOKEN'] ?? '') !== $TOKEN) { http_response_code(401); exit; }

$limit = min(50, max(1, (int) ($_GET['limit'] ?? 20)));
$rows  = $pdo-&gt;query("SELECT id, nomor, isi FROM wa_outbox
                      WHERE status='pending' ORDER BY id LIMIT $limit")-&gt;fetchAll();

header('Content-Type: application/json');
echo json_encode(['data' =&gt; array_map(fn($r) =&gt; [
    'id'   =&gt; 'OUTBOX-' . $r['id'],
    'to'   =&gt; $r['nomor'],
    'type' =&gt; 'text',
    'body' =&gt; $r['isi'],
], $rows)]);</pre>
    <pre class="code">&lt;?php
// ==== api/wa-gateway/jobs/ack.php  (terima laporan status) ====
$TOKEN = '<?= e($c0['token']) ?>';
if (($_SERVER['HTTP_X_GATEWAY_TOKEN'] ?? '') !== $TOKEN) { http_response_code(401); exit; }

$in  = json_decode(file_get_contents('php://input'), true);
$ref = (int) preg_replace('/\D/', '', $in['ref'] ?? '0');

$map = ['queued' =&gt; 'diproses', 'sent' =&gt; 'terkirim', 'delivered' =&gt; 'sampai',
        'read' =&gt; 'dibaca', 'failed' =&gt; 'gagal'];

$pdo-&gt;prepare("UPDATE wa_outbox SET status=?, wa_message_id=?, error=?, updated_at=NOW() WHERE id=?")
    -&gt;execute([$map[$in['status']] ?? $in['status'], $in['wa_message_id'], $in['error'], $ref]);

echo json_encode(['ok' =&gt; true]);</pre>
</div>

<div class="panel">
    <div class="panel-head"><h2>Contoh untuk Laravel</h2></div>
    <pre class="code">// routes/api.php
Route::middleware('throttle:120,1')-&gt;group(function () {
    Route::get('wa-gateway/jobs', [WaGatewayController::class, 'jobs']);
    Route::post('wa-gateway/jobs/ack', [WaGatewayController::class, 'ack']);
});

// app/Http/Controllers/WaGatewayController.php
public function jobs(Request $r)
{
    abort_unless($r-&gt;header('X-Gateway-Token') === config('services.wa_gateway.token'), 401);

    $jobs = WaOutbox::where('status', 'pending')
        -&gt;orderBy('id')-&gt;limit($r-&gt;integer('limit', 20))-&gt;get()
        -&gt;map(fn($o) =&gt; [
            'id'    =&gt; 'OUTBOX-' . $o-&gt;id,
            'to'    =&gt; $o-&gt;nomor,
            'type'  =&gt; $o-&gt;tipe,
            'body'  =&gt; $o-&gt;isi,
            'media_url' =&gt; $o-&gt;media_url,
            'filename'  =&gt; $o-&gt;filename,
        ]);

    return response()-&gt;json(['data' =&gt; $jobs]);
}

public function ack(Request $r)
{
    abort_unless($r-&gt;header('X-Gateway-Token') === config('services.wa_gateway.token'), 401);

    $id = (int) preg_replace('/\D/', '', (string) $r-&gt;input('ref'));
    WaOutbox::whereKey($id)-&gt;update([
        'status'        =&gt; $r-&gt;input('status'),
        'wa_message_id' =&gt; $r-&gt;input('wa_message_id'),
        'error'         =&gt; $r-&gt;input('error'),
    ]);

    return ['ok' =&gt; true];
}

// config/services.php
'wa_gateway' =&gt; ['token' =&gt; env('WA_GATEWAY_TOKEN')],</pre>
    <p class="hint">Kirim pesan cukup dengan menyisipkan baris ke tabel <code>wa_outbox</code> aplikasi: <code>WaOutbox::create(['nomor' =&gt; '0812...', 'tipe' =&gt; 'text', 'isi' =&gt; 'Pesan...', 'status' =&gt; 'pending']);</code></p>
</div>
