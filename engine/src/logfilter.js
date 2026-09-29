/**
 * Penyaring kebisingan console.
 *
 * LATAR: library `libsignal` (dipakai Baileys) mencetak langsung dengan `console.error`
 * / `console.warn` - sehingga `logger: silent` milik Baileys TIDAK menahannya. Yang
 * muncul bertubi-tubi adalah:
 *
 *   Failed to decrypt message with any known session...
 *   Session error:Error: Bad MAC Error: Bad MAC
 *
 * Artinya: ada pesan MASUK yang tidak bisa didekripsi. Penyebab umum dan tidak
 * berbahaya: pesan dikirim saat sesi baru saja ditautkan/belum sinkron. Ini bukan
 * tanda gateway rusak dan tidak mempengaruhi pengiriman keluar.
 *
 * Yang kita lakukan: redam baris-baris itu supaya jendela engine tetap terbaca,
 * TETAPI tetap dihitung dan diringkas maksimal sekali per menit, supaya tidak buta
 * kalau jumlahnya melonjak (mis. karena dua engine memakai sesi yang sama).
 *
 * Cara mematikan saringan ini: set WA_LOG_NOISE=off pada engine/.env.
 */

const POLA_NOISE = new RegExp(
  [
    'Bad MAC',
    'Failed to decrypt',
    'Session error',
    'Closing (open|stale) session',
    'Decrypted message with closed session',
    'Unhandled bucket type',
    'Expected pubkey of length',
    'V1 session storage migration error',
    'SessionEntry',
  ].join('|'),
  'i'
);

const asli = {
  error: console.error.bind(console),
  warn: console.warn.bind(console),
};

let jumlah = 0;
let terakhirLapor = 0;
const JEDA_LAPOR_MS = 60_000;

function saring(level, args) {
  const teks = args
    .map((a) => (typeof a === 'string' ? a : a && a.message ? String(a.message) : ''))
    .join(' ');

  if (!POLA_NOISE.test(teks)) {
    asli[level](...args);
    return;
  }

  jumlah += 1;
  const sekarang = Date.now();
  if (sekarang - terakhirLapor >= JEDA_LAPOR_MS) {
    terakhirLapor = sekarang;
    asli.warn(
      `[redam] ${jumlah} baris kebisingan libsignal disembunyikan (pesan masuk gagal didekripsi - normal, ` +
        'tidak mempengaruhi pengiriman). Jika jumlahnya melonjak terus, periksa jangan-jangan ada DUA engine memakai sesi WA yang sama.'
    );
  }
}

export function statistikRedam() {
  return jumlah;
}

if (String(process.env.WA_LOG_NOISE ?? 'on').toLowerCase() !== 'off') {
  console.error = (...a) => saring('error', a);
  console.warn = (...a) => saring('warn', a);
}
