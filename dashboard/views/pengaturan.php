<?php
/** Pengaturan engine, kuota per sesi, ganti password, bersihkan data. */
$sessions = db_all('SELECT id, name, label, daily_quota FROM wa_sessions ORDER BY id');
?>
<div class="grid2">
    <div class="panel">
        <div class="panel-head"><h2>Pengaturan pengiriman</h2></div>
        <form method="post" action="index.php?action=settings_save" class="form">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <div class="row2">
                <label>Jeda minimum (detik)
                    <input type="number" name="send_delay_min" min="0" value="<?= (int) setting_get('send_delay_min', 3) ?>">
                </label>
                <label>Jeda maksimum (detik)
                    <input type="number" name="send_delay_max" min="0" value="<?= (int) setting_get('send_delay_max', 8) ?>">
                </label>
            </div>
            <label class="cek">
                <input type="checkbox" name="worker_enabled" value="1" <?= setting_get('worker_enabled', '1') === '1' ? 'checked' : '' ?>>
                Aktifkan pengiriman otomatis (matikan untuk menjeda semua antrean)
            </label>
            <?php foreach ($sessions as $s): ?>
                <label>Kuota harian - <?= e($s['label'] ?: $s['name']) ?>
                    <input type="number" name="quota[<?= (int) $s['id'] ?>]" min="1" value="<?= (int) $s['daily_quota'] ?>">
                </label>
            <?php endforeach; ?>
            <button class="btn primary">Simpan pengaturan</button>
        </form>
        <p class="hint">
            Jeda kirim sebenarnya dibaca engine dari berkas <code>engine/.env</code>
            (<code>SEND_DELAY_MIN</code>/<code>SEND_DELAY_MAX</code>). Setelah mengubah di sini, restart engine agar jeda baru dipakai:
            <code>Ctrl+C</code> lalu <code>npm start</code> di folder engine.
        </p>
    </div>

    <div>
        <div class="panel">
            <div class="panel-head"><h2>Ganti password dashboard</h2></div>
            <form method="post" action="index.php?action=password_change" class="form">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <label>Password lama<input type="password" name="lama" required></label>
                <label>Password baru<input type="password" name="baru" minlength="6" required></label>
                <label>Ulangi password baru<input type="password" name="ulangi" minlength="6" required></label>
                <button class="btn primary">Ubah password</button>
            </form>
        </div>

        <div class="panel">
            <div class="panel-head"><h2>Bersihkan data</h2></div>
            <form method="post" action="index.php?action=data_clear" class="form" onsubmit="return confirm('Yakin membersihkan data ini?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <select name="what">
                    <option value="logs">Log engine</option>
                    <option value="messages">Riwayat pesan (selesai)</option>
                    <option value="inbound">Pesan masuk</option>
                </select>
                <button class="btn danger">Bersihkan</button>
            </form>
        </div>

        <div class="panel">
            <div class="panel-head"><h2>Informasi engine</h2></div>
            <table class="kv">
                <tr><td>URL engine</td><td><code><?= e((string) config('engine_url')) ?></code></td></tr>
                <tr><td>Token engine</td><td><input class="token" readonly value="<?= e((string) setting_get('engine_token', '')) ?>" onclick="this.select()"></td></tr>
                <tr><td>Engine</td><td><?= engine_alive()['ok'] ?? false ? '<span class="badge ok">aktif</span>' : '<span class="badge err">mati</span>' ?></td></tr>
                <tr><td>Folder sesi WA</td><td class="muted"><code>engine/sessions/</code> (cadangkan berkala!)</td></tr>
            </table>
        </div>
    </div>
</div>
