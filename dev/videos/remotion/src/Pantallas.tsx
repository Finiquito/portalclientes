import React from 'react';
import {AbsoluteFill, Freeze, OffthreadVideo, Sequence, interpolate, spring, staticFile, useCurrentFrame, useVideoConfig} from 'remotion';
import {T} from './theme';
import {clamp} from './capas';
import {TRAMOS, TRANS, ZOOMS, Trans, seg, FPS} from './data';

const cl01 = (x: number) => Math.max(0, Math.min(1, x));
type Est = {transform: string; borderRadius: number; filter?: string; opacity: number; sombra: number};

// Cómo ENTRA la pantalla nueva (s = resorte 0→1, con un pequeño rebote).
export const entra = (tipo: Trans['tipo'], s: number): Est => {
  const r = 1 - s;
  const m = (tx: number, ty: number, ry: number, rz: number, sk: number, sc: number, sy = 1) =>
    `perspective(1800px) translate(${tx}px, ${ty}px) rotateY(${ry}deg) rotateZ(${rz}deg) skewX(${sk}deg) scale(${sc}) scaleY(${sy})`;
  switch (tipo) {
    case 'izq': return {transform: m(r * 1180, 0, r * -14, r * 5, r * -8, 0.88 + 0.12 * s), borderRadius: r * 70, filter: `blur(${cl01(r) * 10}px)`, opacity: 1, sombra: cl01(r * 4)};
    case 'der': return {transform: m(r * -1180, 0, r * 14, r * -5, r * 8, 0.88 + 0.12 * s), borderRadius: r * 70, filter: `blur(${cl01(r) * 10}px)`, opacity: 1, sombra: cl01(r * 4)};
    case 'empuje': return {transform: m(0, r * 1450, 0, 0, 0, 0.92 + 0.08 * s, 1 + r * 0.08), borderRadius: r * 80, filter: `blur(${cl01(r) * 8}px)`, opacity: 1, sombra: cl01(r * 4)};
    case 'abre': return {transform: m(0, 0, 0, 0, 0, 0.5 + 0.5 * s), borderRadius: r * 170, filter: `blur(${cl01(r) * 6}px)`, opacity: Math.min(1, s * 3), sombra: cl01(r * 4)};
    case 'partir': return {transform: m(0, 0, 0, 0, 0, 0.9 + 0.1 * s), borderRadius: 0, filter: undefined, opacity: 1, sombra: 0};
  }
};
// Cómo SALE la pantalla vieja (q = 0→1).
export const sale = (tipo: Trans['tipo'], q: number): Est => {
  const m = (tx: number, ty: number, ry: number, rz: number, sk: number, sc: number) =>
    `perspective(1800px) translate(${tx}px, ${ty}px) rotateY(${ry}deg) rotateZ(${rz}deg) skewX(${sk}deg) scale(${sc})`;
  switch (tipo) {
    case 'izq': return {transform: m(-q * 1180, 0, q * 14, -q * 6, q * 8, 1 - q * 0.14), borderRadius: q * 70, filter: `blur(${q * 10}px)`, opacity: 1, sombra: cl01(q * 4)};
    case 'der': return {transform: m(q * 1180, 0, -q * 14, q * 6, -q * 8, 1 - q * 0.14), borderRadius: q * 70, filter: `blur(${q * 10}px)`, opacity: 1, sombra: cl01(q * 4)};
    case 'empuje': return {transform: m(0, -q * 1450, 0, 0, 0, 1 - q * 0.08), borderRadius: q * 80, filter: `blur(${q * 8}px)`, opacity: 1, sombra: cl01(q * 4)};
    case 'abre': return {transform: m(0, 0, 0, 0, 0, 1 + q * 0.35), borderRadius: 0, filter: `blur(${q * 10}px)`, opacity: 1 - q, sombra: 0};
    default: return {transform: 'none', borderRadius: 0, opacity: 1, sombra: 0};
  }
};
const caja = (e: Est): React.CSSProperties => ({
  position: 'absolute', inset: 0, overflow: 'hidden', transform: e.transform, borderRadius: e.borderRadius, filter: e.filter, opacity: e.opacity,
  boxShadow: `0 40px 110px rgba(20,28,64,${0.4 * e.sombra})`, willChange: 'transform',
});

