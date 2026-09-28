<?php
/** Log kejadian dari engine (koneksi, kirim, pull, gagal). */
$rows = db_all('SELECT * FROM wa_logs ORDER BY id DESC LIMIT 100');
?>
<div class="panel">
    <div class="panel-head"><h2>100 log terakhir</h2></div>
    <table class="tbl">
        <thead><tr><th>Waktu</th><th>Level</th><th>Kejadian</th><th>Pesan</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="4" class="muted center">Belum ada log.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="nowrap muted"><?= fmt_dt($r['created_at']) ?></td>
                <td><?= badge(match ($r['level']) { 'error' => 'failed', 'warning' => 'retrying', default => 'sent' }) ?></td>
                <td><code><?= e($r['event']) ?></code></td>
                <td class="muted small"><?= e((string) $r['message']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
