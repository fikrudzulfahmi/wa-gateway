import fs from 'node:fs';
import path from 'node:path';
import axios from 'axios';
import { config, ENGINE_ROOT } from './config.js';
import { q, one, dbLog, getSetting } from './db.js';
import { bus } from './bus.js';
import { normalizePhone } from './phone.js';

const MIME = {
  pdf: 'application/pdf',
  jpg: 'image/jpeg',
  jpeg: 'image/jpeg',
  png: 'image/png',
  webp: 'image/webp',
  gif: 'image/gif',
  doc: 'application/msword',
  docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  xls: 'application/vnd.ms-excel',
  xlsx: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  txt: 'text/plain',
  zip: 'application/zip',
};

const mimeOf = (name = '') => MIME[String(name).split('.').pop()?.toLowerCase()] ?? 'application/octet-stream';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const randDelay = () => {
  const min = Math.min(config.delayMin, config.delayMax);
  const max = Math.max(config.delayMin, config.delayMax);
  return (Math.floor(Math.random() * (max - min + 1)) + min) * 1000;
};

/** Ubah baris outbox menjadi konten yang dimengerti Baileys. */
function buildContent(row) {
  const caption = row.body ?? undefined;
  if (row.type === 'image') {
    if (!row.media_url) throw new Error('media_url kosong untuk tipe image');
    const media = /^https?:\/\//i.test(row.media_url)
      ? { url: row.media_url }
      : fs.readFileSync(path.isAbsolute(row.media_url) ? row.media_url : path.join(ENGINE_ROOT, row.media_url));
    return { image: media, caption };
  }
  if (row.type === 'document') {
    if (!row.media_url) throw new Error('media_url kosong untuk tipe document');
    const fileName = row.filename || path.basename(row.media_url);
    const payload = /^https?:\/\//i.test(row.media_url)
      ? { url: row.media_url }
      : fs.readFileSync(row.media_url);
    return { document: payload, mimetype: mimeOf(fileName), fileName, caption };
  }
  return { text: row.body ?? '' };
}

let busy = false;
let lastQuotaWarn = 0;

/** Ambil satu job dari antrean lalu kirim. Dipanggil berkala. */
async function processOne(sessions) {
  if (busy) return { skipped: 'busy' };
  if ((await getSetting('worker_enabled', '1')) !== '1') return { skipped: 'worker-off' };

  const job = await one(
    `SELECT o.*, s.name AS session_name, s.label AS session_label, s.daily_quota
       FROM wa_outbox o
       JOIN wa_sessions s ON s.id = o.session_id
      WHERE o.status = 'queued'
        AND (o.scheduled_at IS NULL OR o.scheduled_at <= NOW())
      ORDER BY o.priority ASC, o.id ASC
      LIMIT 1`
  );
  if (!job) return { skipped: 'empty' };

  const session = sessions.get(job.session_name);
  if (!session) {
    await q('UPDATE wa_outbox SET last_error = ? WHERE id = ?', ['sesi WA tidak ditemukan', job.id]);
    return { skipped: 'no-session' };
  }
  if (!session.isReady) {
    // Jangan tandai gagal: biarkan mengantre sampai sesi tersambung lagi
    await q('UPDATE wa_outbox SET last_error = ? WHERE id = ?', [`sesi "${job.session_name}" belum tersambung`, job.id]);
    return { skipped: 'session-not-ready' };
  }

  // Kuota harian per sesi
  const left = await session.quotaLeft();
  if (left <= 0) {
    if (Date.now() - lastQuotaWarn > 3600_000) {
      lastQuotaWarn = Date.now();
      await dbLog('warning', 'quota_habis', `kuota harian sesi "${job.session_name}" habis, pengiriman ditunda`, null, job.session_id);
    }
    return { skipped: 'quota' };
  }

  busy = true;
  const to = normalizePhone(job.to_number);
  let msgId = null;
  try {
    await q('UPDATE wa_outbox SET status = "sending", claimed_at = NOW() WHERE id = ?', [job.id]);
    const ins = await q(
      `INSERT INTO wa_messages (outbox_id, session_id, client_id, direction, chat_id, to_number, type, body, status)
       VALUES (?,?,?,'out',?,?,?,?, 'sending')`,
      [job.id, job.session_id, job.client_id ?? null, `${to}@s.whatsapp.net`, to, job.type, job.body ?? null]
    );
    msgId = ins.insertId;

    const content = buildContent(job);
    const sent = await session.sendContent(to, content);
    const waId = sent?.key?.id ?? null;

    await q(
      `UPDATE wa_messages SET status='sent', sent_at=NOW(), wa_message_id=?, error=NULL WHERE id=?`,
      [waId, msgId]
    );
    await q('UPDATE wa_outbox SET status="sent", sent_at=NOW(), last_error=NULL WHERE id = ?', [job.id]);
    await session.bumpSentToday();

    bus.publish('status', { outbox_id: job.id, to, status: 'sent', wa_message_id: waId, at: new Date().toISOString() });
    if (job.client_id) {
      const { ackToClient } = await import('./puller.js');
      await ackToClient(job.id, 'sent', { wa_message_id: waId });
    }
    return { ok: true, id: job.id, to, wa_message_id: waId };
  } catch (err) {
    const emsg = String(err?.message ?? err).slice(0, 250);
    const retryCount = Number(job.retry_count) + 1;
    const giveUp = retryCount > Number(job.max_retry);
    if (msgId) {
      await q('UPDATE wa_messages SET status="failed", error=? WHERE id=?', [emsg, msgId]);
    }
    await q(
      `UPDATE wa_outbox SET status = ?, retry_count = ?, last_error = ? WHERE id = ?`,
      [giveUp ? 'failed' : 'queued', retryCount, emsg, job.id]
    );
    bus.publish('status', { outbox_id: job.id, to, status: giveUp ? 'failed' : 'retrying', error: emsg });
    if (job.client_id && giveUp) {
      const { ackToClient } = await import('./puller.js');
      await ackToClient(job.id, 'failed', { error: emsg });
    }
    await dbLog('error', giveUp ? 'send_failed' : 'send_retry', `ke ${to}: ${emsg}`, null, job.session_id);
    return { error: emsg, id: job.id, retry: retryCount };
  } finally {
    busy = false;
    // Jeda acak antar pesan supaya tidak terlihat seperti spam
    await sleep(randDelay());
  }
}

/** Loop worker: periksa antrean setiap WORKER_TICK detik. */
export function startSender(sessions) {
  let stopped = false;
  const loop = async () => {
    if (stopped) return;
    try {
      await processOne(sessions);
    } catch (e) {
      console.error('[error] worker:', e.message);
    }
    if (!stopped) setTimeout(loop, config.workerTick * 1000);
  };
  setTimeout(loop, 1500);
  return () => {
    stopped = true;
  };
}

export { processOne };
