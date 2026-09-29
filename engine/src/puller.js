import axios from 'axios';
import { config } from './config.js';
import { q, one, dbLog, getSetting } from './db.js';
import { bus } from './bus.js';
import { normalizePhone, isValidPhone } from './phone.js';

const enabled = () => (config.pullAck ? true : false);

/** Peredam log: error identik untuk klien yang sama dicatat maksimal tiap 5 menit. */
const PULL_ERR_LOG_INTERVAL = 5 * 60 * 1000;
const pullErrThrottle = new Map(); // client_id -> { msg, at }

/** Jeda minimum antar percobaan lapor-balik ulang untuk job yang sudah pernah ditarik. */
const ACK_ULANG_INTERVAL = 5 * 60 * 1000;
const ackUlangThrottle = new Map(); // client_id -> waktu percobaan terakhir

function httpClient() {
  return axios.create({ timeout: 20000, headers: { Accept: 'application/json' } });
}

function normalizeJob(raw) {
  const to = normalizePhone(raw.to ?? raw.to_number ?? raw.phone ?? raw.nomor);
  return {
    ref: String(raw.id ?? raw.ref ?? raw.job_id ?? '').slice(0, 100) || null,
    to_number: to,
    type: ['text', 'image', 'document'].includes(raw.type) ? raw.type : 'text',
    body: raw.body ?? raw.message ?? raw.pesan ?? null,
    media_url: raw.media_url ?? raw.media ?? null,
    filename: raw.filename ?? null,
    scheduled_at: raw.scheduled_at ?? raw.schedule ?? null,
    valid: isValidPhone(to),
  };
}

/**
 * Tarik job dari satu aplikasi hosting (mode PULL).
 * Kontrak: GET {base_url}{jobs_path}?limit=n  + header X-Gateway-Token
 *          Respons JSON: { data: [ { id, to, type, body, media_url } ] }
 */
export async function pullFromClient(client, sessions) {
  const url = `${String(client.base_url).replace(/\/+$/, '')}${client.jobs_path}`;
  const res = await httpClient().get(url, {
    params: { limit: client.batch_size },
    headers: { 'X-Gateway-Token': client.token },
  });

  // PENTING: hosting yang belum punya endpoint (atau URL salah) sering menjawab
  // HTTP 200 + halaman HTML (bukan JSON), sehingga tanpa pemeriksaan ini gateway
  // akan "sukses" palsu dengan 0 pesan dan pengguna mengira integrasinya jalan.
  const ctype = String(res.headers?.['content-type'] ?? '');
  const body = res.data;
  let list = null;
  if (Array.isArray(body)) list = body;
  else if (body && typeof body === 'object') list = body.data ?? body.jobs ?? null;

  if (!Array.isArray(list)) {
    throw new Error(
      `respons bukan daftar JSON (content-type: ${ctype || 'kosong'}). ` +
        'Periksa apakah endpoint sudah diunggah dan path-nya benar.'
    );
  }

  const jobs = list.map(normalizeJob).filter((j) => j.to_number && j.valid);
  const sessionId = client.session_id ?? [...sessions.values()][0]?.id ?? null;

  let inserted = 0;
  let duplikat = 0;
  // Lapor balik untuk job yang sudah pernah ditarik: dilakukan ulang (maks. tiap 5 menit)
  // supaya aplikasi menyusul bila ack sebelumnya gagal - mis. karena path laporan balik
  // sempat salah. Tanpa ini, baris di aplikasi menggantung 'pending' selamanya dan
  // terus disajikan tiap siklus (gateway memang tidak mengirim ulang, jadi aman).
  const bolehAckUlang =
    enabled() && Date.now() - (ackUlangThrottle.get(client.id) ?? 0) >= ACK_ULANG_INTERVAL;

  for (const job of jobs) {
    if (job.ref) {
      const dup = await one('SELECT id FROM wa_outbox WHERE client_id = ? AND ref = ? LIMIT 1', [client.id, job.ref]);
      if (dup) {
        duplikat += 1;
        if (bolehAckUlang) await ackToClient(null, 'queued', { ref: job.ref, client });
        continue;
      }
    }
    await q(
      `INSERT INTO wa_outbox (session_id, client_id, source, ref, to_number, type, body, media_url, filename, scheduled_at, status)
       VALUES (?,?, 'pull', ?,?,?,?,?,?,?, 'queued')`,
      [sessionId, client.id, job.ref, job.to_number, job.type, job.body, job.media_url, job.filename, job.scheduled_at]
    );
    inserted += 1;
    // Kabari aplikasi bahwa job sudah diambil, supaya tidak dikirim ulang oleh siklus berikutnya
    if (job.ref && enabled()) await ackToClient(null, 'queued', { ref: job.ref, client });
  }

  if (duplikat > 0 && bolehAckUlang) {
    ackUlangThrottle.set(client.id, Date.now());
    await dbLog(
      'warning',
      'pull_duplikat',
      `${duplikat} job dari ${client.name} sudah pernah ditarik: TIDAK dikirim ulang, laporan balik dicoba lagi ` +
        '(bila berulang terus, periksa kolom "Path laporan balik" aplikasi itu)',
      null,
      sessionId
    );
  }

  await q(
    `UPDATE wa_clients SET last_pull_at = NOW(), last_pull_status = ?, pulled_count = pulled_count + ?, last_error = NULL WHERE id = ?`,
    [`ok:${inserted}`, inserted, client.id]
  );
  if (inserted > 0) {
    bus.publish('pull', { client: client.name, inserted });
    await dbLog('info', 'pull_ok', `ambil ${inserted} job dari ${client.name}`, null, sessionId);
  }
  return inserted;
}

