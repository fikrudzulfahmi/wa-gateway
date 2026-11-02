// Uji parsing format Fonnte untuk endpoint /send
// Jalankan: node --test engine/tests/fonnte_endpoint.test.mjs
import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizePhone, isValidPhone } from '../src/phone.js';

test('normalisasi berbagai variasi nomor target Fonnte', () => {
  assert.equal(normalizePhone('08123456789'), '628123456789');
  assert.equal(normalizePhone('+628123456789'), '628123456789');
  assert.equal(normalizePhone('628123456789'), '628123456789');
  assert.equal(normalizePhone('8123456789'), '628123456789');
  assert.equal(normalizePhone('0812-3456-789'), '628123456789');
  assert.equal(isValidPhone('628123456789'), true);
});

test('ekstraksi multi-target dari comma-separated string', () => {
  const raw = '081234567890, 081298765432, 628111222333';
  const list = raw.split(',').map((t) => normalizePhone(t.trim())).filter(isValidPhone);
  assert.deepEqual(list, [
    '6281234567890',
    '6281298765432',
    '628111222333',
  ]);
});
