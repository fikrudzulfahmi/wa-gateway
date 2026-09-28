/* Dashboard WA Gateway - pembaruan realtime (polling ringan ke PHP -> engine) */
(function () {
  const page = (window.WA_CONFIG && window.WA_CONFIG.page) || '';

  // Jam di topbar
  const clock = document.getElementById('clock');
  if (clock) {
    setInterval(() => {
      const d = new Date();
      const p = (n) => String(n).padStart(2, '0');
      clock.textContent = `${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`;
    }, 1000);
  }

  // Tampilkan field lampiran hanya untuk gambar/dokumen
  const typeSel = document.getElementById('f-type');
  const mediaField = document.querySelector('.media-field');
  if (typeSel && mediaField) {
    const sync = () => mediaField.classList.toggle('hide', typeSel.value === 'text');
    typeSel.addEventListener('change', sync);
    sync();
  }

  const get = (url) => fetch(url, { headers: { 'X-Requested-With': 'fetch' } }).then((r) => r.json()).catch(() => null);

  /* ---------------- Dashboard: status sesi + QR ---------------- */
  if (page === 'dashboard') {
    const statusLabel = {
      connected: ['ok', 'Tersambung'], connecting: ['warn', 'Menyambung'],
      qr: ['warn', 'Perlu Scan QR'], disconnected: ['off', 'Terputus'], logged_out: ['err', 'Sesi Dicabut'],
    };

    async function refreshStatus() {
      const d = await get('index.php?page=api&action=status');
      if (!d || !d.ok) return;

      // kartu statistik
      const set = (id, v) => { const el = document.getElementById(id); if (el && v !== undefined && v !== null) el.textContent = v; };
      set('st-total', d.today?.total);
      set('st-terkirim', d.today?.terkirim);
      set('st-sampai', d.today?.sampai);
      set('st-dibaca', d.today?.dibaca);
      set('st-gagal', d.today?.gagal);
      set('st-antrean', d.queue);

      const navQ = document.getElementById('nav-queue');
      if (navQ) { navQ.textContent = d.queue; navQ.classList.toggle('hide', !d.queue); }

      // badge status tiap sesi
      (d.sessions || []).forEach((s) => {
        const slot = document.querySelector(`.badge-slot[data-status="${s.name}"]`);
        if (slot) {
          const [cls, label] = statusLabel[s.status] || ['off', s.status];
          slot.innerHTML = `<span class="badge ${cls}">${label}</span>`;
        }
        const row = document.querySelector(`.session-row[data-session="${s.name}"]`);
        if (row) {
          const ph = row.querySelector('.s-phone');
          if (ph) ph.textContent = s.phone ? s.phone.replace(/^62/, '0') : 'belum tersambung';
          const q = row.querySelector('.s-quota');
          if (q) q.textContent = `${s.sent_today_real ?? 0}/${s.daily_quota}`;
        }
      });

      // panel QR hanya bermakna saat status qr
      const qrSession = (d.sessions || []).find((s) => s.status === 'qr') || (d.sessions || [])[0];
      if (qrSession && qrSession.status !== 'qr') {
        const box = document.getElementById('qr-box');
        if (box) box.innerHTML = `<div class="qr-empty">✔ Sesi <b>${qrSession.label || qrSession.name}</b> sudah tersambung${qrSession.phone ? ' (' + qrSession.phone.replace(/^62/, '0') + ')' : ''}. QR tidak diperlukan.</div>`;
        const t = document.getElementById('qr-time');
        if (t) t.textContent = 'tidak perlu QR';
      }
    }

    async function refreshQr() {
      const box = document.getElementById('qr-box');
      if (!box) return;
      const d = await get('index.php?page=api&action=qr&name=utama');
      if (!d || !d.ok) { box.innerHTML = '<div class="qr-empty">Tidak bisa memuat QR.</div>'; return; }
      const s = d.data || {};
      if (s.qr && s.status === 'qr') {
        box.innerHTML = `<img src="${s.qr}" alt="QR WhatsApp">`;
        const t = document.getElementById('qr-time');
        if (t) t.textContent = 'diperbarui ' + new Date().toLocaleTimeString('id-ID');
      }
    }

    refreshStatus();
    refreshQr();
    setInterval(refreshStatus, 5000);
    setInterval(refreshQr, 3000);

    const btn = document.getElementById('btn-refresh');
    if (btn) btn.addEventListener('click', () => { refreshStatus(); refreshQr(); });
  }

  /* ---------------- Rekap: perbarui tabel diam-diam setiap 10 dtk ---------------- */
  if (page === 'rekap') {
    setInterval(async () => {
      const btn = document.querySelector('.filterbar button');
      if (btn) btn.dataset.pulse = '1';
      // cukup segarkan angka pada judul panel agar tidak mengganggu pengetikan filter
      const d = await get('index.php?page=api&action=status');
      if (d && d.ok) {
        const navQ = document.getElementById('nav-queue');
        if (navQ) { navQ.textContent = d.queue; navQ.classList.toggle('hide', !d.queue); }
      }
    }, 10000);
  }
})();
