import mysql from 'mysql2/promise';
import { config } from './config.js';

export const pool = mysql.createPool({
  host: config.db.host,
  port: config.db.port,
  user: config.db.user,
  password: config.db.password,
  database: config.db.database,
  waitForConnections: true,
  connectionLimit: 8,
  queueLimit: 0,
  charset: 'utf8mb4_unicode_ci',
  timezone: 'local',
});

/** Jalankan query, kembalikan rows. */
export async function q(sql, params = []) {
  const [rows] = await pool.execute(sql, params);
  return rows;
}

/** Ambil satu baris (atau null). */
export async function one(sql, params = []) {
  const rows = await q(sql, params);
  return rows.length ? rows[0] : null;
}

/** MySQL tidak menerima binding untuk LIMIT pada prepared statement -> validasi angka. */
export const safeInt = (v, d = 10, min = 1, max = 500) => {
  const n = parseInt(v ?? '', 10);
  if (!Number.isFinite(n)) return d;
  return Math.min(Math.max(n, min), max);
};

/** Tulis log ke wa_logs + ke console. */
export async function dbLog(level, event, message = null, payload = null, sessionId = null) {
  const tag = `[${level.toUpperCase()}] ${event}${message ? ' - ' + message : ''}`;
  if (level === 'error') console.error(tag);
  else if (level === 'warning') console.warn(tag);
  else console.log(tag);
  try {
    await q(
      'INSERT INTO wa_logs (session_id, level, event, message, payload) VALUES (?,?,?,?,?)',
      [sessionId, level, event, message?.slice(0, 500) ?? null, payload ? JSON.stringify(payload) : null]
    );
  } catch (e) {
    console.error('[warn] gagal simpan log:', e.message);
  }
}

export async function getSetting(key, fallback = null) {
  const row = await one('SELECT `value` FROM wa_settings WHERE `key` = ?', [key]);
  return row?.value ?? fallback;
}

export async function setSetting(key, value) {
  await q(
    'INSERT INTO wa_settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
    [key, value === null ? null : String(value)]
  );
}

export async function dbPing() {
  await q('SELECT 1');
  return true;
}
