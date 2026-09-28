import { config } from './config.js';
import { q, one, dbPing, dbLog, setSetting, getSetting } from './db.js';
import { WaSession } from './session.js';
import { createApi } from './api.js';
import { startSender } from './sender.js';
import { startPuller } from './puller.js';
import { bus } from './bus.js';

const sessions = new Map();

async function ensureSchema() {
  const row = await one(
    `SELECT COUNT(*) AS n FROM information_schema.tables
      WHERE table_schema = ? AND table_name IN ('wa_sessions','wa_outbox','wa_messages','wa_clients')`,
    [config.db.database]
  );
  if (Number(row?.n ?? 0) < 4) {
    throw new Error(
      `skema database belum lengkap di "${config.db.database}". Import dulu: mysql -u root < sql/schema.sql`
    );
  }
}

async function loadSessions() {
  const rows = await q('SELECT * FROM wa_sessions ORDER BY id');
  for (const row of rows) sessions.set(row.name, new WaSession(row));
  console.log(`[info] ${rows.length} sesi dimuat dari database`);
  return rows;
}

async function main() {
  console.log('================ WA GATEWAY LOKAL ================');
  await dbPing();
  await ensureSchema();

  // Token engine disimpan juga di DB supaya dashboard PHP bisa membacanya
  const dbToken = await getSetting('engine_token');
  if (dbToken !== config.token) {
    await setSetting('engine_token', config.token);
    console.log('[info] token engine disinkronkan ke wa_settings');
  }

  const rows = await loadSessions();

  // Nyalakan sesi yang di-set auto_start (dan yang sebelumnya tersambung)
  for (const row of rows) {
    if (Number(row.auto_start) === 1) {
      const s = sessions.get(row.name);
      s.start().catch((e) => console.error(`[error] start ${row.name}:`, e.message));
    }
  }

  const app = createApi(sessions);
  const server = app.listen(config.port, config.host, () => {
    console.log(`[ready] engine jalan di http://${config.host}:${config.port}`);
    console.log(`[ready] token       : ${config.token}`);
    console.log(`[ready] mode        : PULL ke aplikasi hosting (interval ${config.pullInterval}s)`);
    dbLog('info', 'engine_ready', `engine jalan di ${config.host}:${config.port}`);
  });

  const stopSender = startSender(sessions);
  const stopPuller = startPuller(sessions);

  // Heartbeat: tandai sesi yang hidup + broadcast ringkasan tiap 30 detik
  const hb = setInterval(async () => {
    try {
      for (const [name, s] of sessions) {
        if (s.isReady) await q('UPDATE wa_sessions SET last_seen_at = NOW() WHERE name = ?', [name]);
      }
      const pending = await one('SELECT COUNT(*) AS n FROM wa_outbox WHERE status IN ("queued","sending")');
      bus.publish('stats', { queue_pending: Number(pending?.n ?? 0), sessions: [...sessions.values()].map((s) => ({ name: s.name, status: s.status })) });
    } catch (e) {
      console.error('[warn] heartbeat:', e.message);
    }
  }, 30000);

  const shutdown = async (sig) => {
    console.log(`\n[info] ${sig} diterima, menutup gateway...`);
    clearInterval(hb);
    stopSender();
    stopPuller();
    server.close();
    await dbLog('info', 'engine_stop', 'engine dihentikan');
    process.exit(0);
  };
  process.on('SIGINT', () => shutdown('SIGINT'));
  process.on('SIGTERM', () => shutdown('SIGTERM'));
  process.on('uncaughtException', (e) => console.error('[error] uncaught:', e));
  process.on('unhandledRejection', (e) => console.error('[error] unhandled:', e));
}

main().catch(async (e) => {
  console.error('[FATAL]', e.message);
  try {
    await dbLog('error', 'boot_failed', e.message);
  } catch { /* DB mungkin belum siap */ }
  process.exit(1);
});
