<?php
/** Halaman Dashboard: status koneksi WA, QR, dan ringkasan hari ini. */
$sessions = db_all(
    "SELECT id, name, label, phone, status, qr_updated_at, last_error, daily_quota,
            IF(quota_date = CURDATE(), sent_today, 0) AS sent_today, connected_at
       FROM wa_sessions ORDER BY id"
);
$today = db_one(
    "SELECT COUNT(*) AS total,
            SUM(status IN ('sent','delivered','read','played')) AS terkirim,
            SUM(status IN ('delivered','read','played')) AS sampai,
            SUM(status IN ('read','played')) AS dibaca,
            SUM(status = 'failed') AS gagal,
            SUM(status IN ('queued','sending')) AS dalam_proses
       FROM wa_messages WHERE direction='out' AND DATE(created_at) = CURDATE()"
) ?? [];
$queue   = (int) db_val("SELECT COUNT(*) FROM wa_outbox WHERE status IN ('queued','sending')", [], 0);
$clients = db_all('SELECT name, base_url, is_active, last_pull_at, last_pull_status, last_error FROM wa_clients ORDER BY id');
?>

<div class="cards">
    <div class="card"><span class="k">Total pesan hari ini</span><span class="v" id="st-total"><?= (int) ($today['total'] ?? 0) ?></span></div>
    <div class="card"><span class="k">Terkirim</span><span class="v ok" id="st-terkirim"><?= (int) ($today['terkirim'] ?? 0) ?></span></div>
    <div class="card"><span class="k">Sampai (centang 2)</span><span class="v info" id="st-sampai"><?= (int) ($today['sampai'] ?? 0) ?></span></div>
    <div class="card"><span class="k">Dibaca</span><span class="v ok" id="st-dibaca"><?= (int) ($today['dibaca'] ?? 0) ?></span></div>
    <div class="card"><span class="k">Gagal</span><span class="v err" id="st-gagal"><?= (int) ($today['gagal'] ?? 0) ?></span></div>
    <div class="card"><span class="k">Dalam antrean</span><span class="v warn" id="st-antrean"><?= $queue ?></span></div>
</div>

<div class="grid2">
    <div class="panel">
        <div class="panel-head">
            <h2>Status Koneksi WhatsApp</h2>
            <button class="btn small" id="btn-refresh">Muat ulang</button>
        </div>

        <?php foreach ($sessions as $s): ?>
            <div class="session-row" data-session="<?= e($s['name']) ?>">
                <div class="session-info">
                    <div class="session-title">
                        <strong><?= e($s['label'] ?: $s['name']) ?></strong>
                        <span class="badge-slot" data-status="<?= e($s['name']) ?>"><?= badge($s['status']) ?></span>
                    </div>
                    <div class="muted">
                        Nomor: <b class="s-phone"><?= $s['phone'] ? fmt_phone($s['phone']) : 'belum tersambung' ?></b>
                        &middot; Kuota hari ini:
                        <b class="s-quota"><?= (int) $s['sent_today'] ?>/<?= (int) $s['daily_quota'] ?></b>
                        &middot; tersambung sejak <span class="s-since"><?= fmt_ago($s['connected_at']) ?></span>
                    </div>
                    <?php if ($s['last_error']): ?>
                        <div class="muted err-text">Terakhir: <?= e($s['last_error']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="session-actions">
                    <form method="post" action="index.php?action=session_restart">
                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="name" value="<?= e($s['name']) ?>">
                        <button class="btn small">Sambungkan ulang</button>
                    </form>
                    <form method="post" action="index.php?action=session_logout" onsubmit="return confirm('Cabut sesi WA ini? Nomor harus scan QR ulang.')">
                        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                        <input type="hidden" name="name" value="<?= e($s['name']) ?>">
                        <button class="btn small danger">Logout WA</button>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>

        <form class="inline-form" method="post" action="index.php?action=session_create">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="text" name="name" placeholder="nama sesi (mis. kedua)" pattern="[a-z0-9_-]+" required>
            <input type="text" name="label" placeholder="keterangan, mis. Nomor Notifikasi">
            <input type="number" name="quota" value="300" min="1" title="kuota harian" style="max-width:100px">
            <button class="btn small">Tambah sesi WA</button>
        </form>
    </div>

    <div class="panel qr-panel">
        <div class="panel-head">
            <h2>Scan QR</h2>
            <span class="muted" id="qr-time">-</span>
        </div>
        <div class="qr-box" id="qr-box">
            <div class="qr-empty">Memuat QR...</div>
        </div>
        <p class="hint">
            Buka <b>WhatsApp &rarr; Perangkat tertaut &rarr; Tautkan perangkat</b>, lalu scan QR di atas.
            QR berganti otomatis setiap beberapa detik selama belum tersambung.
        </p>
    </div>
</div>

<div class="panel">
    <div class="panel-head"><h2>Aplikasi hosting yang terhubung (mode PULL)</h2></div>
    <?php if (!$clients): ?>
        <p class="muted">Belum ada aplikasi. Tambahkan di menu <a href="index.php?page=klien">Aplikasi Hosting</a>.</p>
    <?php else: ?>
    <table class="tbl">
        <thead><tr><th>Aplikasi</th><th>Base URL</th><th>Status</th><th>Terakhir tarik</th><th>Hasil</th></tr></thead>
        <tbody>
        <?php foreach ($clients as $c): ?>
            <tr>
                <td><b><?= e($c['name']) ?></b></td>
                <td class="muted"><?= e($c['base_url']) ?></td>
                <td><?= $c['is_active'] ? '<span class="badge ok">aktif</span>' : '<span class="badge off">nonaktif</span>' ?></td>
                <td class="muted"><?= fmt_ago($c['last_pull_at']) ?></td>
                <td class="muted"><?= e($c['last_error'] ? 'error: ' . $c['last_error'] : ($c['last_pull_status'] ?? '-')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>
