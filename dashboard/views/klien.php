<?php
/** Kelola aplikasi hosting yang menitipkan pesan (mode PULL). */
$edit = (int) ($_GET['edit'] ?? 0);
$row  = $edit ? db_one('SELECT * FROM wa_clients WHERE id = ?', [$edit]) : null;
$rows = db_all('SELECT c.*, s.label AS sesi FROM wa_clients c LEFT JOIN wa_sessions s ON s.id = c.session_id ORDER BY c.id');
$sessions = db_all('SELECT id, name, label, phone FROM wa_sessions ORDER BY id');
?>
<div class="grid2">
    <div class="panel">
        <div class="panel-head"><h2><?= $row ? 'Ubah aplikasi' : 'Tambah aplikasi hosting' ?></h2></div>
        <form method="post" action="index.php?action=client_save" class="form">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int) ($row['id'] ?? 0) ?>">
            <label>Nama aplikasi
                <input type="text" name="name" value="<?= e($row['name'] ?? '') ?>" placeholder="mis. SIM PKL" required>
            </label>
            <label>Base URL (tanpa garis miring di akhir)
                <input type="text" name="base_url" value="<?= e($row['base_url'] ?? '') ?>" placeholder="https://pkl.ingintau.my.id" required>
            </label>
            <div class="row2">
                <label>Path daftar pesan
                    <input type="text" name="jobs_path" value="<?= e($row['jobs_path'] ?? '/api/wa-gateway/jobs') ?>">
                </label>
                <label>Path laporan balik
                    <input type="text" name="ack_path" value="<?= e($row['ack_path'] ?? '/api/wa-gateway/jobs/ack') ?>">
                </label>
            </div>
            <div class="row2">
                <label>Sesi WA
                    <select name="session_id">
                        <option value="">(otomatis: sesi pertama)</option>
                        <?php foreach ($sessions as $s): ?>
                            <option value="<?= (int) $s['id'] ?>" <?= (int) ($row['session_id'] ?? 0) === (int) $s['id'] ? 'selected' : '' ?>>
                                <?= e($s['label'] ?: $s['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>Interval tarik (detik)
                    <input type="number" name="poll_interval" min="5" value="<?= (int) ($row['poll_interval'] ?? 10) ?>">
                </label>
            </div>
            <div class="row2">
                <label>Jumlah per tarik
                    <input type="number" name="batch_size" min="1" max="100" value="<?= (int) ($row['batch_size'] ?? 20) ?>">
                </label>
                <label>Token (kosongkan = buat otomatis)
                    <input type="text" name="token" value="<?= e($row['token'] ?? '') ?>">
                </label>
            </div>
            <label class="cek"><input type="checkbox" name="is_active" value="1" <?= (!$row || $row['is_active']) ? 'checked' : '' ?>> Aktif</label>
            <button class="btn primary"><?= $row ? 'Simpan perubahan' : 'Tambah aplikasi' ?></button>
            <?php if ($row): ?><a class="btn" href="index.php?page=klien">Batal</a><?php endif; ?>
        </form>
        <p class="hint">Aplikasi wajib menyediakan 2 endpoint sesuai menu <a href="index.php?page=panduan">Panduan Integrasi</a>.</p>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2>Daftar aplikasi</h2>
            <form method="post" action="index.php?action=client_pull">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <button class="btn small">Tarik job sekarang</button>
            </form>
        </div>
        <table class="tbl">
            <thead><tr><th>Aplikasi</th><th>URL</th><th>Sesi</th><th>Interval</th><th>Terakhir</th><th>Token</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="7" class="muted center">Belum ada aplikasi terdaftar.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $c): ?>
                <tr>
                    <td><b><?= e($c['name']) ?></b><br><span class="badge <?= $c['is_active'] ? 'ok' : 'off' ?>"><?= $c['is_active'] ? 'aktif' : 'nonaktif' ?></span></td>
                    <td class="muted small"><?= e($c['base_url']) ?></td>
                    <td class="muted small"><?= e($c['sesi'] ?? 'otomatis') ?></td>
                    <td class="muted small"><?= (int) $c['poll_interval'] ?>s / <?= (int) $c['batch_size'] ?> pesan</td>
                    <td class="muted small">
                        <?= fmt_ago($c['last_pull_at']) ?><br>
                        <?= e($c['last_error'] ? 'error: ' . $c['last_error'] : ($c['last_pull_status'] ?? '-')) ?>
                        <br><span class="muted">total ditarik: <?= (int) $c['pulled_count'] ?></span>
                    </td>
                    <td><input class="token" readonly value="<?= e($c['token']) ?>" onclick="this.select()"></td>
                    <td class="nowrap">
                        <a class="btn tiny" href="index.php?page=klien&edit=<?= (int) $c['id'] ?>">Ubah</a>
                        <form method="post" action="index.php?action=client_delete" style="display:inline" onsubmit="return confirm('Hapus aplikasi ini?')">
                            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button class="btn tiny danger">Hapus</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
