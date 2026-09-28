<?php
/** Antrean keluar (outbox) - pesan yang belum selesai dikirim. */
$rows = db_all(
    "SELECT o.*, c.name AS aplikasi, s.label AS sesi
       FROM wa_outbox o
       LEFT JOIN wa_clients c ON c.id = o.client_id
       LEFT JOIN wa_sessions s ON s.id = o.session_id
      ORDER BY FIELD(o.status,'sending','queued','failed','sent','delivered','read','cancelled'), o.id DESC
      LIMIT 100"
);
$counts = db_one(
    "SELECT SUM(status='queued') AS antre, SUM(status='sending') AS kirim, SUM(status='failed') AS gagal, SUM(status IN ('sent','delivered','read','played')) AS sukses FROM wa_outbox"
) ?? [];
?>
<div class="cards">
    <div class="card"><span class="k">Menunggu</span><span class="v warn"><?= (int) ($counts['antre'] ?? 0) ?></span></div>
    <div class="card"><span class="k">Sedang dikirim</span><span class="v info"><?= (int) ($counts['kirim'] ?? 0) ?></span></div>
    <div class="card"><span class="k">Sukses</span><span class="v ok"><?= (int) ($counts['sukses'] ?? 0) ?></span></div>
    <div class="card"><span class="k">Gagal</span><span class="v err"><?= (int) ($counts['gagal'] ?? 0) ?></span></div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2>100 pesan terakhir di antrean</h2>
        <span class="muted" id="queue-live">memuat...</span>
    </div>
    <table class="tbl">
        <thead><tr><th>#</th><th>Dibuat</th><th>Tujuan</th><th>Isi</th><th>Sumber</th><th>Status</th><th>Coba</th><th>Catatan</th><th></th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="9" class="muted center">Antrean kosong.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="muted"><?= (int) $r['id'] ?></td>
                <td class="nowrap muted"><?= fmt_dt($r['created_at']) ?></td>
                <td class="nowrap"><b><?= fmt_phone($r['to_number']) ?></b></td>
                <td class="body-cell"><?= e(mb_strimwidth((string) $r['body'], 0, 60, '...')) ?></td>
                <td class="muted"><?= e($r['aplikasi'] ?: ucfirst((string) $r['source'])) ?></td>
                <td><?= badge((string) $r['status']) ?></td>
                <td class="muted"><?= (int) $r['retry_count'] ?>/<?= (int) $r['max_retry'] ?></td>
                <td class="muted small"><?= e((string) ($r['last_error'] ?? '')) ?></td>
                <td class="nowrap">
                    <?php if ($r['status'] === 'queued'): ?>
                        <form method="post" action="index.php?action=outbox_cancel" style="display:inline">
                            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button class="btn tiny danger">Batal</button>
                        </form>
                    <?php endif; ?>
                    <?php if (in_array($r['status'], ['failed', 'cancelled'], true)): ?>
                        <form method="post" action="index.php?action=outbox_retry" style="display:inline">
                            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button class="btn tiny">Kirim ulang</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script>setInterval(() => fetch('index.php?page=api&action=status').then(r => r.json()).then(d => {
    const el = document.getElementById('queue-live');
    if (el && d.ok) el.textContent = 'antrean aktif: ' + d.queue + ' pesan';
}), 5000);</script>
