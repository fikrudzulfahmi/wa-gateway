<?php
/**
 * Konfigurasi dashboard WA Gateway (PHP 8+ / XAMPP).
 * Nilai default mengikuti XAMPP standar: root tanpa password, MySQL di localhost.
 */
return (static function (): array {
    $cfg = [
    // --- Database (XAMPP) ---
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'wa_gateway',
        'user' => 'root',
        'pass' => '',
    ],

    // --- Engine Node.js (hanya localhost) ---
    'engine_url' => 'http://127.0.0.1:3001',
    // Kosongkan untuk membaca token dari tabel wa_settings (disarankan)
    'engine_token' => '',

    // --- Aplikasi ---
    'app_name'    => 'WA Gateway Lokal',
    'app_author'  => 'by fikrudzulfahmi',
    'timezone'    => 'Asia/Jakarta',
    'upload_dir'  => __DIR__ . '/uploads',
    ];

    // Override lokal untuk deploy ke server lain (berkas ini TIDAK di-commit:
    // sudah masuk .gitignore sebagai dashboard/*.local.php).
    // Contoh isi config.local.php:  <?php return ['db' => ['user' => 'wa_gateway', 'pass' => 'rahasia']];
    $local = __DIR__ . '/config.local.php';
    if (is_file($local)) {
        $override = require $local;
        if (is_array($override)) {
            $cfg = array_replace_recursive($cfg, $override);
        }
    }

    return $cfg;
})();
