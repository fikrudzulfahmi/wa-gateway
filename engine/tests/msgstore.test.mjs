// Uji message store untuk `getMessage` Baileys (retry receipt).
// Jalankan: node --test engine/tests/msgstore.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { simpanPesan, ambilPesan, buatCacheRetry, jumlahPesanTersimpan } from '../src/msgstore.js';

test('pesan yang baru dikirim bisa diambil ulang untuk retry', async () => {
  const key = { id: '3EB0UJI001', remoteJid: '628123456789@s.whatsapp.net', fromMe: true };
  simpanPesan({ key, message: { conversation: 'Halo dari gateway' } });

  // Retry receipt memanggil getMessage({ ...key, id }) — harus mengembalikan IMessage asli.
  const isi = await ambilPesan({ id: '3EB0UJI001', remoteJid: '628123456789@s.whatsapp.net', fromMe: true });
  assert.deepEqual(isi, { conversation: 'Halo dari gateway' });
});

test('id yang tidak dikenal mengembalikan undefined (bukan melempar)', async () => {
  assert.equal(await ambilPesan({ id: 'TIDAK-ADA-999' }), undefined);
  assert.equal(await ambilPesan({}), undefined);
  assert.equal(await ambilPesan(null), undefined);
});

test('pesan tanpa isi tidak disimpan', () => {
  const sebelum = jumlahPesanTersimpan();
  simpanPesan({ key: { id: 'KOSONG1' } });
  simpanPesan({ key: null, message: { conversation: 'x' } });
  assert.equal(jumlahPesanTersimpan(), sebelum);
});

test('cache retry memenuhi antarmuka CacheStore', () => {
  const c = buatCacheRetry();
  c.set('3EB0X:628@s.whatsapp.net', 2);
  assert.equal(c.get('3EB0X:628@s.whatsapp.net'), 2);
  c.del('3EB0X:628@s.whatsapp.net');
  assert.equal(c.get('3EB0X:628@s.whatsapp.net'), undefined);
  c.set('a', 1);
  c.flushAll();
  assert.equal(c.get('a'), undefined);
});
