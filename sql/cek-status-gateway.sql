-- ============================================================
--  PEMERIKSAAN SISI GATEWAY (XAMPP phpMyAdmin / DB: wa_gateway)
--  Jalankan bila pesan dari aplikasi hosting tidak kunjung terkirim.
-- ============================================================

-- 1. Kondisi tarikan terakhir per aplikasi klien (SIBER dll.)
SELECT id, name, is_active, base_url, jobs_path, ack_path,
       session_id, poll_interval, last_pull_at, last_pull_status, last_error
  FROM wa_clients;

-- 2. Status sesi WhatsApp yang terdaftar
SELECT id, name, label, status, is_active, auto_start, daily_quota, last_seen_at
  FROM wa_sessions;

-- 3. Rekap antrean lokal hasil tarikan (client_id NOT NULL = PULL)
SELECT status, COUNT(*) AS jumlah
  FROM wa_outbox
 WHERE client_id IS NOT NULL
 GROUP BY status;

-- 4. 15 baris terakhir antrean lokal
SELECT id, client_id, ref, to_number, status, retry_count, last_error, created_at
  FROM wa_outbox
 WHERE client_id IS NOT NULL
 ORDER BY id DESC
 LIMIT 15;

-- 5. 30 baris log terbaru engine
SELECT level, event, LEFT(message, 200) AS pesan, created_at
  FROM wa_logs
 ORDER BY id DESC
 LIMIT 30;
