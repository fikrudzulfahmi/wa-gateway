import path from 'node:path';
import fs from 'node:fs';
import makeWASocket, {
  useMultiFileAuthState,
  DisconnectReason,
  fetchLatestBaileysVersion,
  Browsers,
  jidNormalizedUser,
} from '@whiskeysockets/baileys';
import QRCode from 'qrcode';
import pino from 'pino';
import { config } from './config.js';
import { q, one, dbLog } from './db.js';
import { bus } from './bus.js';
import { fromJid, toJid } from './phone.js';

const silent = pino({ level: 'silent' });

/** Peta ack_code WhatsApp -> status internal kita */
export const ACK_TO_STATUS = { 2: 'sent', 3: 'delivered', 4: 'read', 5: 'played' };
export const STATUS_RANK = { failed: -1, queued: 0, sending: 1, sent: 2, delivered: 3, read: 4, played: 5 };

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Satu sesi WhatsApp (satu nomor). Membungkus socket Baileys:
 * QR, status koneksi, auto-reconnect, kirim pesan, catat pesan masuk.
 */
export class WaSession {
  constructor(row) {
    this.id = row.id;
    this.name = row.name;
    this.label = row.label ?? row.name;
    this.status = row.status ?? 'disconnected';
    this.phone = row.phone ?? null;
    this.sock = null;
    this.retry = 0;
    this.reconnectTimer = null;
    this.stopping = false;
    this.qrDataUrl = null;
    this.lastActivity = Date.now();
  }

  get authDir() {
    return path.join(config.sessionsDir, this.name);
  }

  get isReady() {
    return this.status === 'connected' && !!this.sock;
  }

  async refreshFromDb() {
    const row = await one('SELECT * FROM wa_sessions WHERE id = ?', [this.id]);
    if (row) {
      this.status = row.status;
      this.phone = row.phone;
    }
    return row;
  }

  async setStatus(status, extra = {}) {
    this.status = status;
    const sets = ['status = ?', 'last_seen_at = NOW()'];
    const params = [status];
    for (const [k, v] of Object.entries(extra)) {
      sets.push(`${k} = ?`);
      params.push(v);
    }
    params.push(this.name);
    await q(`UPDATE wa_sessions SET ${sets.join(', ')} WHERE name = ?`, params);
    bus.publish('session', { id: this.id, name: this.name, label: this.label, status, phone: extra.phone ?? this.phone });
  }

  async bumpSentToday() {
    await q(
      `UPDATE wa_sessions
         SET sent_today = IF(quota_date = CURDATE(), sent_today + 1, 1),
             quota_date = CURDATE()
       WHERE name = ?`,
      [this.name]
    );
  }

  async quotaLeft() {
    const row = await one('SELECT sent_today, quota_date, daily_quota FROM wa_sessions WHERE name = ?', [this.name]);
    if (!row) return 0;
    const used = row.quota_date && new Date(row.quota_date).toDateString() === new Date().toDateString() ? row.sent_today : 0;
    return Math.max(0, Number(row.daily_quota) - Number(used));
  }

  /** Mulai / sambungkan sesi. Aman dipanggil berkali-kali. */
  async start({ fresh = false } = {}) {
    if (this.sock) return this.status;
    this.stopping = false;

    if (fresh) {
      try {
        fs.rmSync(this.authDir, { recursive: true, force: true });
      } catch { /* abaikan */ }
    }
    fs.mkdirSync(this.authDir, { recursive: true });

    await this.setStatus('connecting');

    const { state, saveCreds } = await useMultiFileAuthState(this.authDir);
    let version;
    try {
      ({ version } = await fetchLatestBaileysVersion());
    } catch {
      version = [2, 3000, 1023223821];
    }

    this.sock = makeWASocket({
      version,
      auth: state,
      logger: silent,
      printQRInTerminal: false,
      browser: Browsers.ubuntu('Chrome'),
      markOnlineOnConnect: false,
      syncFullHistory: false,
      generateHighQualityLinkPreview: false,
      getMessage: async () => undefined,
    });

    this.sock.ev.on('creds.update', saveCreds);
    this.sock.ev.on('connection.update', (u) => this.onConnectionUpdate(u).catch((e) => console.error(e)));
    this.sock.ev.on('messages.update', (u) => this.onMessagesUpdate(u).catch((e) => console.error(e)));
    this.sock.ev.on('messages.upsert', (u) => this.onMessagesUpsert(u).catch((e) => console.error(e)));

    dbLog('info', 'session_start', `sesi "${this.name}" dinyalakan`, null, this.id);
    return this.status;
  }

