<?php
/** Rekap pesan terkirim: filter, tabel, export, kirim ulang. */
$f       = recap_filters($_GET);
$p       = max(1, (int) ($_GET['p'] ?? 1));
$limit   = 15;
$rows    = db_all(
    "SELECT m.*, c.name AS aplikasi, s.label AS sesi
       FROM wa_messages m
       LEFT JOIN wa_clients c ON c.id = m.client_id
       LEFT JOIN wa_sessions s ON s.id = m.session_id
      {$f['where']} ORDER BY m.id DESC LIMIT {$limit} OFFSET " . (($p - 1) * $limit),
    $f['params']
);
$total   = (int) db_val("SELECT COUNT(*) FROM wa_messages m {$f['where']}", $f['params'], 0);
$pages   = max(1, (int) ceil($total / $limit));
$clients = db_all('SELECT id, name FROM wa_clients ORDER BY name');
$qs      = $_GET;
unset($qs['p']);
$exportUrl = 'index.php?page=export&' . http_build_query($_GET);
$statuses = ['' => 'Semua status', 'sent' => 'Terkirim', 'delivered' => 'Sampai', 'read' => 'Dibaca', 'failed' => 'Gagal', 'queued,sending' => 'Antrean / sedang kirim'];
?>
<form class="filterbar" method="get" action="index.php">
    <input type="hidden" name="page" value="rekap">
    <label>Dari<input type="date" name="dari" value="<?= e($_GET['dari'] ?? date('Y-m-d')) ?>"></label>
    <label>Sampai<input type="date" name="sampai" value="<?= e($_GET['sampai'] ?? date('Y-m-d')) ?>"></label>
    <label>Status
        <select name="status">
            <?php foreach ($statuses as $k => $v): ?>
                <option value="<?= e($k) ?>" <?= ($_GET['status'] ?? '') === $k ? 'selected' : '' ?>><?= e($v) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Aplikasi
        <select name="aplikasi">
            <option value="">Semua</option>
            <?php foreach ($clients as $c): ?>
                <option value="<?= (int) $c['id'] ?>" <?= (string) ($_GET['aplikasi'] ?? '') === (string) $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Nomor<input type="text" name="nomor" value="<?= e($_GET['nomor'] ?? '') ?>" placeholder="08xx"></label>
    <label>Kata kunci<input type="text" name="cari" value="<?= e($_GET['cari'] ?? '') ?>" placeholder="isi pesan"></label>
    <button class="btn primary">Terapkan</button>
    <a class="btn" href="index.php?page=rekap">Reset</a>
    <a class="btn" href="<?= e($exportUrl) ?>">⬇ Export CSV (Excel)</a>
</form>

<div class="panel">
    <div class="panel-head">
        <h2><?= number_format($total, 0, ',', '.') ?> pesan</h2>
        <span class="muted">Halaman <?= $p ?> / <?= $pages ?></span>
    </div>
    <table class="tbl" id="tbl-rekap">
        <thead>
        <tr>
            <th>Waktu</th><th>Tujuan</th><th>Isi</th><th>Aplikasi</th><th>Status</th><th>Sampai</th><th>Dibaca</th><th></th>
        </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="8" class="muted center">Belum ada pesan pada rentang filter ini.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="nowrap"><?= fmt_dt($r['created_at']) ?></td>
                <td class="nowrap"><b><?= fmt_phone($r['to_number']) ?></b></td>
                <td class="body-cell" title="<?= e($r['body']) ?>">
                    <?= e(mb_strimwidth((string) $r['body'], 0, 70, '...')) ?>
                    <?php if ($r['type'] !== 'text'): ?><span class="badge off"><?= e($r['type']) ?></span><?php endif; ?>
                    <?php if ($r['error']): ?><div class="err-text small"><?= e($r['error']) ?></div><?php endif; ?>
                </td>
                <td class="muted"><?= e($r['aplikasi'] ?? 'Manual') ?></td>
                <td><?= badge((string) $r['status']) ?></td>
                <td class="muted nowrap"><?= fmt_dt($r['delivered_at'], '-') ?></td>
                <td class="muted nowrap"><?= fmt_dt($r['read_at'], '-') ?></td>
                <td>
                    <?php if (in_array($r['status'], ['failed'], true)): ?>
                        <form method="post" action="index.php?action=outbox_retry">
                            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="id" value="<?= (int) $r['outbox_id'] ?>">
                            <button class="btn tiny">Kirim ulang</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($pages > 1): ?>
        <div class="pager">
            <?php for ($i = max(1, $p - 3); $i <= min($pages, $p + 3); $i++): ?>
                <a class="btn tiny <?= $i === $p ? 'primary' : '' ?>" href="index.php?<?= e(http_build_query($qs + ['p' => $i])) ?>"><?= $i ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>
</div>
