import 'dotenv/config';
import path from 'node:path';
import fs from 'node:fs';
import { fileURLToPath } from 'node:url';
import crypto from 'node:crypto';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
export const ENGINE_ROOT = path.resolve(__dirname, '..');

const int = (v, d) => {
  const n = parseInt(v ?? '', 10);
  return Number.isFinite(n) ? n : d;
};
const bool = (v, d = false) => (v === undefined || v === '' ? d : ['1', 'true', 'yes', 'on'].includes(String(v).toLowerCase()));

// --- Token engine: dari .env, kalau kosong buat baru & tulis balik ke .env ---
let token = (process.env.ENGINE_TOKEN || '').trim();
if (!token) {
  token = crypto.randomBytes(24).toString('hex');
  const envPath = path.join(ENGINE_ROOT, '.env');
  try {
    let txt = fs.readFileSync(envPath, 'utf8');
    txt = txt.replace(/^ENGINE_TOKEN=.*$/m, `ENGINE_TOKEN=${token}`);
    fs.writeFileSync(envPath, txt);
  } catch {
    /* .env belum ada -> cukup pakai token di memori */
  }
}

export const config = {
  host: process.env.ENGINE_HOST || '127.0.0.1',
  port: int(process.env.ENGINE_PORT, 3001),
  token,

  db: {
    host: process.env.DB_HOST || '127.0.0.1',
    port: int(process.env.DB_PORT, 3306),
    user: process.env.DB_USER || 'wa_gateway',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME || 'wa_gateway',
  },

  sessionsDir: path.join(ENGINE_ROOT, 'sessions'),
  delayMin: int(process.env.SEND_DELAY_MIN, 3),
  delayMax: int(process.env.SEND_DELAY_MAX, 8),
  workerTick: int(process.env.WORKER_TICK, 2),
  pullInterval: int(process.env.PULL_INTERVAL, 10),
  pullAck: bool(process.env.PULL_ACK, true),
  logLevel: process.env.LOG_LEVEL || 'info',
};

fs.mkdirSync(config.sessionsDir, { recursive: true });
