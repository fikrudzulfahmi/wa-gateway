-- =====================================================================
--  WA GATEWAY LOKAL - Skema Database (MySQL / MariaDB, XAMPP)
--  Database: wa_gateway
--  Charset : utf8mb4 / utf8mb4_unicode_ci (aman untuk MariaDB XAMPP)
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `wa_gateway`
  DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `wa_gateway`;

-- ---------------------------------------------------------------------
-- 1. SESI WHATSAPP (fitur: status koneksi WA)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wa_sessions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(50)  NOT NULL COMMENT 'nama sesi, dipakai juga sebagai nama folder auth',
  `label`       VARCHAR(100) DEFAULT NULL COMMENT 'keterangan bebas, mis. "Nomor Notifikasi Sekolah"',
  `phone`       VARCHAR(20)  DEFAULT NULL COMMENT 'nomor WA setelah tersambung (format 62xxx)',
  `status`      ENUM('disconnected','qr','connecting','connected','logged_out') NOT NULL DEFAULT 'disconnected',
  `qr_string`   TEXT         DEFAULT NULL,
  `qr_updated_at` DATETIME   DEFAULT NULL,
  `last_error`  VARCHAR(255) DEFAULT NULL,
  `auto_start`  TINYINT(1)   NOT NULL DEFAULT 1,
  `daily_quota` INT UNSIGNED NOT NULL DEFAULT 300 COMMENT 'batas pesan keluar per hari per sesi',
  `sent_today`  INT UNSIGNED NOT NULL DEFAULT 0,
  `quota_date`  DATE         DEFAULT NULL,
  `connected_at` DATETIME    DEFAULT NULL,
  `last_seen_at` DATETIME    DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sessions_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. KLIEN / APLIKASI HOSTING (untuk mode PULL)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wa_clients` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(100) NOT NULL COMMENT 'nama aplikasi, mis. "SIM PKL"',
  `base_url`       VARCHAR(255) NOT NULL COMMENT 'mis. https://pkl.ingintau.my.id',
  `jobs_path`      VARCHAR(120) NOT NULL DEFAULT '/api/wa-gateway/jobs',
  `ack_path`       VARCHAR(120) NOT NULL DEFAULT '/api/wa-gateway/jobs/ack',
  `token`          VARCHAR(128) NOT NULL COMMENT 'token gateway (dikirim sebagai X-Gateway-Token)',
  `session_id`     INT UNSIGNED DEFAULT NULL COMMENT 'sesi WA yang dipakai klien ini (NULL = sesi default)',
  `poll_interval`  INT UNSIGNED NOT NULL DEFAULT 10 COMMENT 'detik',
  `batch_size`     INT UNSIGNED NOT NULL DEFAULT 20,
  `is_active`      TINYINT(1)   NOT NULL DEFAULT 1,
  `last_pull_at`   DATETIME     DEFAULT NULL,
  `last_pull_status` VARCHAR(255) DEFAULT NULL,
  `last_error`     VARCHAR(255) DEFAULT NULL,
  `pulled_count`   INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_clients_name` (`name`),
  KEY `fk_clients_session` (`session_id`),
  CONSTRAINT `fk_clients_session` FOREIGN KEY (`session_id`) REFERENCES `wa_sessions`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. ANTRIAN KELUAR (semua pesan yang mau dikirim)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wa_outbox` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id`   INT UNSIGNED NOT NULL,
  `client_id`    INT UNSIGNED DEFAULT NULL COMMENT 'NULL = dikirim manual dari dashboard / import Excel',
  `source`       ENUM('dashboard','excel','api','pull') NOT NULL DEFAULT 'dashboard',
  `ref`          VARCHAR(100) DEFAULT NULL COMMENT 'ID job di sisi aplikasi hosting (untuk ack)',
  `to_number`    VARCHAR(20)  NOT NULL COMMENT 'format 62xxx',
  `type`         ENUM('text','image','document') NOT NULL DEFAULT 'text',
  `body`         TEXT         DEFAULT NULL COMMENT 'isi teks / caption',
  `media_url`    VARCHAR(500) DEFAULT NULL COMMENT 'URL atau path file media',
  `filename`     VARCHAR(255) DEFAULT NULL,
  `status`       ENUM('queued','sending','sent','delivered','read','failed','cancelled') NOT NULL DEFAULT 'queued',
  `retry_count`  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `max_retry`    TINYINT UNSIGNED NOT NULL DEFAULT 3,
  `priority`     TINYINT NOT NULL DEFAULT 5 COMMENT '1 = tertinggi',
  `scheduled_at` DATETIME     DEFAULT NULL COMMENT 'NULL = kirim ASAP',
  `claimed_at`   DATETIME     DEFAULT NULL COMMENT 'mencegah dobel ambil oleh siklus pull',
  `sent_at`      DATETIME     DEFAULT NULL,
  `last_error`   VARCHAR(255) DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_outbox_worker` (`status`, `scheduled_at`, `priority`, `id`),
  KEY `idx_outbox_client` (`client_id`, `status`),
  KEY `fk_outbox_session` (`session_id`),
  CONSTRAINT `fk_outbox_session` FOREIGN KEY (`session_id`) REFERENCES `wa_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. RIWAYAT PESAN KELUAR + STATUS AKHIR (fitur: status pesan & rekap)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wa_messages` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `outbox_id`     BIGINT UNSIGNED DEFAULT NULL,
  `session_id`    INT UNSIGNED NOT NULL,
  `client_id`     INT UNSIGNED DEFAULT NULL,
  `direction`     ENUM('out','in') NOT NULL DEFAULT 'out',
  `wa_message_id` VARCHAR(120) DEFAULT NULL COMMENT 'key.id dari WhatsApp',
  `chat_id`       VARCHAR(40)  NOT NULL COMMENT 'JID lengkap, mis. 62812xxx@s.whatsapp.net',
  `to_number`     VARCHAR(20)  DEFAULT NULL,
  `type`          VARCHAR(20)  NOT NULL DEFAULT 'text',
  `body`          TEXT         DEFAULT NULL,
  `status`        ENUM('queued','sending','sent','delivered','read','played','failed') NOT NULL DEFAULT 'queued',
  `ack_code`      TINYINT      DEFAULT NULL COMMENT '1=pending 2=server 3=delivered 4=read 5=played',
  `error`         VARCHAR(255) DEFAULT NULL,
  `sent_at`       DATETIME     DEFAULT NULL,
  `delivered_at`  DATETIME     DEFAULT NULL,
  `read_at`       DATETIME     DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_messages_waid` (`wa_message_id`),
  KEY `idx_messages_recap` (`created_at`, `status`),
  KEY `idx_messages_client` (`client_id`, `created_at`),
  KEY `idx_messages_number` (`to_number`),
  KEY `fk_messages_session` (`session_id`),
  CONSTRAINT `fk_messages_session` FOREIGN KEY (`session_id`) REFERENCES `wa_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. PESAN MASUK (opsional, untuk log balasan / opt-out)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wa_inbound` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id`    INT UNSIGNED NOT NULL,
  `wa_message_id` VARCHAR(120) DEFAULT NULL,
  `from_number`   VARCHAR(20)  NOT NULL,
  `push_name`     VARCHAR(100) DEFAULT NULL,
  `type`          VARCHAR(20)  NOT NULL DEFAULT 'text',
  `body`          TEXT         DEFAULT NULL,
  `received_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inbound_waid` (`wa_message_id`),
  KEY `idx_inbound_from` (`from_number`, `received_at`),
  KEY `fk_inbound_session` (`session_id`),
  CONSTRAINT `fk_inbound_session` FOREIGN KEY (`session_id`) REFERENCES `wa_sessions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. PENGATURAN & 7. LOG/AUDIT
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wa_settings` (
  `key`        VARCHAR(60) NOT NULL,
  `value`      TEXT        DEFAULT NULL,
  `updated_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `wa_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` INT UNSIGNED DEFAULT NULL,
  `level`      ENUM('info','warning','error') NOT NULL DEFAULT 'info',
  `event`      VARCHAR(60)  NOT NULL,
  `message`    VARCHAR(500) DEFAULT NULL,
  `payload`    TEXT         DEFAULT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_logs_time` (`created_at`, `level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. ADMIN DASHBOARD
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `wa_users` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(50)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `nama`          VARCHAR(100) DEFAULT NULL,
  `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
  `last_login_at` DATETIME     DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Nilai awal
-- ---------------------------------------------------------------------
INSERT INTO `wa_settings` (`key`, `value`) VALUES
  ('engine_token',      NULL),
  ('send_delay_min',    '3'),
  ('send_delay_max',    '8'),
  ('worker_enabled',    '1'),
  ('sender_name',       'WA Gateway')
ON DUPLICATE KEY UPDATE `value` = VALUES(`value`);

INSERT INTO `wa_sessions` (`name`, `label`, `auto_start`)
VALUES ('utama', 'Sesi Utama', 1)
ON DUPLICATE KEY UPDATE `label` = VALUES(`label`);

-- ---------------------------------------------------------------------
-- VIEW REKAP HARIAN (dipakai dashboard & export)
-- ---------------------------------------------------------------------
CREATE OR REPLACE VIEW `v_rekap_harian` AS
SELECT DATE(m.created_at)              AS tanggal,
       m.client_id,
       c.name                          AS aplikasi,
       m.session_id,
       s.label                         AS sesi,
       COUNT(*)                        AS total,
       SUM(m.status IN ('sent','delivered','read','played')) AS terkirim,
       SUM(m.status = 'failed')        AS gagal,
       SUM(m.status IN ('read','played')) AS dibaca,
       SUM(m.status IN ('queued','sending')) AS dalam_proses
FROM wa_messages m
LEFT JOIN wa_clients  c ON c.id = m.client_id
LEFT JOIN wa_sessions s ON s.id = m.session_id
WHERE m.direction = 'out'
GROUP BY DATE(m.created_at), m.client_id, m.session_id;
