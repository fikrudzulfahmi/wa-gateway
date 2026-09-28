<?php
/** Pesan masuk (balasan dari penerima). */
$limit = 50;
$rows  = db_all(
    "SELECT i.*, s.label AS sesi FROM wa_inbound i LEFT JOIN wa_sessions s ON s.id = i.session_id ORDER BY i.id DESC LIMIT {$limit}"
);
?>
<div class="panel">
    <div class="panel-head"><h2><?= count($rows) ?> pesan masuk terakhir</h2></div>
    <p class="muted">Berguna untuk menangkap balasan seperti <code>STOP</code>, <code>YA</code>, atau konfirmasi dari wali murid.</p>
    <table class="tbl">
        <thead><tr><th>Waktu</th><th>Dari</th><th>Nama</th><th>Isi</th><th>Sesi</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="5" class="muted center">Belum ada pesan masuk.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="nowrap muted"><?= fmt_dt($r['received_at']) ?></td>
                <td class="nowrap"><b><?= fmt_phone($r['from_number']) ?></b></td>
                <td class="muted"><?= e($r['push_name'] ?? '-') ?></td>
                <td><?= e(mb_strimwidth((string) $r['body'], 0, 120, '...')) ?></td>
                <td class="muted"><?= e($r['sesi'] ?? '-') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