const Video: React.FC<{raw: number; rate?: number; congelar?: boolean}> = ({raw, rate = 1, congelar}) => {
  const v = <OffthreadVideo src={staticFile('crudo-1.mp4')} startFrom={Math.round(raw * FPS)} playbackRate={rate} muted style={{width: T.W, height: T.H}} />;
  return <div style={{position: 'absolute', inset: 0, background: T.c.paper}}>{congelar ? <Freeze frame={0}>{v}</Freeze> : v}</div>;
};

// Zoom de cámara hacia el cursor: entra justo antes del clic, se sostiene y vuelve.
const zoomEn = (f: number, bloqueado: boolean) => {
  let mejor = {z: 1, fx: 0.5, fy: 0.5};
  if (bloqueado) return mejor;
  for (const z of ZOOMS) {
    const l = f - (z.f - 12);
    if (l < 0 || l > 12 + 34 + 18) continue;
    const env = l < 12 ? T.ease.out(l / 12) : l < 46 ? 1 : 1 - T.ease.inOut((l - 46) / 18);
    const zz = 1 + (z.z - 1) * env;
    if (zz > mejor.z) mejor = {z: zz, fx: z.fx, fy: z.fy};
  }
  return mejor;
};

export const Pantallas: React.FC<{desde: number; hasta: number}> = ({desde, hasta}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  if (f < seg(desde) - 1 || f > seg(hasta) + 30) return null;

  const activa = TRANS.find(t => f >= seg(t.out) - 1 && f <= seg(t.out) + t.D + 4);
  const dentro = activa ? f - seg(activa.out) : 0;
  const s = activa ? spring({frame: dentro, fps, config: {damping: 13, stiffness: 95, mass: 0.85}, durationInFrames: activa.D + 4}) : 1;
  const q = activa ? interpolate(dentro, [0, activa.D * 0.9], [0, 1], {...clamp, easing: T.ease.inOut}) : 1;

  const cam = zoomEn(f, !!activa && dentro < activa.D + 6);
  const deriva = 1 + Math.sin(f / 40) * 0.006;
  const tramos = TRAMOS.map((t, i) => {
    const a = seg(t.out[0]), b = seg(t.out[1]);
    const rate = t.congelar ? 1 : (t.raw[1] - t.raw[0]) / (t.out[1] - t.out[0]);
    return (
      <Sequence key={i} from={a} durationInFrames={b - a + (i < TRAMOS.length - 1 ? 0 : 20)}>
        <Video raw={t.raw[0]} rate={rate} congelar={t.congelar} />
      </Sequence>
    );
  });
  // Se sostiene el «aprobado» con un empujón lento de cámara.
  const hold = TRAMOS.find(t => t.congelar)!;
  const empuje = hold ? interpolate(f, [seg(hold.out[0]), seg(hold.out[1])], [1, 1.1], {...clamp, easing: T.ease.inOut}) : 1;
  const ent = activa && activa.tipo !== 'partir' ? entra(activa.tipo, s) : activa ? entra('partir', s) : null;

  return (
    <AbsoluteFill>
      {activa && activa.rawA !== undefined && (
        activa.tipo === 'partir' ? (
          <>
            {(['arriba', 'abajo'] as const).map(mitad => (
              <div key={mitad} style={{position: 'absolute', inset: 0, clipPath: mitad === 'arriba' ? 'inset(0 0 50% 0)' : 'inset(50% 0 0 0)', transform: `translateY(${(mitad === 'arriba' ? -1 : 1) * q * 760}px) rotateZ(${(mitad === 'arriba' ? -1 : 1) * q * 3}deg)`, transformOrigin: mitad === 'arriba' ? '50% 100%' : '50% 0%', filter: `blur(${q * 6}px)`, opacity: 1 - Math.max(0, q - 0.85) * 6}}>
                <Sequence from={seg(activa.out)} durationInFrames={activa.D + 6}><Video raw={activa.rawA} congelar /></Sequence>
              </div>
            ))}
          </>
        ) : (
          <div style={caja(sale(activa.tipo, q))}>
            <Sequence from={seg(activa.out)} durationInFrames={activa.D + 6}><Video raw={activa.rawA} congelar /></Sequence>
          </div>
        )
      )}
      <div style={ent ? caja(ent) : {position: 'absolute', inset: 0, overflow: 'hidden'}}>
        <div style={{position: 'absolute', inset: 0, transform: `scale(${cam.z * deriva * empuje})`, transformOrigin: `${cam.fx * 100}% ${cam.fy * 100}%`}}>
          {tramos}
        </div>
      </div>
    </AbsoluteFill>
  );
};