  async onConnectionUpdate({ connection, lastDisconnect, qr }) {
    if (qr) {
      // Simpan QR (data URL PNG) supaya dashboard bisa langsung menampilkan.
      // last_error dikosongkan: QR baru berarti siklus normal, bukan kegagalan.
      this.qrDataUrl = await QRCode.toDataURL(qr, { margin: 1, width: 340 });
      await q('UPDATE wa_sessions SET qr_string = ?, qr_updated_at = NOW(), status = ?, last_error = NULL WHERE name = ?', [
        this.qrDataUrl,
        'qr',
        this.name,
      ]);
      this.status = 'qr';
      bus.publish('qr', { id: this.id, name: this.name, label: this.label, qr: this.qrDataUrl });
      return;
    }

    if (connection === 'open') {
      this.retry = 0;
      this.qrDataUrl = null;
      const phone = this.sock?.user?.id ? fromJid(jidNormalizedUser(this.sock.user.id)) : null;
      this.phone = phone;
      await q(
        `UPDATE wa_sessions
            SET status='connected', phone=?, qr_string=NULL, connected_at=NOW(),
                last_seen_at=NOW(), last_error=NULL
          WHERE name = ?`,
        [phone, this.name]
      );
      this.status = 'connected';
      bus.publish('session', { id: this.id, name: this.name, label: this.label, status: 'connected', phone });
      await dbLog('info', 'connected', `sesi "${this.name}" tersambung sebagai ${phone ?? '?'}`, null, this.id);
      return;
    }

    if (connection === 'close') {
      const code = lastDisconnect?.error?.output?.statusCode;
      const reason = DisconnectReason[code] ?? String(code ?? 'unknown');
      const errMsg = lastDisconnect?.error?.message ?? reason;
      this.sock = null;

      // Sesi yang BELUM pernah dipindai akan terus-menerus menutup koneksi saat siklus QR
      // habis ("QR refs attempts ended", timedOut). Itu NORMAL, bukan kegagalan - menyimpannya
      // sebagai last_error membuat dashboard menampilkan pesan error palsu tepat saat user
      // hendak memindai QR. Jadi hanya catat error untuk sesi yang sudah pernah tersambung.
      const belumPernahDipindai = !this.phone && code !== DisconnectReason.loggedOut;
      if (belumPernahDipindai) {
        await q('UPDATE wa_sessions SET last_error = NULL WHERE name = ?', [this.name]);
      } else {
        await q('UPDATE wa_sessions SET last_error = ? WHERE name = ?', [String(errMsg).slice(0, 255), this.name]);
      }
      dbLog(code === DisconnectReason.loggedOut ? 'warning' : 'info', 'disconnected', `sesi "${this.name}" terputus (${reason})`, null, this.id);

      if (code === DisconnectReason.loggedOut) {
        // Sesi dicabut dari HP -> folder auth dibuang supaya bisa scan QR baru
        try {
          fs.rmSync(this.authDir, { recursive: true, force: true });
        } catch { /* abaikan */ }
        await q('UPDATE wa_sessions SET status="logged_out", qr_string=NULL, phone=NULL WHERE name = ?', [this.name]);
        this.status = 'logged_out';
        bus.publish('session', { id: this.id, name: this.name, label: this.label, status: 'logged_out' });
        return;
      }

      if (this.stopping) return;
      await this.setStatus('disconnected');

      // Backoff: 3s, 6s, 12s, ... maksimal 60s
      const wait = Math.min(3000 * 2 ** this.retry, 60000);
      this.retry += 1;
      bus.publish('session', { id: this.id, name: this.name, label: this.label, status: 'disconnected', retryIn: Math.round(wait / 1000) });
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = setTimeout(() => {
        this.start().catch((e) => dbLog('error', 'reconnect_failed', e.message, null, this.id));
      }, code === DisconnectReason.restartRequired ? 300 : wait);
    }
  }

