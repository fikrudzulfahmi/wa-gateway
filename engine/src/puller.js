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
 * Petakan status lokal wa_outbox -> status yang dikenal aplikasi hosting.
 * Dipakai saat sebuah job sudah pernah ditarik: aplikasi menerima status AKTUAL
 * baris itu (mis. 'sent' bila ack sebelumnya hilang), bukan selalu 'queued'.
 */
function statusUntukKlien(status) {
  if (['sent', 'delivered', 'read', 'played'].includes(status)) return 'sent';
  if (['failed', 'cancelled'].includes(status)) return 'failed';
  return 'queued';
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

  const jobs = list.map(normalizeJob);

  // Job yang nomornya tidak bisa dipakai WhatsApp (kosong / di luar 9–15 digit)
  // TIDAK lagi dibuang diam-diam: dulu barisnya tetap 'pending' selamanya di
  // aplikasi dan — karena aplikasi mengambil 20 baris terdepan — menyumbat
  // antrean sehingga pesan baru tidak pernah tertarik. Sekarang dilaporkan
  // 'failed' supaya keluar dari antrean.
  const ditolak = jobs.filter((j) => !j.to_number || !j.valid);
  const layak = jobs.filter((j) => j.to_number && j.valid);

  // Sesi WA yang dipakai untuk pesan-pesan ini. Kalau TIDAK ada sesi sama
  // sekali, pull dibatalkan: wa_outbox.session_id NOT NULL + ber-FK, sehingga
  // INSERT akan gagal dan seluruh siklus pull batal (antrean aplikasi tetap
  // 'pending' tanpa sebab yang kelihatan di dashboard).
  let sessionId = client.session_id ? Number(client.session_id) : null;
  if (sessionId && !(await one('SELECT id FROM wa_sessions WHERE id = ?', [sessionId]))) sessionId = null;
  if (!sessionId) sessionId = [...sessions.values()][0]?.id ?? null;
  if (!sessionId) {
    const pesanLewat = 'tidak ada sesi WA terdaftar di wa_sessions — pull dilewati sampai ada sesi';
    await q('UPDATE wa_clients SET last_error = ? WHERE id = ?', [pesanLewat, client.id]);
    await dbLog('warning', 'pull_dilewati', `${client.name}: ${pesanLewat}`, null, null);
    return 0;
  }

  let inserted = 0;
  let duplikat = 0;
  let ditolakCount = 0;

  // Peredam log saja: ack untuk job yang sudah pernah ditarik SELALU dikirim
  // (endpoint ack idempoten), tetapi barisnya tidak dicatat berulang.
  const bolehAckUlang =
    enabled() && Date.now() - (ackUlangThrottle.get(client.id) ?? 0) >= ACK_ULANG_INTERVAL;

  for (const bad of ditolak) {
    ditolakCount += 1;
    if (bad.ref && enabled()) {
      await ackToClient(null, 'failed', {
        ref: bad.ref,
        client,
        error: 'nomor tujuan tidak valid untuk WhatsApp (harus 9-15 digit, format 62xxx)',
      });
    }
  }

  for (const job of layak) {
    if (job.ref) {
      const dup = await one(
        'SELECT id, status FROM wa_outbox WHERE client_id = ? AND ref = ? LIMIT 1',
        [client.id, job.ref]
      );
      if (dup) {
        duplikat += 1;
        // Sudah pernah ditarik: laporkan status AKTUAL baris itu, bukan selalu
        // 'queued'. Bila ack sebelumnya hilang padahal pesannya sudah terkirim,
        // aplikasi kini ikut menyusul jadi 'sent' (memulihkan sendiri).
        if (enabled()) {
          await ackToClient(null, statusUntukKlien(dup.status), {
            ref: job.ref,
            client,
            error: dup.status === 'failed' ? 'pengiriman sebelumnya gagal di gateway' : null,
          });
        }
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

  if (ditolakCount > 0) {
    await dbLog(
      'warning',
      'pull_ditolak',
      `${ditolakCount} job dari ${client.name} ditolak (nomor tidak valid) dan sudah dilaporkan 'failed' supaya tidak menggantung di antrean aplikasi`,
      null,
      sessionId
    );
  }

  if (duplikat > 0 && bolehAckUlang) {
    ackUlangThrottle.set(client.id, Date.now());
    await dbLog(
      'warning',
      'pull_duplikat',
      `${duplikat} job dari ${client.name} sudah pernah ditarik: TIDAK dikirim ulang, status aktualnya dilaporkan balik ` +
        '(bila berulang terus, periksa kolom "Path laporan balik" aplikasi itu)',
      null,
      sessionId
    );
  }

  await q(
    `UPDATE wa_clients SET last_pull_at = NOW(), last_pull_status = ?, pulled_count = pulled_count + ?, last_error = NULL WHERE id = ?`,
    [
      `ok:${inserted}${ditolakCount ? ` ditolak:${ditolakCount}` : ''}${duplikat ? ` duplikat:${duplikat}` : ''}`,
      inserted,
      client.id,
    ]
  );
  if (inserted > 0) {
    bus.publish('pull', { client: client.name, inserted });
    await dbLog('info', 'pull_ok', `ambil ${inserted} job dari ${client.name}`, null, sessionId);
  }
  return inserted;
}

/** Kunci siklus tarik: cegah dua penarikan berbarengan (mis. tombol "Tarik sekarang"
 *  ditekan saat loop otomatis sedang jalan). Dua siklus bersamaan menarik job yang
 *  sama sebelum ack selesai -> berisiko pesan terkirim dobel. */
let sedangPull = false;

/** Ambil semua klien aktif lalu tarik job-nya (dipanggil berkala). */
export async function pullAll(sessions) {
  if (sedangPull) {
    console.log('[info] siklus tarik sebelumnya belum selesai — siklus ini dilewati');
    return 0;
  }
  sedangPull = true;
  try {
    return await pullAllDalam(sessions);
  } finally {
    sedangPull = false;
  }
}

async function pullAllDalam(sessions) {
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
