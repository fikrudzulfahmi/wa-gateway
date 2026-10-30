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
  if (!rec) return undefined;
  if (Date.now() - rec.ts > UMUR_MS) {
    peta.delete(id);
    return undefined;
  }
  return rec.message;
}

/** Cache penghitung retry sederhana (antarmuka CacheStore Baileys). */
export function buatCacheRetry() {
  const m = new Map();
  return {
    get: (k) => m.get(k),
    set: (k, v) => {
      m.set(k, v);
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
