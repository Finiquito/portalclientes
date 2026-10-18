import React from 'react';
import {spring, useCurrentFrame, useVideoConfig} from 'remotion';
import {T, azar} from './theme';
import {seg} from './data';

// El caos: post-its y notificaciones que tapan la portada y después caen por gravedad.
export const T_CAIDA = 9.25;

// Reparto de los 20 post-its (x, y, lado, giro): tapa ~70 % del cuadro (calculado, no al azar).
const POS: [number, number, number, number][] = [
  [57, 7, 252, -4.0], [231, 94, 237, -10.2], [519, 96, 256, -11.6], [789, -20, 232, -3.0], [-50, 312, 267, 4.6], [307, 244, 231, 9.7],
  [581, 354, 233, -6.9], [745, 257, 227, 7.1], [-35, 606, 240, 3.3], [220, 531, 231, 8.6], [557, 561, 274, -9.2], [813, 613, 243, 7.8],
  [-45, 770, 244, -2.8], [327, 828, 226, 10.3], [487, 800, 261, 6.0], [812, 839, 251, 10.8], [45, 1143, 253, 3.3], [245, 1081, 226, 13.0],
  [500, 1061, 250, -8.9], [804, 1098, 266, -10.3],
];
const ORDEN = [3, 9, 0, 14, 6, 18, 11, 4, 16, 1, 12, 7, 19, 2, 10, 15, 5, 13, 17, 8];   // en qué orden se pegan
const TEXTOS = [
  'Pedir logo en alta', '¿Aprobó el calendario?', 'Audio de 3 min: ¿qué dijo?', 'Cambiar el post 4', 'Responder a Josefa', 'Factura Panadería Ruiz',
  '¿Quién sube el reel?', 'Reunión martes: ¿qué quedó?', 'Colores de la marca (???)', 'Subir la grilla a Drive', 'Jira: más tiempo manteniéndolo que trabajando', '¿Dónde quedó el PDF?',
  'Cobrar el saldo', 'Mandar propuesta', 'Textos del reel', 'Fotos del horno', 'Tareas de Google: me quedó chico', 'Hacer la minuta', 'Cambios v3_FINAL_final', 'Recordar a Camila',
];
const ESPECIALES = new Set([10, 16]);

type Nota = {kind: 'nota'; cx: number; cy: number; w: number; h: number; rot: number; t: number; texto: string; color: string; var: number; esp: boolean};
type Notif = {kind: 'notif'; cx: number; cy: number; w: number; h: number; rot: number; t: number; app: 'chat' | 'mail'; quien: string; texto: string; hora: string};
type Cuerpo = (Nota | Notif) & {r: number; delay: number; vx: number; w0: number};

const NOTIFS_DATA: [string, string, string][] = [
  ['chat', 'Josefa', '¿Me mandas el logo en alta?'], ['mail', 'Re: Re: Calendario noviembre', 'Adjunto la versión corregida'], ['chat', 'Camila · Pampa', 'Audio (3:12)'],
  ['chat', 'Panadería Ruiz', 'Ya vi la grilla, cambios abajo'], ['mail', 'Aprobación v3_FINAL_final.pdf', 'Te lo dejo para revisar'], ['chat', 'Josefa', 'Hola?? ¿lo viste?'],
  ['chat', 'Cliente nuevo', 'Te escribo por lo del logo'], ['mail', '¿Recibiste mi correo anterior?', 'Te escribo de nuevo'], ['chat', 'Campaña octubre', '47 mensajes nuevos'],
  ['mail', 'Factura pendiente', 'Recordatorio de pago'], ['chat', 'Camila · Pampa', 'El PDF no abre'], ['chat', 'Josefa', 'El pan más grande, porfa'],
  ['mail', 'Fotos del horno (zip)', '412 MB · el enlace expira'], ['chat', 'Josefa', '¿Para cuándo queda?'],
];

const CUERPOS: Cuerpo[] = (() => {
  const r = azar(23);
  const salida: Cuerpo[] = [];
  ORDEN.forEach((idx, k) => {
    const [x, y, sz, rot] = POS[idx];
    const t = ESPECIALES.has(idx) ? (idx === 16 ? 5.0 : 7.1) : 2.5 + 6.2 * Math.pow(k / 19, 0.85);
    salida.push({kind: 'nota', cx: x + sz / 2, cy: y + sz * 0.48, w: sz, h: sz * 0.96, rot, t, texto: TEXTOS[idx], color: T.c.notas[Math.floor(r() * T.c.notas.length)], var: Math.floor(r() * 10), esp: ESPECIALES.has(idx), r: sz * 0.44, delay: r() * 0.18, vx: (r() - 0.5) * 220, w0: (r() - 0.5) * 360});
  });
  NOTIFS_DATA.forEach(([app, quien, texto], i) => {
    const x = 30 + r() * 340, y = 70 + i * 84 + (r() - 0.5) * 50;
    salida.push({kind: 'notif', cx: x + 320, cy: y + 60, w: 640, h: 120, rot: (r() - 0.5) * 2, t: 2.2 + i * 0.4, app: app as 'chat' | 'mail', quien, texto, hora: `${9 + Math.floor(i / 5)}:${String(10 + Math.floor(r() * 49)).padStart(2, '0')}`, r: 62, delay: r() * 0.18, vx: (r() - 0.5) * 160, w0: (r() - 0.5) * 120});
  });
  return salida;
})();

