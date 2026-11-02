import express from 'express';
import crypto from 'node:crypto';
import path from 'node:path';
import fs from 'node:fs';
import { config, ENGINE_ROOT } from './config.js';
import { q, one, safeInt, getSetting, setSetting, dbLog } from './db.js';
import { bus } from './bus.js';
import { isValidPhone, normalizePhone } from './phone.js';
import { processOne } from './sender.js';
import { pullAll } from './puller.js';
import { WaSession } from './session.js';

const ok = (res, data = {}, extra = {}) => res.json({ ok: true, ...extra, ...data });
const fail = (res, code, message) => res.status(code).json({ ok: false, error: message });

/** Buat ulang objek sesi dari DB (dipakai saat tombol refresh/restart). */
async function reloadSession(sessions, name) {
  const row = await one('SELECT * FROM wa_sessions WHERE name = ?', [name]);
  if (!row) return null;
  const existing = sessions.get(name);
  if (existing) {
    existing.id = row.id;
    existing.label = row.label;
    return existing;
  }
  const s = new WaSession(row);
  sessions.set(name, s);
  return s;
}

export function createApi(sessions) {
  const app = express();
  app.use(express.json({ limit: '8mb' }));
  app.use(express.urlencoded({ extended: true, limit: '8mb' }));

  // Sajikan dashboard web bawaan (tanpa butuh XAMPP/PHP)
  const publicDir = path.join(ENGINE_ROOT, 'public');
  if (fs.existsSync(publicDir)) {
    app.use(express.static(publicDir));
  }

  // CORS terbuka agar bisa dipanggil lewat Cloudflare Tunnel & aplikasi hosting
  app.use((req, res, next) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Token');
    res.setHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, DELETE, OPTIONS');
    if (req.method === 'OPTIONS') return res.sendStatus(204);
    next();
  });

  // ---- autentikasi token ----
  // Bebaskan:
  // - Dashboard web & aset statis (GET /, /index.html)
  // - Health check (/api/health)
  app.use(async (req, res, next) => {
    if (req.method === 'GET' && !req.path.startsWith('/api') && req.path !== '/send' && req.path !== '/device') {
      return next();
    }
    if (req.path === '/api/health') return next();

    const authHeader = req.get('Authorization') || '';
    const token =
      (authHeader.startsWith('Bearer ') ? authHeader.slice(7) : authHeader).trim() ||
      req.get('X-Token') ||
      req.query.token ||
      req.body?.token;

    const expected = (await getSetting('engine_token', config.token)) || config.token;
    if (!token || token !== expected) {
      if (req.path === '/send' || req.path === '/api/send' || req.path === '/device' || req.path === '/api/device') {
        return res.status(401).json({ status: false, reason: 'Token otorisasi tidak valid' });
      }
      return fail(res, 401, 'token tidak valid');
    }
    next();
  });

  // ===================== HEALTH & STATISTIK =====================
  app.get('/api/health', async (req, res) => {
    const rows = await q('SELECT status FROM wa_sessions');
    const pending = await one('SELECT COUNT(*) AS n FROM wa_outbox WHERE status IN ("queued","sending")');
    return ok(res, {
      name: 'WA Gateway Engine',
      time: new Date().toISOString(),
      uptime_sec: Math.round(process.uptime()),
      sessions_total: rows.length,
      sessions_connected: rows.filter((r) => r.status === 'connected').length,
      queue_pending: Number(pending?.n ?? 0),
    });
  });

  // ===================== SESI / STATUS KONEKSI =====================
  app.get('/api/sessions', async (req, res) => {
    const rows = await q(
      `SELECT id, name, label, phone, status, qr_updated_at, last_error, auto_start, daily_quota,
              sent_today, quota_date, connected_at, last_seen_at,
              IF(quota_date = CURDATE(), sent_today, 0) AS sent_today_real
         FROM wa_sessions ORDER BY id`
    );
    return ok(res, {
      data: rows.map((r) => ({
        ...r,
        quota_left: Math.max(0, Number(r.daily_quota) - Number(r.sent_today_real)),
        live_status: sessions.get(r.name)?.status ?? r.status,
        has_socket: !!sessions.get(r.name)?.sock,
      })),
    });
  });

  app.post('/api/sessions', async (req, res) => {
    const name = String(req.body?.name ?? '').trim().toLowerCase().replace(/[^a-z0-9_-]/g, '');
    if (!name) return fail(res, 422, 'nama sesi wajib (huruf/angka/_-)');
    const label = req.body?.label ?? name;
    const dup = await one('SELECT id FROM wa_sessions WHERE name = ?', [name]);
    if (dup) return fail(res, 409, `sesi "${name}" sudah ada`);
    await q('INSERT INTO wa_sessions (name, label, auto_start, daily_quota) VALUES (?,?,?,?)', [
      name,
      label,
      1,
      safeInt(req.body?.daily_quota, 300, 1, 100000),
    ]);
    const row = await one('SELECT * FROM wa_sessions WHERE name = ?', [name]);
    const s = new WaSession(row);
    sessions.set(name, s);
    s.start().catch((e) => console.error(e));
    await dbLog('info', 'session_created', `sesi "${name}" dibuat`);
    return ok(res, { data: { id: row.id, name } });
  });

  app.post('/api/sessions/:name/start', async (req, res) => {
    const s = (await reloadSession(sessions, req.params.name));
    if (!s) return fail(res, 404, 'sesi tidak ditemukan');
    await s.start({ fresh: !!req.body?.fresh });
    return ok(res, { data: { name: s.name, status: s.status } });
  });

  app.post('/api/sessions/:name/restart', async (req, res) => {
    const s = (await reloadSession(sessions, req.params.name));
    if (!s) return fail(res, 404, 'sesi tidak ditemukan');
    await s.restart(!!req.body?.fresh);
    return ok(res, { data: { name: s.name, status: s.status } });
  });

  app.post('/api/sessions/:name/logout', async (req, res) => {
    const s = sessions.get(req.params.name);
    if (!s) return fail(res, 404, 'sesi tidak ditemukan');
    await s.logout();
    return ok(res, { data: { name: s.name, status: s.status } });
  });

  app.get('/api/sessions/:name/qr', async (req, res) => {
    const row = await one('SELECT name, label, status, phone, qr_string, qr_updated_at FROM wa_sessions WHERE name = ?', [
      req.params.name,
    ]);
    if (!row) return fail(res, 404, 'sesi tidak ditemukan');
    const live = sessions.get(row.name);
    return ok(res, {
      data: {
        name: row.name,
        label: row.label,
        status: live?.status ?? row.status,
        phone: row.phone,
        qr: live?.qrDataUrl ?? row.qr_string ?? null,
        updated_at: row.qr_updated_at,
      },
    });
  });

  // ===================== FONNTE-COMPATIBLE ENDPOINTS =====================
  // Format drop-in: POST /send atau POST /api/send
  // Header: Authorization: TOKEN (atau X-Token / query token)
  // Body: target (nomor hp), message (teks), url (media opsional), filename, schedule, delay
  const handleFonnteSend = async (req, res) => {
    const b = req.body ?? {};
    const rawTarget = b.target ?? b.to ?? b.to_number ?? b.phone;
    if (!rawTarget) {
      return res.status(422).json({ status: false, reason: 'target wajib diisi (nomor tujuan)' });
    }

    const message = String(b.message ?? b.body ?? b.text ?? '').trim();
    const mediaUrl = b.url ?? b.media_url ?? null;
    const filename = b.filename ?? null;

    let type = 'text';
    if (mediaUrl) {
      if (b.type && ['image', 'document'].includes(b.type)) {
        type = b.type;
      } else {
        const ext = String(mediaUrl).split('.').pop()?.toLowerCase();
        if (['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'zip'].includes(ext)) {
          type = 'document';
        } else {
          type = 'image';
        }
      }
    }

    if (type === 'text' && !message) {
      return res.status(422).json({ status: false, reason: 'isi pesan (message) tidak boleh kosong' });
    }

    let sessionName = b.session ?? b.session_name;
    if (!sessionName) {
      const first = await one('SELECT name FROM wa_sessions WHERE status = "connected" ORDER BY id LIMIT 1');
      sessionName = first?.name ?? (await one('SELECT name FROM wa_sessions ORDER BY id LIMIT 1'))?.name;
    }
    const s = sessionName ? await reloadSession(sessions, sessionName) : null;
    if (!s) {
      return res.status(503).json({ status: false, reason: 'sesi WhatsApp tidak ditemukan atau belum aktif' });
    }

    // Dukung target tunggal ("0812xxx"), comma-separated ("0812xxx,0813xxx"), atau array
    const targetList = (Array.isArray(rawTarget) ? rawTarget : String(rawTarget).split(','))
      .map((t) => normalizePhone(t, b.countryCode ?? '62'))
      .filter((t) => isValidPhone(t));

    if (targetList.length === 0) {
      return res.status(422).json({ status: false, reason: 'tidak ada nomor tujuan (target) yang valid' });
    }

    const insertedIds = [];
    const validTargets = [];

    for (const to of targetList) {
      const ins = await q(
        `INSERT INTO wa_outbox (session_id, client_id, source, ref, to_number, type, body, media_url, filename, scheduled_at, priority)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)`,
        [
          s.id,
          null,
          'api',
          b.ref ?? null,
          to,
          type,
          message || null,
          mediaUrl,
          filename,
          b.schedule ? new Date(b.schedule) : null,
          safeInt(b.priority, 5, 1, 9),
        ]
      );
      insertedIds.push(ins.insertId);
      validTargets.push(to);
      bus.publish('queued', { id: ins.insertId, to, at: new Date().toISOString() });
    }

    if (b.now !== false) {
      processOne(sessions).catch(() => {});
    }

    // Jika mode wait aktif dan hanya 1 nomor: tunggu sampai terkirim (maks 20 detik)
    if (b.wait && insertedIds.length === 1) {
      const outboxId = insertedIds[0];
      const started = Date.now();
      while (Date.now() - started < 20000) {
        const row = await one('SELECT status, last_error, sent_at FROM wa_outbox WHERE id = ?', [outboxId]);
        if (['sent', 'delivered', 'read', 'failed'].includes(row?.status)) {
          return res.json({
            status: row.status !== 'failed',
            target: validTargets[0],
            id: outboxId,
            process: row.status,
            detail: row.status === 'failed' ? (row.last_error || 'pengiriman gagal') : 'pesan berhasil diproses',
          });
        }
        await new Promise((r) => setTimeout(r, 700));
      }
    }

    return res.json({
      status: true,
      target: validTargets.length === 1 ? validTargets[0] : validTargets,
      id: insertedIds.length === 1 ? insertedIds[0] : insertedIds,
      process: 'processing',
      detail: 'pesan masuk antrean',
    });
  };

  app.post('/send', handleFonnteSend);
  app.post('/api/send', handleFonnteSend);

  // Status Device / Sesi (Fonnte Compatible)
  const handleFonnteDevice = async (req, res) => {
    const s = [...sessions.values()][0];
    const isConn = s && s.isReady;
    return res.json({
      status: isConn,
      device: s?.phone ?? null,
      name: s?.name ?? 'default',
      device_status: isConn ? 'connect' : (s?.status ?? 'disconnect'),
      reason: isConn ? undefined : (s?.status === 'qr' ? 'scan qr diperlukan' : 'sesi belum tersambung'),
    });
  };
  app.get('/device', handleFonnteDevice);
  app.get('/api/device', handleFonnteDevice);

  // ===================== KIRIM PESAN (LEGACY/DASHBOARD) =====================
  app.post('/api/messages/send', async (req, res) => {
    const b = req.body ?? {};
    const to = normalizePhone(b.to ?? b.to_number ?? b.phone ?? b.target);
    if (!isValidPhone(to)) return fail(res, 422, 'nomor tujuan tidak valid');
    const type = ['text', 'image', 'document'].includes(b.type) ? b.type : 'text';
    const bodyText = b.body ?? b.message ?? b.text ?? '';
    if (type === 'text' && !String(bodyText).trim()) return fail(res, 422, 'isi pesan kosong');
    if (type !== 'text' && !b.media_url && !b.url) return fail(res, 422, 'media_url wajib untuk tipe ' + type);

    let sessionName = b.session ?? b.session_name;
    if (!sessionName) {
      const first = await one('SELECT name FROM wa_sessions WHERE status = "connected" ORDER BY id LIMIT 1');
      sessionName = first?.name ?? (await one('SELECT name FROM wa_sessions ORDER BY id LIMIT 1'))?.name;
    }
    const s = sessionName ? await reloadSession(sessions, sessionName) : null;
    if (!s) return fail(res, 404, 'sesi WA tidak ditemukan');

    const ins = await q(
      `INSERT INTO wa_outbox (session_id, client_id, source, ref, to_number, type, body, media_url, filename, scheduled_at, priority)
       VALUES (?,?,?,?,?,?,?,?,?,?,?)`,
      [
        s.id,
        b.client_id ?? null,
        b.client_id ? 'api' : 'dashboard',
        b.ref ?? null,
        to,
        type,
        bodyText || null,
        b.media_url ?? b.url ?? null,
        b.filename ?? null,
        b.scheduled_at ?? b.schedule ?? null,
        safeInt(b.priority, 5, 1, 9),
      ]
    );
    bus.publish('queued', { id: ins.insertId, to, at: new Date().toISOString() });

    if (b.now !== false) processOne(sessions).catch(() => {});

    if (b.wait) {
      // Tunggu hasil maksimal ~20 detik (dipakai tombol "Kirim & Lihat Status")
      const started = Date.now();
      while (Date.now() - started < 20000) {
        const row = await one('SELECT status, last_error, sent_at FROM wa_outbox WHERE id = ?', [ins.insertId]);
        if (['sent', 'delivered', 'read', 'failed'].includes(row?.status)) {
          return ok(res, { data: { id: ins.insertId, ...row } });
        }
        await new Promise((r) => setTimeout(r, 700));
      }
      const row = await one('SELECT status, last_error, sent_at FROM wa_outbox WHERE id = ?', [ins.insertId]);
      return ok(res, { data: { id: ins.insertId, ...row, note: 'masih dalam antrean' } });
    }
    return ok(res, { data: { id: ins.insertId, status: 'queued' } }, { message: 'pesan masuk antrean' });
  });

  // ===================== REKAP PESAN =====================
  app.get('/api/messages', async (req, res) => {
    const limit = safeInt(req.query.limit, 25, 1, 200);
    const page = safeInt(req.query.page, 1, 1, 10000);
    const offset = (page - 1) * limit;
    const where = [];
    const params = [];
    if (req.query.direction) { where.push('m.direction = ?'); params.push(req.query.direction); }
    if (req.query.status) {
      const st = String(req.query.status).split(',').filter((x) => /^(queued|sending|sent|delivered|read|played|failed)$/.test(x));
      if (st.length) { where.push(`m.status IN (${st.map(() => '?').join(',')})`); params.push(...st); }
    }
    if (req.query.client_id) { where.push('m.client_id = ?'); params.push(safeInt(req.query.client_id, 0, 0, 1e9)); }
    if (req.query.session_id) { where.push('m.session_id = ?'); params.push(safeInt(req.query.session_id, 0, 0, 1e9)); }
    if (req.query.number) { where.push('m.to_number LIKE ?'); params.push(`%${normalizePhone(req.query.number)}%`); }
    if (req.query.q) {
      where.push('(m.body LIKE ? OR m.to_number LIKE ?)');
      params.push(`%${req.query.q}%`, `%${req.query.q}%`);
    }
    if (req.query.from) { where.push('m.created_at >= ?'); params.push(`${req.query.from} 00:00:00`); }
    if (req.query.to) { where.push('m.created_at <= ?'); params.push(`${req.query.to} 23:59:59`); }
    const sql = `WHERE ${where.length ? where.join(' AND ') : '1=1'}`;

    const rows = await q(
      `SELECT m.*, c.name AS aplikasi, s.label AS sesi
         FROM wa_messages m
         LEFT JOIN wa_clients c ON c.id = m.client_id
         LEFT JOIN wa_sessions s ON s.id = m.session_id
         ${sql} ORDER BY m.id DESC LIMIT ${limit} OFFSET ${offset}`,
      params
    );
    const total = await one(
      `SELECT COUNT(*) AS n FROM wa_messages m ${sql}`,
      params
    );
    return ok(res, { data: rows, meta: { page, limit, total: Number(total?.n ?? 0) } });
  });

  app.get('/api/messages/stats', async (req, res) => {
    const from = req.query.from ?? new Date().toISOString().slice(0, 10);
    const to = req.query.to ?? from;
    const rows = await q(
      `SELECT DATE(created_at) AS tanggal,
              COUNT(*) AS total,
              SUM(status IN ('sent','delivered','read','played')) AS terkirim,
              SUM(status IN ('delivered','read','played')) AS sampai,
              SUM(status IN ('read','played')) AS dibaca,
              SUM(status = 'failed') AS gagal,
              SUM(status IN ('queued','sending')) AS dalam_proses
         FROM wa_messages
        WHERE direction = 'out' AND created_at BETWEEN ? AND ?
        GROUP BY DATE(created_at) ORDER BY tanggal DESC`,
      [`${from} 00:00:00`, `${to} 23:59:59`]
    );
    const totals = await one(
      `SELECT COUNT(*) AS total,
              SUM(status IN ('sent','delivered','read','played')) AS terkirim,
              SUM(status IN ('delivered','read','played')) AS sampai,
              SUM(status IN ('read','played')) AS dibaca,
              SUM(status = 'failed') AS gagal
         FROM wa_messages WHERE direction='out' AND created_at BETWEEN ? AND ?`,
      [`${from} 00:00:00`, `${to} 23:59:59`]
    );
    const byApp = await q(
      `SELECT COALESCE(c.name,'Manual/Excel') AS aplikasi, COUNT(*) AS total,
              SUM(m.status IN ('sent','delivered','read','played')) AS terkirim,
              SUM(m.status = 'failed') AS gagal
         FROM wa_messages m LEFT JOIN wa_clients c ON c.id = m.client_id
        WHERE m.direction='out' AND m.created_at BETWEEN ? AND ?
        GROUP BY aplikasi ORDER BY total DESC`,
      [`${from} 00:00:00`, `${to} 23:59:59`]
    );
    return ok(res, { data: { harian: rows, total: totals, per_aplikasi: byApp } });
  });

  app.get('/api/outbox', async (req, res) => {
    const limit = safeInt(req.query.limit, 25, 1, 200);
    const rows = await q(
      `SELECT o.*, c.name AS aplikasi, s.label AS sesi FROM wa_outbox o
         LEFT JOIN wa_clients c ON c.id = o.client_id
         LEFT JOIN wa_sessions s ON s.id = o.session_id
        ORDER BY o.id DESC LIMIT ${limit}`
    );
    return ok(res, { data: rows });
  });

  app.post('/api/outbox/:id/retry', async (req, res) => {
    const id = safeInt(req.params.id, 0);
    await q('UPDATE wa_outbox SET status="queued", retry_count=0, last_error=NULL WHERE id = ? AND status IN ("failed","cancelled")', [id]);
    processOne(sessions).catch(() => {});
    return ok(res, {}, { message: 'pesan dikembalikan ke antrean' });
  });

  app.post('/api/outbox/:id/cancel', async (req, res) => {
    const id = safeInt(req.params.id, 0);
    await q('UPDATE wa_outbox SET status="cancelled" WHERE id = ? AND status = "queued"', [id]);
    return ok(res, {}, { message: 'pesan dibatalkan' });
  });

  app.get('/api/inbound', async (req, res) => {
    const limit = safeInt(req.query.limit, 25, 1, 200);
    const rows = await q(
      `SELECT i.*, s.label AS sesi FROM wa_inbound i LEFT JOIN wa_sessions s ON s.id = i.session_id
        ORDER BY i.id DESC LIMIT ${limit}`
    );
    return ok(res, { data: rows });
  });

  app.get('/api/logs', async (req, res) => {
    const limit = safeInt(req.query.limit, 50, 1, 500);
    const rows = await q('SELECT * FROM wa_logs ORDER BY id DESC LIMIT ' + limit);
    return ok(res, { data: rows });
  });

  // ===================== KLIEN HOSTING (MODE PULL) =====================
  app.get('/api/clients', async (req, res) => {
    const rows = await q(
      `SELECT c.*, s.label AS sesi FROM wa_clients c LEFT JOIN wa_sessions s ON s.id = c.session_id ORDER BY c.id`
    );
    return ok(res, { data: rows });
  });

  app.post('/api/clients', async (req, res) => {
    const b = req.body ?? {};
    if (!b.name || !b.base_url) return fail(res, 422, 'name dan base_url wajib');
    const token = b.token || crypto.randomBytes(24).toString('hex');
    if (b.id) {
      await q(
        `UPDATE wa_clients SET name=?, base_url=?, jobs_path=?, ack_path=?, token=?, session_id=?, poll_interval=?, batch_size=?, is_active=? WHERE id=?`,
        [b.name, b.base_url, b.jobs_path ?? '/api/wa-gateway/jobs', b.ack_path ?? '/api/wa-gateway/jobs/ack', token,
          b.session_id ?? null, safeInt(b.poll_interval, 10, 5, 3600), safeInt(b.batch_size, 20, 1, 100), b.is_active ? 1 : 0, b.id]
      );
      return ok(res, { data: { id: b.id, token } });
    }
    const ins = await q(
      `INSERT INTO wa_clients (name, base_url, jobs_path, ack_path, token, session_id, poll_interval, batch_size, is_active)
       VALUES (?,?,?,?,?,?,?,?,?)`,
      [b.name, b.base_url, b.jobs_path ?? '/api/wa-gateway/jobs', b.ack_path ?? '/api/wa-gateway/jobs/ack', token,
        b.session_id ?? null, safeInt(b.poll_interval, 10, 5, 3600), safeInt(b.batch_size, 20, 1, 100), b.is_active === undefined ? 1 : b.is_active ? 1 : 0]
    );
    return ok(res, { data: { id: ins.insertId, token } });
  });

  app.delete('/api/clients/:id', async (req, res) => {
    await q('DELETE FROM wa_clients WHERE id = ?', [safeInt(req.params.id, 0, 0, 1e9)]);
    return ok(res);
  });

  app.post('/api/pull-now', async (req, res) => {
    const n = await pullAll(sessions);
    return ok(res, { data: { inserted: n } });
  });

  // ===================== LAIN-LAIN =====================
  app.post('/api/check-numbers', async (req, res) => {
    const name = req.body?.session;
    const s = name ? await reloadSession(sessions, name) : [...sessions.values()][0];
    if (!s) return fail(res, 404, 'sesi tidak ditemukan');
    const numbers = (req.body?.numbers ?? []).filter(isValidPhone);
    const result = await s.checkNumbers(numbers).catch((e) => ({ error: e.message }));
    return ok(res, { data: result });
  });

  app.get('/api/settings', async (req, res) => {
    const rows = await q('SELECT `key`, `value` FROM wa_settings');
    return ok(res, { data: Object.fromEntries(rows.map((r) => [r.key, r.value])) });
  });

  app.post('/api/settings', async (req, res) => {
    const b = req.body ?? {};
    for (const [k, v] of Object.entries(b)) await setSetting(k, v);
    return ok(res);
  });

  // ===================== SSE: realtime QR & status =====================
  app.get('/api/events', async (req, res) => {
    res.writeHead(200, {
      'Content-Type': 'text/event-stream',
      'Cache-Control': 'no-cache',
      Connection: 'keep-alive',
      'X-Accel-Buffering': 'no',
    });
    const send = (type, data) => res.write(`event: ${type}\ndata: ${JSON.stringify(data)}\n\n`);
    send('hello', { at: new Date().toISOString(), sessions: [...sessions.values()].map((s) => ({ name: s.name, status: s.status, phone: s.phone })) });
    for (const [name, s] of sessions) {
      if (s.qrDataUrl) send('qr', { name, qr: s.qrDataUrl });
    }
    const unsub = bus.subscribe((e) => send(e.type ?? 'event', e));
    const hb = setInterval(() => res.write(': ping\n\n'), 25000);
    req.on('close', () => {
      clearInterval(hb);
      unsub();
      res.end();
    });
  });

  app.use((req, res) => fail(res, 404, 'endpoint tidak ada'));
  app.use((err, req, res, next) => {
    console.error('[error] api:', err.message);
    fail(res, 500, err.message);
  });

  return app;
}
