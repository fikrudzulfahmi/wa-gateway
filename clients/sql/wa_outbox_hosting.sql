-- =====================================================================
--  TABEL SISI HOSTING (mode PULL)
--  Jalankan di database APLIKASI (bukan di database gateway).
--  Kolom sengaja sederhana supaya mudah ditempelkan ke aplikasi apa pun.
-- =====================================================================

CREATE TABLE IF NOT EXISTS `wa_outbox` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nomor`         VARCHAR(20)  NOT NULL COMMENT 'boleh 08xx atau 628xx, gateway menormalkan sendiri',
  `tipe`          ENUM('text','image','document') NOT NULL DEFAULT 'text',
  `isi`           TEXT         DEFAULT NULL COMMENT 'isi pesan / caption',
  `media_url`     VARCHAR(500) DEFAULT NULL COMMENT 'URL publik berkas (untuk image/document)',
  `filename`      VARCHAR(255) DEFAULT NULL,
  `kategori`      VARCHAR(50)  DEFAULT NULL COMMENT 'bebas: presensi, tagihan, pengumuman',
  `ref_berkas`    VARCHAR(100) DEFAULT NULL COMMENT 'id referensi di aplikasi, mis. id presensi',
  `status`        ENUM('pending','diproses','terkirim','sampai','dibaca','gagal','dibatalkan')
                  NOT NULL DEFAULT 'pending' COMMENT 'pending = menunggu diambil gateway',
  `wa_message_id` VARCHAR(120) DEFAULT NULL COMMENT 'diisi dari laporan gateway',
  `error`         VARCHAR(255) DEFAULT NULL,
  `scheduled_at`  DATETIME     DEFAULT NULL COMMENT 'NULL = kirim secepatnya',
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_outbox_pending` (`status`, `scheduled_at`, `id`),
  KEY `idx_outbox_nomor` (`nomor`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contoh: siapkan 1 pesan untuk diuji
-- INSERT INTO wa_outbox (nomor, tipe, isi, kategori) VALUES ('081234567890','text','Tes dari aplikasi hosting','uji');
