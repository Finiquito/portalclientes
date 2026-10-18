// Kit de sonidos sintéticos (sin descargas): WAV de 16 bits en public/sfx. Es determinista.
const fs = require('fs'), path = require('path');
const SR = 44100, dir = path.join(__dirname, 'public/sfx');
fs.mkdirSync(dir, {recursive: true});
let semilla = 7; const rnd = () => { semilla = (semilla * 1664525 + 1013904223) % 4294967296; return semilla / 4294967296 * 2 - 1; };
const wav = (nombre, seg, fn) => {
  const n = Math.floor(seg * SR), b = Buffer.alloc(44 + n * 2);
  b.write('RIFF', 0); b.writeUInt32LE(36 + n * 2, 4); b.write('WAVEfmt ', 8); b.writeUInt32LE(16, 16); b.writeUInt16LE(1, 20); b.writeUInt16LE(1, 22);
  b.writeUInt32LE(SR, 24); b.writeUInt32LE(SR * 2, 28); b.writeUInt16LE(2, 32); b.writeUInt16LE(16, 34); b.write('data', 36); b.writeUInt32LE(n * 2, 40);
  for (let i = 0; i < n; i++) { const t = i / SR; const v = Math.max(-1, Math.min(1, fn(t, i / n))); b.writeInt16LE(Math.round(v * 32767 * 0.9), 44 + i * 2); }
  fs.writeFileSync(path.join(dir, nombre + '.wav'), b);
};
const pasaBajo = (corte) => { let y = 0; return x => { const a = Math.min(1, 2 * Math.PI * corte / SR); y += a * (x - y); return y; }; };
const env = (t, a, d) => Math.min(1, t / a) * Math.exp(-t / d);

// Papel que se pega: golpe seco y breve.
{ const lp = pasaBajo(2200); wav('slap', 0.18, (t) => lp(rnd()) * 0.7 * env(t, 0.002, 0.03) + Math.sin(2 * Math.PI * (150 - 60 * t) * t) * 0.8 * env(t, 0.003, 0.05)); }
// Golpe grave del «¡CAOS!».
{ const lp = pasaBajo(900); wav('thud', 0.7, (t) => Math.sin(2 * Math.PI * (90 - 55 * Math.min(1, t * 3)) * t) * 1.0 * env(t, 0.004, 0.16) + lp(rnd()) * 0.5 * env(t, 0.001, 0.04)); }
// Pop de los textos.
wav('pop', 0.16, (t) => Math.sin(2 * Math.PI * (480 + 900 * Math.min(1, t * 14)) * t) * 0.7 * env(t, 0.002, 0.035));
// Clic suave del cursor.
wav('tick', 0.06, (t) => Math.sin(2 * Math.PI * 1700 * t) * 0.5 * env(t, 0.001, 0.008) + rnd() * 0.12 * env(t, 0.001, 0.005));
// Whoosh de las transiciones: ruido que sube y baja de tono.
{ let y = 0; wav('whoosh', 0.55, (t, p) => { const corte = 400 + 5200 * Math.sin(Math.PI * Math.min(1, p * 1.05)); const a = Math.min(1, 2 * Math.PI * corte / SR); y += a * (rnd() - y); return y * 2.4 * Math.sin(Math.PI * Math.pow(p, 0.7)); }); }
// Riser antes de que todo se ordene.
{ let y = 0; wav('riser', 1.15, (t, p) => { const corte = 300 + 6000 * p * p; const a = Math.min(1, 2 * Math.PI * corte / SR); y += a * (rnd() - y); return y * 2.2 * p * p + Math.sin(2 * Math.PI * (200 + 900 * p * p) * t) * 0.25 * p; }); }
// Colapso: pasada rápida hacia abajo + golpe.
{ let y = 0; wav('colapso', 0.7, (t, p) => { const corte = 5000 * Math.exp(-5 * p) + 200; const a = Math.min(1, 2 * Math.PI * corte / SR); y += a * (rnd() - y); return y * 2 * Math.exp(-4 * p) + Math.sin(2 * Math.PI * (120 - 60 * p) * t) * 0.7 * env(t, 0.005, 0.12); }); }
// Ding de «aprobado».
wav('ding', 0.9, (t) => (Math.sin(2 * Math.PI * 880 * t) * 0.5 + Math.sin(2 * Math.PI * 1320 * t) * 0.3 + Math.sin(2 * Math.PI * 1760 * t) * 0.12) * env(t, 0.003, 0.22));
// Acorde de cierre (do-mi-sol).
wav('acorde', 1.6, (t) => (Math.sin(2 * Math.PI * 261.6 * t) + Math.sin(2 * Math.PI * 329.6 * t) + Math.sin(2 * Math.PI * 392 * t)) * 0.22 * env(t, 0.01, 0.55));
console.log('sfx listos en', dir);
