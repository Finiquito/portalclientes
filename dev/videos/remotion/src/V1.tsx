import React from 'react';
import {AbsoluteFill, Audio, Sequence, interpolate, spring, staticFile, useCurrentFrame, useVideoConfig} from 'remotion';
import {T} from './theme';
import {Fondo, GranoViñeta, clamp} from './capas';
import {Gancho, TIEMPOS_NOTAS, T_CAOS, T_FIN} from './Gancho';
import {Portada} from './Portada';
import {Cierre} from './Cierre';
import {Aviso} from './Aviso';
import {Pantallas, entra, sale} from './Pantallas';
import {AVISOS, T_CIERRE, T_GANCHO, T_PORTADA, TRANS, ZOOMS, seg} from './data';

const Sfx: React.FC<{t: number; src: string; vol?: number}> = ({t, src, vol = 0.5}) => (
  <Sequence from={Math.max(0, seg(t))} durationInFrames={90}><Audio src={staticFile(`sfx/${src}.wav`)} volume={vol} /></Sequence>
);

// Video 1 · «Lo que ve mi cliente», editado: gancho de post-its → portada → recorrido → cierre.
export const V1: React.FC = () => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const tr0 = TRANS[0];
  // La portada sale hacia arriba cuando entra la pantalla de Josefa.
  const dp = f - seg(tr0.out);
  const qp = interpolate(dp, [0, tr0.D * 0.9], [0, 1], {...clamp, easing: T.ease.inOut});
  const portadaOut = dp >= 0 ? sale('empuje', qp) : null;
  // El cierre entra desde abajo mientras el recorrido se abre y se va.
  const dc = f - seg(T_CIERRE);
  const sc = spring({frame: dc, fps, config: {damping: 13, stiffness: 95, mass: 0.85}, durationInFrames: 22});
  const cierreIn = dc >= 0 ? entra('empuje', sc) : null;

  return (
    <AbsoluteFill style={{backgroundColor: T.c.paper2}}>
      <Fondo />
      {f >= seg(T_PORTADA[0]) && f < seg(T_PORTADA[1]) + 24 && (
        <AbsoluteFill style={portadaOut ? {transform: portadaOut.transform, borderRadius: portadaOut.borderRadius, filter: portadaOut.filter, boxShadow: `0 40px 110px rgba(20,28,64,${0.4 * portadaOut.sombra})`, overflow: 'hidden'} : undefined}>
          <Portada t0={T_GANCHO} />
        </AbsoluteFill>
      )}
      {f < seg(T_GANCHO + 0.4) && <Gancho />}
      <Pantallas desde={T_PORTADA[1]} hasta={T_CIERRE} />
      {AVISOS.map((a, i) => <Aviso key={i} a={a} />)}
      {f >= seg(T_CIERRE) - 1 && cierreIn && (
        <AbsoluteFill style={{transform: cierreIn.transform, borderRadius: cierreIn.borderRadius, filter: cierreIn.filter, boxShadow: `0 -30px 90px rgba(20,28,64,${0.35 * cierreIn.sombra})`, overflow: 'hidden'}}>
          <Cierre t0={T_CIERRE} />
        </AbsoluteFill>
      )}
      <GranoViñeta />

      {/* Sonido: kit sintético en public/sfx (node sfx.js). Va bajo la narración. */}
      {TIEMPOS_NOTAS.map((t, i) => <Sfx key={`n${i}`} t={t - 0.04} src="slap" vol={0.32} />)}
      <Sfx t={T_CAOS - 0.03} src="thud" vol={0.85} />
      <Sfx t={T_FIN - 1.1} src="riser" vol={0.5} />
      <Sfx t={T_FIN + 0.05} src="colapso" vol={0.6} />
      <Sfx t={T_GANCHO + 0.1} src="pop" vol={0.45} />
      {[0, 1, 2, 3, 4].map(i => <Sfx key={`p${i}`} t={T_GANCHO + (6 + i * 5) / 30} src="pop" vol={0.22} />)}
      {TRANS.map((t, i) => <Sfx key={`w${i}`} t={t.out - 0.06} src={t.tipo === 'partir' ? 'thud' : 'whoosh'} vol={t.tipo === 'partir' ? 0.5 : 0.55} />)}
      {AVISOS.map((a, i) => <Sfx key={`a${i}`} t={a.t - 0.02} src="pop" vol={0.4} />)}
      {ZOOMS.map((z, i) => <Sfx key={`z${i}`} t={z.f / 30 - 0.05} src="tick" vol={0.3} />)}
      <Sfx t={32.55} src="ding" vol={0.45} />
      <Sfx t={T_CIERRE - 0.06} src="whoosh" vol={0.55} />
      <Sfx t={T_CIERRE + 50 / 30} src="acorde" vol={0.45} />
    </AbsoluteFill>
  );
};
