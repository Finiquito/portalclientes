import React from 'react';
import {AbsoluteFill, Freeze, OffthreadVideo, Sequence, staticFile, useCurrentFrame} from 'remotion';
import {T} from './theme';
import {FondoMarco} from './capas';
import {Ventana, geom} from './Ventana';
import {TRAMOS, TRANS, ZOOMV, seg, FPS} from './data';

const e = (u: number) => T.ease.inOut(Math.max(0, Math.min(1, u)));

// ----- El video grabado (en vivo, con sus cambios de velocidad) y un cuadro congelado -----
export const Vivo: React.FC = () => (
  <>
    {TRAMOS.map((t, i) => {
      const a = seg(t.out[0]), b = seg(t.out[1]);
      const rate = t.congelar ? 1 : (t.raw[1] - t.raw[0]) / (t.out[1] - t.out[0]);
      const v = <OffthreadVideo src={staticFile('crudo-1.mp4')} startFrom={Math.round(t.raw[0] * FPS)} playbackRate={rate} muted style={{width: T.W, height: T.H}} />;
      return <Sequence key={i} from={a} durationInFrames={b - a + (i < TRAMOS.length - 1 ? 0 : 400)}>{t.congelar ? <Freeze frame={0}>{v}</Freeze> : v}</Sequence>;
    })}
  </>
);
const Congelado: React.FC<{raw: number; desde: number}> = ({raw, desde}) => (
  <Sequence from={desde} durationInFrames={120}><Freeze frame={0}><OffthreadVideo src={staticFile('crudo-1.mp4')} startFrom={Math.round(raw * FPS)} muted style={{width: T.W, height: T.H}} /></Freeze></Sequence>
);

type Capa = {tipo: 'vivo' | 'cong'; m: number; tx?: number; ty?: number; sc?: number; op?: number; z?: number; fx?: number; fy?: number; bg?: number};

// Zoom dentro de la ventana: la pantalla se achica en el marco, se acerca al cursor y vuelve.
const zoomEstado = (f: number) => {
  for (const zv of ZOOMV) {
    const l = f - zv.f, a = 14, b = a + 16, c = b + zv.sostiene, d = c + 16, g = d + 14;
    if (l < 0 || l > g) continue;
    const m = l < a ? e(l / a) : l < d ? 1 : 1 - e((l - d) / 14);
    const z = l < a ? 1 : l < b ? 1 + (zv.z - 1) * e((l - a) / 16) : l < c ? zv.z : l < d ? zv.z - (zv.z - 1) * e((l - c) / 16) : 1;
    return {m, z, fx: zv.fx, fy: zv.fy};
  }
  return null;
};

// Qué capas se ven en el cuadro f (cada transición es distinta; las hay sin movimiento).
export const capasEn = (f: number): Capa[] => {
  const tr = TRANS.find(t => f >= seg(t.out) && f < seg(t.out) + t.D);
  if (tr) {
    const u = (f - seg(tr.out)) / tr.D;
    if (tr.tipo === 'zoom') {            // se achica en el marco; adentro cambia la pantalla; el marco crece
      const m = u < 0.4 ? e(u / 0.4) : u < 0.58 ? 1 : 1 - e((u - 0.58) / 0.42);
      const k = e((u - 0.42) / 0.14);
      return [{tipo: 'cong', m, op: 1 - k}, {tipo: 'vivo', m, op: k}];
    }
    if (tr.tipo === 'encima') {          // la nueva se sobrepone a la vieja
      const k = e(u);
      return [{tipo: 'cong', m: 0, sc: 1 - 0.015 * k}, {tipo: 'vivo', m: 0.3 * (1 - k), ty: (1 - k) * 110, op: Math.min(1, k * 4.5), bg: 0}];
    }
    if (tr.tipo === 'empuje') {          // una ventana empuja a la otra
      const mA = u < 0.28 ? e(u / 0.28) : 1;
      const v = e((u - 0.28) / 0.46);
      const mB = u < 0.74 ? 1 : 1 - e((u - 0.74) / 0.26);
      return [{tipo: 'cong', m: mA, tx: -v * 1180}, {tipo: 'vivo', m: mB, tx: (1 - v) * 1180 * (mB === 1 ? 1 : 0), bg: mB}];
    }
    return [{tipo: 'cong', m: 0, op: 1 - u}, {tipo: 'vivo', m: 0, op: u}];   // corte: sin movimiento
  }
  const z = zoomEstado(f);
  if (z) return [{tipo: 'vivo', m: z.m, z: z.z, fx: z.fx, fy: z.fy}];
  return [{tipo: 'vivo', m: 0}];
};

export const Pantallas: React.FC<{desde: number; hasta: number}> = ({desde, hasta}) => {
  const f = useCurrentFrame();
  if (f < seg(desde) - 1 || f > seg(hasta)) return null;
  const capas = capasEn(f);
  const tr = TRANS.find(t => f >= seg(t.out) && f < seg(t.out) + t.D);
  const bg = Math.min(1, capas.reduce((a, c) => Math.max(a, c.bg ?? c.m), 0) * 2);
  return (
    <AbsoluteFill>
      <FondoMarco opacity={bg} />
      {capas.map((c, i) => {
        const g = geom(c.m);
        return (
          <Ventana key={i} rect={g} tx={c.tx} ty={c.ty} sc={c.sc} op={c.op} sombra={c.m} z={c.z} fx={c.fx} fy={c.fy}>
            {c.tipo === 'vivo' ? <Vivo /> : tr ? <Congelado raw={tr.rawA} desde={seg(tr.out)} /> : null}
          </Ventana>
        );
      })}
    </AbsoluteFill>
  );
};
