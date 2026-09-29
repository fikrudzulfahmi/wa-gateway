<?php
declare(strict_types=1);
/**
 * WA Gateway Lokal - Dashboard (XAMPP / PHP 8+)
 * Front controller tunggal: index.php?page=xxx
 */
session_start();
require __DIR__ . '/lib/helpers.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';

date_default_timezone_set((string) config('timezone', 'Asia/Jakarta'));
auth_ensure_admin();

$page   = preg_replace('/[^a-z_]/', '', (string) ($_GET['page'] ?? 'dashboard'));
$action = preg_replace('/[^a-z_]/', '', (string) ($_GET['action'] ?? ''));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// =====================================================================
// 1. AJAX / JSON API (dipakai halaman untuk update realtime)
// =====================================================================
if ($page === 'api') {
    header('Content-Type: application/json; charset=utf-8');
    if (!auth_user()) {
        http_response_code(401);
        exit(json_encode(['ok' => false, 'error' => 'belum login']));
    }

    $out = ['ok' => true];
    switch ($action) {
        case 'status':
            $sessions = engine('GET', '/api/sessions');
            $today    = db_one(
                "SELECT COUNT(*) AS total,
                        SUM(status IN ('sent','delivered','read','played')) AS terkirim,
                        SUM(status IN ('delivered','read','played')) AS sampai,
                        SUM(status IN ('read','played')) AS dibaca,
                        SUM(status = 'failed') AS gagal,
                        SUM(status IN ('queued','sending')) AS dalam_proses
                   FROM wa_messages WHERE direction='out' AND DATE(created_at) = CURDATE()"
            );
            $out = [
                'ok'       => true,
                'health'   => engine_alive(),
                'sessions' => $sessions['data'] ?? [],
                'today'    => $today,
                'queue'    => (int) db_val("SELECT COUNT(*) FROM wa_outbox WHERE status IN ('queued','sending')", [], 0),
                'server'   => date('d/m/Y H:i:s'),
            ];
            break;

        case 'qr':
            $name = preg_replace('/[^a-z0-9_-]/', '', (string) ($_GET['name'] ?? 'utama'));
            $r    = engine('GET', '/api/sessions/' . $name . '/qr');
            $out  = $r;
            break;

        case 'recap':
            $filters = recap_filters($_GET);
            $page_n  = max(1, (int) ($_GET['p'] ?? 1));
            $limit   = 15;
            $rows    = db_all(
                "SELECT m.*, c.name AS aplikasi, s.label AS sesi
                   FROM wa_messages m
                   LEFT JOIN wa_clients c ON c.id = m.client_id
                   LEFT JOIN wa_sessions s ON s.id = m.session_id
                  {$filters['where']}
                  ORDER BY m.id DESC LIMIT {$limit} OFFSET " . (($page_n - 1) * $limit),
                $filters['params']
            );
            $total = (int) db_val("SELECT COUNT(*) FROM wa_messages m {$filters['where']}", $filters['params'], 0);
            $out = ['ok' => true, 'data' => $rows, 'meta' => ['page' => $page_n, 'limit' => $limit, 'total' => $total,
                'pages' => max(1, (int) ceil($total / $limit))]];
            break;

        case 'outbox':
            $out = ['ok' => true, 'data' => db_all(
                "SELECT o.*, c.name AS aplikasi, s.label AS sesi
                   FROM wa_outbox o
                   LEFT JOIN wa_clients c ON c.id = o.client_id
                   LEFT JOIN wa_sessions s ON s.id = o.session_id
                  ORDER BY o.id DESC LIMIT 20"
            )];
            break;

        default:
            http_response_code(404);
            $out = ['ok' => false, 'error' => 'aksi tidak dikenal'];
    }
    exit(json_encode($out, JSON_UNESCAPED_UNICODE));
}

