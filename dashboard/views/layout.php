<?php
/**
 * Layout utama dashboard.
 * Variabel: $page (halaman aktif), $active
 */
$u      = auth_user();
$flash  = flash();
$health = $page !== 'login' ? engine_alive() : null;
$nav    = [
    'dashboard'  => ['Dashboard', '📊'],
    'kirim'      => ['Kirim Pesan', '✉️'],
    'rekap'      => ['Rekap Terkirim', '📁'],
    'antrean'    => ['Antrean', '⏳'],
    'masuk'      => ['Pesan Masuk', '📥'],
    'klien'      => ['Aplikasi Hosting', '🖥️'],
    'log'        => ['Log Engine', '📜'],
    'pengaturan' => ['Pengaturan', '⚙️'],
    'panduan'    => ['Panduan Integrasi', '📘'],
];
$judul  = $nav[$page][0] ?? ($page === 'login' ? 'Masuk' : 'Dashboard');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(config('app_name')) ?> - <?= e($nav[$page][0] ?? 'Dashboard') ?></title>
<link rel="stylesheet" href="assets/app.css?v=1">
</head>
<body>
<?php if ($page === 'login'): ?>
    <?php require __DIR__ . '/login.php'; ?>
<?php else: ?>
<div class="shell">
    <aside class="sidebar">
        <div class="brand">
            <span class="logo">WA</span>
            <div>
                <strong>WA Gateway</strong>
                <small>Server Lokal</small>
            </div>
        </div>
        <nav>
            <?php foreach ($nav as $key => [$label, $ico]): ?>
                <a href="index.php?page=<?= e($key) ?>" class="<?= $active === $key ? 'on' : '' ?>">
                    <span class="ico"><?= $ico ?></span><?= e($label) ?>
                    <?php if ($key === 'antrean'): $qq = (int) db_val("SELECT COUNT(*) FROM wa_outbox WHERE status IN ('queued','sending')", [], 0); ?>
                        <em id="nav-queue" class="<?= $qq ? '' : 'hide' ?>"><?= $qq ?></em>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="engine-box">
            <span class="dot <?= $health['ok'] ?? false ? 'ok' : 'err' ?>"></span>
            <div>
                <strong><?= $health['ok'] ?? false ? 'Engine aktif' : 'Engine mati' ?></strong>
                <small><?= $health['ok'] ?? false ? 'uptime ' . floor(($health['uptime_sec'] ?? 0) / 60) . ' mnt' : e(substr((string) ($health['error'] ?? ''), 0, 40)) ?></small>
            </div>
        </div>
        <div class="who">
            <span><?= e($u['nama'] ?? $u['username'] ?? '-') ?></span>
            <form method="post" action="index.php?action=logout">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <button class="link">Keluar</button>
            </form>
        </div>
        <div class="credit"><?= e(config('app_author')) ?></div>
    </aside>

    <main class="main">
        <header class="topbar">
            <h1><?= e($nav[$page][0] ?? 'Dashboard') ?></h1>
            <div class="top-right">
                <span class="clock" id="clock"><?= date('H:i:s') ?></span>
            </div>
        </header>

        <?php if ($flash): ?>
            <div class="alert <?= e($flash['type']) ?>"><?= e($flash['msg']) ?></div>
        <?php endif; ?>

        <?php if ($health && !($health['ok'] ?? false)): ?>
            <div class="alert err">
                Engine Node.js belum jalan. Jalankan di folder <code>engine</code>:
                <code>npm start</code> &mdash; lalu muat ulang halaman ini.
                <br><small><?= e((string) ($health['error'] ?? '')) ?></small>
            </div>
        <?php endif; ?>

        <section class="content">
            <?php require __DIR__ . '/' . $page . '.php'; ?>
        </section>
    </main>
</div>
<script>window.WA_CONFIG = {page: <?= json_encode($page) ?>};</script>
<script src="assets/app.js?v=1"></script>
<?php endif; ?>
</body>
</html>
