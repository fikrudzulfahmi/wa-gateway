<?php
/** Kirim pesan manual + impor massal CSV. */
$sessions = db_all('SELECT id, name, label, status, phone FROM wa_sessions ORDER BY id');
?>
<div class="grid2">
    <div class="panel">
        <div class="panel-head"><h2>Kirim pesan</h2></div>
        <form method="post" action="index.php?action=send" enctype="multipart/form-data" class="form">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <label>Sesi WA
                <select name="session">
                    <?php foreach ($sessions as $s): ?>
                        <option value="<?= e($s['name']) ?>"><?= e($s['label'] ?: $s['name']) ?><?= $s['phone'] ? ' - ' . fmt_phone($s['phone']) : '' ?> (<?= e($s['status']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>Nomor tujuan
                <input type="text" name="to" placeholder="0812xxxxxxx / 62812xxxxxxx" required>
            </label>
            <label>Tipe
                <select name="type" id="f-type">
                    <option value="text">Teks</option>
                    <option value="image">Gambar (dengan caption)</option>
                    <option value="document">Dokumen (PDF/Excel/Word)</option>
                </select>
            </label>
            <label>Isi pesan / caption
                <textarea name="body" rows="5" placeholder="Tulis pesan di sini...&#10;Bisa multi-baris."></textarea>
            </label>
            <label class="media-field hide">Lampiran berkas (gambar/dokumen)
                <input type="file" name="media" id="f-media">
            </label>
            <label class="cek">
                <input type="checkbox" name="wait" value="1" checked>
                Tunggu sampai status terkirim/gagal (maks 20 detik)
            </label>
            <button class="btn primary">Kirim sekarang</button>
        </form>
    </div>

    <div>
        <div class="panel">
            <div class="panel-head"><h2>Kirim massal dari CSV</h2></div>
            <p class="muted">
                Berkas CSV 2 kolom tanpa header atau dengan header: <code>nomor,pesan</code>.
                Bisa juga diekspor dari Excel (Simpan sebagai &rarr; CSV UTF-8).
            </p>
            <form method="post" action="index.php?action=send_bulk" enctype="multipart/form-data" class="form">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="file" name="file" accept=".csv,text/csv" required>
                <button class="btn">Impor &amp; masukkan antrean</button>
            </form>
            <p class="hint">Pesan dikirim satu per satu dengan jeda acak sesuai Pengaturan, agar aman dari pemblokiran.</p>
        </div>

        <div class="panel">
            <div class="panel-head"><h2>Catatan penting</h2></div>
            <ul class="notes">
                <li>Status <b>terkirim</b> = WhatsApp server sudah menerima pesan.</li>
                <li>Status <b>sampai</b> (centang 2) = pesan masuk ke perangkat penerima.</li>
                <li>Status <b>dibaca</b> bergantung pada setelan privasi penerima &mdash; tidak selalu muncul.</li>
                <li>Nomor yang tidak terdaftar WhatsApp akan berstatus <b>gagal</b> setelah 3 kali percobaan.</li>
            </ul>
        </div>
    </div>
</div>