/** Filter rekap yang dipakai halaman & export. */
function recap_filters(array $in): array
{
    $where  = ['1=1'];
    $params = [];
    $status = trim((string) ($in['status'] ?? ''));
    if ($status !== '') {
        $allowed = ['queued', 'sending', 'sent', 'delivered', 'read', 'played', 'failed'];
        $parts   = array_values(array_intersect($allowed, explode(',', $status)));
        if ($parts) {
            $where[] = 'm.status IN (' . implode(',', array_fill(0, count($parts), '?')) . ')';
            $params  = array_merge($params, $parts);
        }
    }
    if (!empty($in['dari'])) {
        $where[]  = 'm.created_at >= ?';
        $params[] = $in['dari'] . ' 00:00:00';
    }
    if (!empty($in['sampai'])) {
        $where[]  = 'm.created_at <= ?';
        $params[] = $in['sampai'] . ' 23:59:59';
    }
    if (!empty($in['aplikasi'])) {
        $where[]  = 'm.client_id = ?';
        $params[] = (int) $in['aplikasi'];
    }
    if (!empty($in['nomor'])) {
        $where[]  = 'm.to_number LIKE ?';
        $params[] = '%' . normalize_phone((string) $in['nomor']) . '%';
    }
    if (!empty($in['cari'])) {
        $where[]  = '(m.body LIKE ? OR m.to_number LIKE ?)';
        $params[] = '%' . $in['cari'] . '%';
        $params[] = '%' . $in['cari'] . '%';
    }
    return ['where' => 'WHERE ' . implode(' AND ', $where), 'params' => $params];
}

// =====================================================================
// 2. Export CSV (Excel-friendly: pakai BOM + pemisah ;)
// =====================================================================
if ($page === 'export' && auth_user()) {
    $f  = recap_filters($_GET);
    $rows = db_all(
        "SELECT m.created_at, m.to_number, m.body, m.status, m.type, m.error,
                m.sent_at, m.delivered_at, m.read_at, COALESCE(c.name,'Manual/Excel') AS aplikasi, s.label AS sesi
           FROM wa_messages m
           LEFT JOIN wa_clients c ON c.id = m.client_id
           LEFT JOIN wa_sessions s ON s.id = m.session_id
          {$f['where']} ORDER BY m.id DESC",
        $f['params']
    );
    $name = 'rekap-wa-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $fh = fopen('php://output', 'w');
    fwrite($fh, "\xEF\xBB\xBF"); // BOM supaya Excel membaca UTF-8
    fputcsv($fh, ['Waktu', 'Nomor', 'Isi Pesan', 'Status', 'Tipe', 'Aplikasi', 'Sesi', 'Terkirim', 'Sampai', 'Dibaca', 'Error'], ';');
    foreach ($rows as $r) {
        fputcsv($fh, [
            $r['created_at'], fmt_phone($r['to_number']), $r['body'], $r['status'], $r['type'],
            $r['aplikasi'], $r['sesi'], $r['sent_at'], $r['delivered_at'], $r['read_at'], $r['error'],
        ], ';');
    }
    fclose($fh);
    exit;
}

