/**
 * Normalisasi nomor telepon Indonesia ke format WhatsApp (62xxxxxxxxxx).
 * Menerima: 0812xxxx, +62812xxxx, 62812xxxx, 812xxxx, 0812-xxxx-xxxx
 */
export function normalizePhone(raw, cc = '62') {
  let s = String(raw ?? '').replace(/[^\d+]/g, '').replace(/^\+/, '');
  if (!s) return '';
  if (s.startsWith('0')) s = cc + s.slice(1);
  else if (s.startsWith('8')) s = cc + s;
  else if (!s.startsWith(cc)) s = cc + s.replace(/^0+/, '');
  return s;
}

export function isValidPhone(raw) {
  const n = normalizePhone(raw);
  return /^\d{9,15}$/.test(n);
}

export function toJid(raw) {
  return `${normalizePhone(raw)}@s.whatsapp.net`;
}

export function fromJid(jid) {
  return String(jid || '').split('@')[0].split(':')[0];
}
