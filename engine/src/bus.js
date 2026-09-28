import { EventEmitter } from 'node:events';

/**
 * Bus event sederhana untuk melayani SSE ke dashboard
 * (QR berubah, status sesi berubah, pesan terkirim/gagal, dsb).
 */
class Bus extends EventEmitter {
  constructor() {
    super();
    this.setMaxListeners(0);
    this.lastQr = {}; // name -> dataURL terakhir, supaya klien baru langsung dapat QR
  }

  publish(type, data = {}) {
    const payload = { type, at: new Date().toISOString(), ...data };
    if (type === 'qr' && data.name) this.lastQr[data.name] = data.qr;
    this.emit('event', payload);
  }

  subscribe(handler) {
    this.on('event', handler);
    return () => this.off('event', handler);
  }
}

export const bus = new Bus();