// Física simple (círculos con gravedad y choques): se calcula una vez y es siempre igual.
const FRAMES_CAIDA = 75;
const SIM: number[][][] = (() => {
  const st = CUERPOS.map(c => ({x: c.cx, y: c.cy, vx: c.vx, vy: 0, rot: c.rot, w: c.w0, act: false}));
  const salida: number[][][] = [];
  const dt = 1 / 120;
  for (let fr = 0; fr <= FRAMES_CAIDA; fr++) {
    for (let sub = 0; sub < 4; sub++) {
      const tt = (fr + sub / 4) / 30;
      st.forEach((s, i) => { if (!s.act && tt >= CUERPOS[i].delay) s.act = true; });
      st.forEach(s => { if (s.act) { s.vy += 3000 * dt; s.x += s.vx * dt; s.y += s.vy * dt; s.rot += s.w * dt; } });
      for (let i = 0; i < st.length; i++) for (let j = i + 1; j < st.length; j++) {
        const a = st[i], b = st[j]; if (!a.act || !b.act) continue;
        const dx = b.x - a.x, dy = b.y - a.y, d = Math.hypot(dx, dy), min = CUERPOS[i].r + CUERPOS[j].r;
        if (d >= min || d === 0) continue;
        const nx = dx / d, ny = dy / d, sol = (min - d) / 2;
        a.x -= nx * sol; a.y -= ny * sol; b.x += nx * sol; b.y += ny * sol;
        const rv = (b.vx - a.vx) * nx + (b.vy - a.vy) * ny;
        if (rv < 0) { const imp = -(1 + 0.3) * rv / 2; a.vx -= imp * nx; a.vy -= imp * ny; b.vx += imp * nx; b.vy += imp * ny; a.w -= imp * 0.35; b.w += imp * 0.35; }
      }
    }
    salida.push(st.map(s => [s.x, s.y, s.rot]));
  }
  return salida;
})();

// ----- Post-it realista: degradado suave, esquina doblada, cinta o papel rayado -----
const mezcla = (hex: string, a: number) => {
  const n = parseInt(hex.slice(1), 16); const r = (n >> 16) & 255, g = (n >> 8) & 255, b = n & 255;
  const m = (v: number) => Math.max(0, Math.min(255, Math.round(a >= 0 ? v + (255 - v) * a : v * (1 + a))));
  return `rgb(${m(r)},${m(g)},${m(b)})`;
};
export const PostIt: React.FC<{n: Nota; sombra: number}> = ({n, sombra}) => {
  const lineado = n.var === 3 || n.var === 7;
  const cinta = n.var % 3 === 1 || lineado;
  const doblado = !lineado && n.var % 2 === 0;
  const c = 54;
  const base = lineado ? '#f3e6d4' : n.color;
  return (
    <div style={{position: 'absolute', left: 0, top: 0, width: n.w, height: n.h, filter: `drop-shadow(0 ${12 * sombra}px ${14 * sombra}px rgba(20,28,64,0.32))`}}>
      <div style={{position: 'absolute', inset: 0, background: lineado ? `repeating-linear-gradient(180deg, transparent 0 ${n.h / 9 - 2}px, rgba(120,90,70,0.32) ${n.h / 9 - 2}px ${n.h / 9}px), linear-gradient(160deg, ${mezcla(base, 0.3)}, ${base} 70%, ${mezcla(base, -0.06)})` : `linear-gradient(160deg, ${mezcla(base, 0.38)} 0%, ${base} 55%, ${mezcla(base, -0.1)} 100%)`, clipPath: doblado ? `polygon(0 0, 100% 0, 100% 100%, ${c}px 100%, 0 calc(100% - ${c}px))` : undefined}} />
      {!lineado && <div style={{position: 'absolute', left: 0, right: 0, top: 0, height: '15%', background: 'rgba(255,255,255,0.22)'}} />}
      {lineado && [0.12, 0.3, 0.48, 0.66, 0.84].map((y, i) => <div key={i} style={{position: 'absolute', left: 10, top: `${y * 100}%`, width: 16, height: 16, borderRadius: '50%', background: 'rgba(20,28,64,0.18)', boxShadow: 'inset 0 2px 3px rgba(0,0,0,0.25)'}} />)}
      {doblado && <div style={{position: 'absolute', left: 0, top: n.h - c, width: c, height: c, background: `linear-gradient(135deg, ${mezcla(base, -0.22)}, ${mezcla(base, 0.25)})`, clipPath: 'polygon(0 0, 100% 100%, 100% 0)', filter: 'drop-shadow(2px 3px 3px rgba(0,0,0,0.3))'}} />}
      {cinta && <div style={{position: 'absolute', left: '50%', top: -16, width: 104, height: 36, marginLeft: -52, background: 'rgba(204,158,94,0.86)', transform: `rotate(${(n.var % 5) - 2}deg)`, boxShadow: '0 2px 5px rgba(0,0,0,0.22)'}} />}
      <div style={{position: 'absolute', left: lineado ? 46 : 24, right: 18, top: lineado ? 26 : 40, fontFamily: 'Caveat, cursive', fontWeight: 700, fontSize: n.esp ? 38 : 47, lineHeight: 0.98, color: '#232a58', transform: `rotate(${((n.var % 4) - 1.5) * 0.9}deg)`}}>{n.texto}</div>
    </div>
  );
};