/** Ambil semua klien aktif lalu tarik job-nya (dipanggil berkala). */
export async function pullAll(sessions) {
  if ((await getSetting('worker_enabled', '1')) !== '1') return 0;
  const clients = await q('SELECT * FROM wa_clients WHERE is_active = 1');
  let total = 0;
  for (const client of clients) {
    const last = client.last_pull_at ? new Date(client.last_pull_at).getTime() : 0;
    if (Date.now() - last < Number(client.poll_interval) * 1000) continue;
    try {
      total += await pullFromClient(client, sessions);
      await q('UPDATE wa_clients SET last_pull_at = NOW() WHERE id = ?', [client.id]);
      pullErrThrottle.delete(client.id);
    } catch (e) {
      const msg = String(e?.message ?? e).slice(0, 250);
      await q('UPDATE wa_clients SET last_pull_at = NOW(), last_error = ? WHERE id = ?', [msg, client.id]);
      // Peredam: error yang sama tidak dicatat berulang tiap siklus (bisa ribuan baris/hari)
      const prev = pullErrThrottle.get(client.id);
      const now = Date.now();
      if (!prev || prev.msg !== msg || now - prev.at > PULL_ERR_LOG_INTERVAL) {
        pullErrThrottle.set(client.id, { msg, at: now });
        await dbLog('warning', 'pull_error', `${client.name}: ${msg}`, null, client.session_id);
      }
    }
  }
  return total;
}

/**
 * Lapor balik status akhir pesan ke aplikasi hosting.
 * Kontrak: POST {base_url}{ack_path}  body { ref, status, wa_message_id, error, sent_at }
 */
export async function ackToClient(outboxId, status, extra = {}) {
  if (!enabled() && !extra.client) return false;
  let ref = extra.ref ?? null;
  let client = extra.client ?? null;

  if (outboxId && !ref) {
    const row = await one(
      `SELECT o.ref, o.client_id, c.name, c.base_url, c.ack_path, c.token
         FROM wa_outbox o JOIN wa_clients c ON c.id = o.client_id
        WHERE o.id = ? AND o.client_id IS NOT NULL`,
      [outboxId]
    );
    if (!row) return false;
    ref = row.ref;
    client = { name: row.name, base_url: row.base_url, ack_path: row.ack_path, token: row.token };
  }
  if (!ref || !client) return false;

  const url = `${String(client.base_url).replace(/\/+$/, '')}${client.ack_path}`;
  const payload = {
    ref,
    status,
    wa_message_id: extra.wa_message_id ?? null,
    error: extra.error ?? null,
    sent_at: new Date().toISOString(),
  };
  try {
    await httpClient().post(url, payload, { headers: { 'X-Gateway-Token': client.token } });
    return true;
  } catch (e) {
    await dbLog('warning', 'ack_error', `${client.name} ref=${ref}: ${String(e?.message ?? e).slice(0, 180)}`);
    return false;
  }
}

/** Loop penarik job: berjalan tiap PULL_INTERVAL detik. */
export function startPuller(sessions) {
  if (!config.pullInterval || config.pullInterval <= 0) {
    dbLog('info', 'puller_off', 'mode PULL nonaktif (PULL_INTERVAL=0)');
    return () => {};
  }
  let stopped = false;
  const loop = async () => {
    if (stopped) return;
    try {
      await pullAll(sessions);
    } catch (e) {
      console.error('[error] puller:', e.message);
    }
    if (!stopped) setTimeout(loop, Math.max(5, config.pullInterval) * 1000);
  };
  setTimeout(loop, 6000);
  return () => {
    stopped = true;
  };
}
