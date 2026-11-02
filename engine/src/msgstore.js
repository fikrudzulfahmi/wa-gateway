/**
 * Penyimpan pesan terakhir (in-memory) untuk memenuhi `getMessage` milik Baileys.
 *
 * MENGAPA INI PENTING
 * Saat penerima gagal mendekripsi sebuah pesan (sesi Signal belum sinkron, kunci
 * berganti, dsb.), WhatsApp mengirim "retry receipt" ke pengirim. Baileys lalu
 * memanggil `getMessage(key)` untuk mengambil isi ASLI pesan, membuat ulang sesi,
 * dan mengirimkannya kembali. Bila `getMessage` mengembalikan `undefined`, retry
 * tidak pernah berhasil dan pesan terus tampil di HP penerima sebagai:
 *
 *   "Menunggu pesan ini. Ini mungkin membutuhkan waktu beberapa saat."
 *
 * Cache ini menyimpan pesan yang BARU SAJA dikirim/diterima (beberapa jam terakhir)
 * supaya retry bisa dilayani tanpa perlu store persisten.
 */

const MAKS = 500; // cukup untuk lonjakan antrean
const UMUR_MS = 6 * 60 * 60 * 1000; // 6 jam

const peta = new Map(); // id -> { message, ts }

function kunci(key) {
  return key?.id ? String(key.id) : null;
}

/** Simpan WAMessage (hasil sendMessage atau messages.upsert). */
export function simpanPesan(msg) {
  const id = kunci(msg?.key);
  if (!id || !msg?.message) return;
  peta.set(id, { message: msg.message, ts: Date.now() });
  // Buang entri tertua bila melewati batas (Map menjaga urutan sisip).
  while (peta.size > MAKS) {
    const tertua = peta.keys().next().value;
    peta.delete(tertua);
  }
}

/** Callback `getMessage` Baileys: kembalikan IMessage asli atau undefined. */
export async function ambilPesan(key) {
  const id = kunci(key);
  if (!id) return undefined;
  const rec = peta.get(id);
  if (rec) {
    if (Date.now() - rec.ts <= UMUR_MS) {
      return rec.message;
    }
    peta.delete(id);
  }

  // Fallback: ambil dari tabel wa_messages bila pesan terbuang dari RAM / setelah restart
  try {
    const { one } = await import('./db.js');
    const row = await one('SELECT type, body FROM wa_messages WHERE wa_message_id = ? LIMIT 1', [id]);
    if (row) {
      if (row.type === 'text' || !row.type) {
        return { conversation: row.body ?? '' };
      }
      if (row.body) {
        return { conversation: row.body };
      }
    }
  } catch {
    // Abaikan jika DB tidak dapat diakses atau di lingkungan uji
  }

  return undefined;
}

/** Cache penghitung retry sederhana (antarmuka CacheStore Baileys). */
export function buatCacheRetry() {
  const m = new Map(); // key -> { val, ts }
  const TTL_MS = 60 * 60 * 1000; // 1 jam sesuai standar DEFAULT_CACHE_TTLS.MSG_RETRY
  return {
    get: (k) => {
      const rec = m.get(k);
      if (!rec) return undefined;
      if (Date.now() - rec.ts > TTL_MS) {
        m.delete(k);
        return undefined;
      }
      return rec.val;
    },
    set: (k, v) => {
      m.set(k, { val: v, ts: Date.now() });
    },
    del: (k) => {
      m.delete(k);
    },
    flushAll: () => m.clear(),
  };
}

/** Untuk diagnostik/uji. */
export function jumlahPesanTersimpan() {
  return peta.size;
}
