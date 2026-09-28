<div class="login-wrap">
    <form class="login-card" method="post" action="index.php?action=login">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <div class="brand center">
            <span class="logo">WA</span>
            <div>
                <strong><?= e(config('app_name')) ?></strong>
                <small>Gateway WhatsApp untuk aplikasi hosting</small>
            </div>
        </div>
        <label>Username
            <input type="text" name="username" value="admin" autocomplete="username" required autofocus>
        </label>
        <label>Password
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button class="btn primary block">Masuk</button>
        <p class="hint">Login bawaan: <code>admin</code> / <code>admin123</code> &mdash; segera ganti di menu Pengaturan.</p>
        <div class="credit center"><?= e(config('app_author')) ?></div>
    </form>
</div>