  async onMessagesUpsert({ messages, type }) {
    if (type !== 'notify') return;
    for (const m of messages ?? []) {
      if (!m.key || m.key.fromMe) continue;
      const remote = m.key.remoteJid ?? '';
      if (remote.endsWith('@g.us') || remote === 'status@broadcast') continue;
      const from = fromJid(remote);
      const msg = m.message ?? {};
      const body =
        msg.conversation ??
        msg.extendedTextMessage?.text ??
        msg.imageMessage?.caption ??
        msg.videoMessage?.caption ??
        (msg.imageMessage ? '[gambar]' : msg.documentMessage ? '[dokumen]' : '[media]');
      const mtype = msg.imageMessage ? 'image' : msg.documentMessage ? 'document' : 'text';
      try {
        await q(
          `INSERT IGNORE INTO wa_inbound (session_id, wa_message_id, from_number, push_name, type, body)
           VALUES (?,?,?,?,?,?)`,
          [this.id, m.key.id ?? null, from, m.pushName ?? null, mtype, String(body).slice(0, 4000)]
        );
        bus.publish('inbound', { session: this.name, from, body, at: new Date().toISOString() });
      } catch (e) {
        console.error('[warn] simpan pesan masuk gagal:', e.message);
      }
    }
  }

  async onMessagesUpdate(updates) {
    for (const u of updates ?? []) {
      const id = u.key?.id;
      const ack = u.update?.status;
      if (!id || ack === undefined || ack === null) continue;
      const status = ACK_TO_STATUS[ack];
      if (!status) continue;

      const row = await one('SELECT id, outbox_id, client_id, session_id, status, to_number, wa_message_id FROM wa_messages WHERE wa_message_id = ?', [id]);
      if (!row) continue;
      if ((STATUS_RANK[status] ?? 0) <= (STATUS_RANK[row.status] ?? 0)) continue;

      await q(
        `UPDATE wa_messages
            SET status = ?, ack_code = ?,
                delivered_at = IF(? IN ('delivered','read','played') AND delivered_at IS NULL, NOW(), delivered_at),
                read_at      = IF(? IN ('read','played') AND read_at IS NULL, NOW(), read_at)
          WHERE id = ?`,
        [status, ack, status, status, row.id]
      );
      if (row.outbox_id) {
        await q('UPDATE wa_outbox SET status = ? WHERE id = ? AND status NOT IN ("failed","cancelled")', [status, row.outbox_id]);
      }
      bus.publish('status', { outbox_id: row.outbox_id, wa_message_id: id, to: row.to_number, status, at: new Date().toISOString() });

      if (row.client_id) {
        const { ackToClient } = await import('./puller.js');
        await ackToClient(row.outbox_id, status, { wa_message_id: id });
      }
    }
  }

  /** Kirim konten sudah jadi (dipakai worker). */
  async sendContent(to, content) {
    if (!this.isReady) throw new Error('sesi belum tersambung');
    const jid = toJid(to);
    const res = await this.sock.sendMessage(jid, content);
    this.lastActivity = Date.now();
    return res;
  }

  /** Minta ulang daftar kontak/nomor terdaftar WhatsApp (opsional). */
  async checkNumbers(numbers = []) {
    if (!this.isReady) throw new Error('sesi belum tersambung');
    const jids = numbers.map((n) => toJid(n));
    const res = await this.sock.onWhatsApp(...jids);
    return res ?? [];
  }

  async logout() {
    try {
      if (this.sock) await this.sock.logout();
    } catch (e) {
      dbLog('warning', 'logout_error', e.message, null, this.id);
    }
    this.stopping = true;
    clearTimeout(this.reconnectTimer);
    this.sock = null;
    try {
      fs.rmSync(this.authDir, { recursive: true, force: true });
    } catch { /* abaikan */ }
    await q('UPDATE wa_sessions SET status="logged_out", qr_string=NULL, phone=NULL WHERE name = ?', [this.name]);
    this.status = 'logged_out';
    bus.publish('session', { id: this.id, name: this.name, label: this.label, status: 'logged_out' });
    await dbLog('warning', 'logout', `sesi "${this.name}" di-logout, sesi WA dicabut`, null, this.id);
    return true;
  }

  async stop() {
    this.stopping = true;
    clearTimeout(this.reconnectTimer);
    try {
      this.sock?.end?.(undefined);
    } catch { /* abaikan */ }
    this.sock = null;
    await this.setStatus('disconnected').catch(() => {});
  }

  /** Nyalakan ulang bersih (dipakai tombol restart di dashboard). */
  async restart(fresh = false) {
    await this.stop();
    await sleep(500);
    return this.start({ fresh });
  }
}