// =====================================================================
// 3. Aksi POST
// =====================================================================
if ($method === 'POST') {
    csrf_check();

    switch ($action) {
        // ---------- autentikasi ----------
        case 'login':
            if (auth_attempt(trim((string) $_POST['username']), (string) $_POST['password'])) {
                redirect('index.php?page=dashboard');
            }
            flash('Username atau password salah.', 'err');
            redirect('index.php?page=login');

        case 'logout':
            auth_logout();
            redirect('index.php?page=login');

        // ---------- akun ----------
        case 'password_change':
            auth_require();
            $u = auth_user();
            $row = db_one('SELECT password_hash FROM wa_users WHERE id = ?', [$u['id']]);
            if (!password_verify((string) $_POST['lama'], (string) $row['password_hash'])) {
                flash('Password lama tidak sesuai.', 'err');
            } elseif (strlen((string) $_POST['baru']) < 6) {
                flash('Password baru minimal 6 karakter.', 'err');
            } elseif ($_POST['baru'] !== $_POST['ulangi']) {
                flash('Ulangi password tidak sama.', 'err');
            } else {
                db_exec('UPDATE wa_users SET password_hash = ? WHERE id = ?', [
                    password_hash((string) $_POST['baru'], PASSWORD_DEFAULT), $u['id'],
                ]);
                flash('Password berhasil diubah.');
            }
            redirect('index.php?page=pengaturan');

        // ---------- sesi WhatsApp ----------
        case 'session_start':
        case 'session_restart':
        case 'session_logout':
        case 'session_create':
            auth_require();
            $name = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($_POST['name'] ?? 'utama')));
            if ($action === 'session_create') {
                $label = trim((string) ($_POST['label'] ?? $name));
                $r = engine('POST', '/api/sessions', ['name' => $name, 'label' => $label, 'daily_quota' => (int) ($_POST['quota'] ?? 300)]);
                flash($r['ok'] ? "Sesi \"$name\" dibuat, tunggu QR muncul." : 'Gagal: ' . ($r['error'] ?? '?'), $r['ok'] ? 'ok' : 'err');
            } elseif ($action === 'session_logout') {
                $r = engine('POST', "/api/sessions/$name/logout");
                flash($r['ok'] ? "Sesi \"$name\" di-logout. Scan QR lagi untuk menyambungkan nomor baru." : 'Gagal: ' . ($r['error'] ?? '?'), $r['ok'] ? 'ok' : 'err');
            } else {
                $path = $action === 'session_restart' ? 'restart' : 'start';
                $r = engine('POST', "/api/sessions/$name/$path", ['fresh' => !empty($_POST['fresh'])]);
                flash($r['ok'] ? ucfirst($path) . " sesi \"$name\" dijalankan." : 'Gagal: ' . ($r['error'] ?? '?'), $r['ok'] ? 'ok' : 'err');
            }
            redirect('index.php?page=dashboard');

        // ---------- kirim pesan ----------
        case 'send':
            auth_require();
            $to   = normalize_phone((string) ($_POST['to'] ?? ''));
            $type = in_array($_POST['type'] ?? 'text', ['text', 'image', 'document'], true) ? $_POST['type'] : 'text';
            if ($to === '' || strlen($to) < 9) {
                flash('Nomor tujuan tidak valid.', 'err');
                redirect('index.php?page=kirim');
            }
            $media = null;
            $filename = null;
            if (!empty($_FILES['media']['name'])) {
                if (!is_dir((string) config('upload_dir'))) {
                    mkdir((string) config('upload_dir'), 0777, true);
                }
                $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $_FILES['media']['name']);
                $target   = rtrim((string) config('upload_dir'), '/\\') . DIRECTORY_SEPARATOR . date('Ymd-His') . '-' . $filename;
                if (!move_uploaded_file($_FILES['media']['tmp_name'], $target)) {
                    flash('Gagal mengunggah lampiran.', 'err');
                    redirect('index.php?page=kirim');
                }
                $media = str_replace('/', '\\', $target); // engine membaca path lokal Windows
            }
            $r = engine('POST', '/api/messages/send', [
                'to'        => $to,
                'type'      => $type,
                'body'      => (string) ($_POST['body'] ?? ''),
                'media_url' => $media,
                'filename'  => $filename,
                'session'   => $_POST['session'] ?? null,
                'wait'      => !empty($_POST['wait']),
            ]);
            if ($r['ok'] ?? false) {
                $st = $r['data']['status'] ?? 'queued';
                flash('Pesan ke ' . fmt_phone($to) . ' masuk antrean. Status: ' . $st . ($r['data']['last_error'] ? ' - ' . $r['data']['last_error'] : ''));
            } else {
                flash('Gagal kirim: ' . ($r['error'] ?? 'tidak diketahui'), 'err');
            }
            redirect('index.php?page=kirim');

        // ---------- impor massal CSV ----------
        case 'send_bulk':
            auth_require();
            if (empty($_FILES['file']['tmp_name'])) {
                flash('Pilih berkas CSV dulu.', 'err');
                redirect('index.php?page=kirim');
            }
            $fh = fopen($_FILES['file']['tmp_name'], 'r');
            if (!$fh) {
                flash('Berkas tidak bisa dibaca.', 'err');
                redirect('index.php?page=kirim');
            }
            $sessionId = (int) db_val('SELECT id FROM wa_sessions ORDER BY id LIMIT 1', [], 0);
            $ok = 0;
            $skip = 0;
            $line = 0;
            while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
                $line++;
                if ($line === 1 && !preg_match('/^\d/', preg_replace('/\D/', '', (string) ($row[0] ?? '')))) {
                    continue; // lewati header
                }
                $to   = normalize_phone((string) ($row[0] ?? ''));
                $body = trim((string) ($row[1] ?? ''));
                if (strlen($to) < 9 || $body === '') {
                    $skip++;
                    continue;
                }
                db_insert('wa_outbox', [
                    'session_id' => $sessionId, 'source' => 'excel', 'to_number' => $to,
                    'type' => 'text', 'body' => $body, 'status' => 'queued',
                ]);
                $ok++;
            }
            fclose($fh);
            flash("Impor selesai: $ok pesan masuk antrean, $skip baris dilewati.");
            redirect('index.php?page=antrean');

        // ---------- antrean ----------
        case 'outbox_retry':
            auth_require();
            db_exec("UPDATE wa_outbox SET status='queued', retry_count=0, last_error=NULL WHERE id = ? AND status IN ('failed','cancelled')", [(int) $_POST['id']]);
            engine('POST', '/api/pull-now');
            flash('Pesan dimasukkan kembali ke antrean.');
            redirect('index.php?page=antrean');

        case 'outbox_cancel':
            auth_require();
            db_exec("UPDATE wa_outbox SET status='cancelled' WHERE id = ? AND status='queued'", [(int) $_POST['id']]);
            flash('Pesan dibatalkan.');
            redirect('index.php?page=antrean');

        // ---------- aplikasi klien (mode PULL) ----------
        case 'client_save':
            auth_require();
            $id  = (int) ($_POST['id'] ?? 0);
            $data = [
                'name'          => trim((string) $_POST['name']),
                'base_url'      => rtrim(trim((string) $_POST['base_url']), '/'),
                'jobs_path'     => trim((string) ($_POST['jobs_path'] ?: '/api/wa-gateway/jobs')),
                'ack_path'      => trim((string) ($_POST['ack_path'] ?: '/api/wa-gateway/jobs/ack')),
                'session_id'    => ($_POST['session_id'] ?? '') !== '' ? (int) $_POST['session_id'] : null,
                'poll_interval' => max(5, (int) ($_POST['poll_interval'] ?? 10)),
                'batch_size'    => max(1, (int) ($_POST['batch_size'] ?? 20)),
                'is_active'     => !empty($_POST['is_active']) ? 1 : 0,
            ];
            $token = trim((string) ($_POST['token'] ?? ''));
            if ($token === '') {
                $token = bin2hex(random_bytes(24));
            }
            $data['token'] = $token;
            if ($id > 0) {
                db_exec(
                    'UPDATE wa_clients SET name=?, base_url=?, jobs_path=?, ack_path=?, session_id=?, poll_interval=?, batch_size=?, is_active=?, token=? WHERE id=?',
                    [...array_values($data), $id]
                );
                flash('Aplikasi diperbarui. Token: ' . $token);
            } else {
                db_insert('wa_clients', $data);
                flash('Aplikasi ditambahkan. Token: ' . $token);
            }
            redirect('index.php?page=klien');

        case 'client_delete':
            auth_require();
            db_exec('DELETE FROM wa_clients WHERE id = ?', [(int) $_POST['id']]);
            flash('Aplikasi dihapus.');
            redirect('index.php?page=klien');

        // Uji koneksi ke endpoint aplikasi PERSIS seperti yang dipakai engine, supaya
        // salah path atau token langsung ketahuan dari dashboard (tanpa menebak-nebak).
        case 'client_test':
            auth_require();
            $c = db_one('SELECT * FROM wa_clients WHERE id = ?', [(int) ($_POST['id'] ?? 0)]);
            if (!$c) {
                flash('Aplikasi tidak ditemukan.', 'err');
                redirect('index.php?page=klien');
            }

            $url = rtrim((string) $c['base_url'], '/') . (string) $c['jobs_path'];
            $ch  = curl_init($url . '?limit=1');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => ['X-Gateway-Token: ' . $c['token'], 'Accept: application/json'],
            ]);
            $body  = (string) curl_exec($ch);
            $code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $ctype = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $gagal = curl_error($ch);
            curl_close($ch);

            $cuplik = preg_replace('/\s+/', ' ', substr($body, 0, 110));
            if ($gagal !== '') {
                flash("GAGAL terhubung ke {$url} → {$gagal}. Periksa Base URL (tanpa garis miring di akhir) dan koneksi internet server ini.", 'err');
            } elseif ($code === 401 || $code === 403) {
                flash("TOKEN TIDAK COCOK → HTTP {$code} dari {$url}. Samakan kolom Token di sini dengan WA_GATEWAY_TOKEN di _config.php aplikasi. Balasan: {$cuplik}", 'err');
            } elseif (str_contains(strtolower($ctype), 'json')) {
                if (str_contains($body, '"ok":true') || str_contains($body, '"ok": true')) {
                    flash("BERHASIL → {$url} menjawab JSON (HTTP {$code}). Integrasi siap; klik “Tarik job sekarang”. Balasan: {$cuplik}", 'ok');
                } else {
                    flash("Endpoint menjawab JSON tetapi MENOLAK permintaan (HTTP {$code}): {$cuplik}", 'err');
                }
            } else {
                flash("PATH SALAH / ENDPOINT BELUM ADA → {$url} menjawab HTTP {$code} dengan tipe {$ctype} (bukan JSON). Pastikan kolom Path daftar pesan diisi path yang benar, mis. /wa-gateway/jobs.php — bukan dibiarkan kosong. Balasan: {$cuplik}", 'err');
            }
            redirect('index.php?page=klien');

        case 'client_pull':
            auth_require();
            $r = engine('POST', '/api/pull-now');
            flash(($r['ok'] ?? false) ? 'Menarik job: ' . (int) ($r['data']['inserted'] ?? 0) . ' pesan baru masuk antrean.' : 'Gagal: ' . ($r['error'] ?? '?'), ($r['ok'] ?? false) ? 'ok' : 'err');
            redirect('index.php?page=klien');

        // ---------- pengaturan ----------
        case 'settings_save':
            auth_require();
            setting_set('send_delay_min', max(0, (int) $_POST['send_delay_min']));
            setting_set('send_delay_max', max((int) $_POST['send_delay_min'], (int) $_POST['send_delay_max']));
            setting_set('worker_enabled', !empty($_POST['worker_enabled']) ? '1' : '0');
            engine('POST', '/api/settings', [
                'send_delay_min' => (int) $_POST['send_delay_min'],
                'send_delay_max' => (int) $_POST['send_delay_max'],
                'worker_enabled' => !empty($_POST['worker_enabled']) ? '1' : '0',
            ]);
            foreach (db_all('SELECT id FROM wa_sessions') as $s) {
                db_exec('UPDATE wa_sessions SET daily_quota = ? WHERE id = ?', [max(1, (int) $_POST['quota'][$s['id']]), $s['id']]);
            }
            flash('Pengaturan disimpan. Jeda kirim baru aktif setelah engine di-restart (jeda dibaca dari .env).');
            redirect('index.php?page=pengaturan');

        case 'data_clear':
            auth_require();
            $what = (string) ($_POST['what'] ?? '');
            if ($what === 'logs') {
                db_exec('DELETE FROM wa_logs');
            } elseif ($what === 'messages') {
                db_exec("DELETE FROM wa_messages WHERE status NOT IN ('queued','sending')");
            } elseif ($what === 'inbound') {
                db_exec('DELETE FROM wa_inbound');
            }
            flash('Data dibersihkan.');
            redirect('index.php?page=pengaturan');
    }

    // aksi tidak dikenal -> kembali ke dashboard
    redirect('index.php?page=dashboard');
}

// =====================================================================
// 4. Render halaman
// =====================================================================
$public = ['login'];
if (!in_array($page, $public, true)) {
    auth_require();
}

$pages = ['login', 'dashboard', 'kirim', 'rekap', 'antrean', 'masuk', 'klien', 'log', 'pengaturan', 'panduan'];
if (!in_array($page, $pages, true)) {
    $page = 'dashboard';
}

$active = $page;
require __DIR__ . '/views/layout.php';