// ----- Aviso de chat o de correo (genérico, sin marcas) -----
const Icono: React.FC<{app: 'chat' | 'mail'}> = ({app}) => app === 'chat'
  ? <svg viewBox="0 0 24 24" width="34" height="34"><path d="M12 3C6.5 3 2.5 6.6 2.5 11.2c0 2.2 1 4.2 2.7 5.7L4.4 21l4.2-2.1c1 .3 2 .4 3.4.4 5.5 0 9.5-3.6 9.5-8.1S17.5 3 12 3z" fill="#fff" /></svg>
  : <svg viewBox="0 0 24 24" width="34" height="34"><rect x="2.5" y="5" width="19" height="14" rx="2.6" fill="#fff" /><path d="M3.5 7l8.5 6.2L20.5 7" fill="none" stroke={T.c.sky1} strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" /></svg>;
export const Tarjeta: React.FC<{n: Notif}> = ({n}) => (
  <div style={{width: n.w, height: n.h, boxSizing: 'border-box', padding: '0 24px', display: 'flex', alignItems: 'center', gap: 20, background: 'rgba(252,252,255,0.96)', borderRadius: 28, boxShadow: '0 12px 30px rgba(20,28,64,0.26)', fontFamily: T.font, color: T.c.ink}}>
    <div style={{width: 68, height: 68, borderRadius: 17, flex: 'none', display: 'grid', placeItems: 'center', background: n.app === 'chat' ? 'linear-gradient(160deg,#3fd47a,#1fa65a)' : `linear-gradient(160deg,${T.c.sky2},${T.c.sky1})`}}><Icono app={n.app} /></div>
    <div style={{flex: 1, minWidth: 0}}>
      <div style={{display: 'flex', justifyContent: 'space-between', gap: 10, fontSize: 27, fontWeight: 800, letterSpacing: '-0.01em'}}><span style={{overflow: 'hidden', whiteSpace: 'nowrap', textOverflow: 'ellipsis'}}>{n.quien}</span><span style={{fontSize: 21, fontWeight: 500, color: T.c.ink3, flex: 'none'}}>{n.hora}</span></div>
      <div style={{fontSize: 26, fontWeight: 500, color: T.c.ink2, marginTop: 3, overflow: 'hidden', whiteSpace: 'nowrap', textOverflow: 'ellipsis'}}>{n.texto}</div>
    </div>
  </div>
);

export const Caos: React.FC = () => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const fc = f - seg(T_CAIDA);
  if (fc > FRAMES_CAIDA + 4) return null;
  const dibuja = (tipo: 'notif' | 'nota') => CUERPOS.map((c, i) => {
    if (c.kind !== tipo || f < seg(c.t)) return null;
    const p = spring({frame: f - seg(c.t), fps, config: tipo === 'nota' ? {damping: 12, stiffness: 150, mass: 0.7} : {damping: 18, stiffness: 140, mass: 0.8}});
    let x = c.cx, y = c.cy, rot = c.rot;
    if (fc >= 0) { const s = SIM[Math.min(FRAMES_CAIDA, fc)][i]; x = s[0]; y = s[1]; rot = s[2]; }
    if (y - c.h > T.H + 200) return null;
    const entra = fc >= 0 ? 1 : p;
    const pega = tipo === 'nota' ? 1.28 - 0.28 * entra : 1;
    const px = tipo === 'notif' ? (1 - entra) * 70 : 0;
    return (
      <div key={i} style={{position: 'absolute', left: 0, top: 0, width: c.w, height: c.h, opacity: Math.min(1, p * 2.5), transform: `translate(${x - c.w / 2 + px}px, ${y - c.h / 2}px) rotate(${rot + (tipo === 'nota' ? (1 - entra) * 8 : 0)}deg) scale(${pega})`}}>
        {c.kind === 'nota' ? <PostIt n={c} sombra={fc >= 0 ? 1.4 : 1 + (1 - entra) * 1.2} /> : <Tarjeta n={c} />}
      </div>
    );
  });
  return <div style={{position: 'absolute', inset: 0, overflow: 'hidden'}}>{dibuja('notif')}{dibuja('nota')}</div>;
};
