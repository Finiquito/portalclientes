import React from 'react';
import {AbsoluteFill, interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';
import {T} from './theme';
import {Logo, Palabras, Piezas, Subrayado, clamp} from './capas';
import {seg} from './data';

// Portada: el título se descubre palabra por palabra y las piezas caen. t0 = segundo en que arranca.
export const Portada: React.FC<{t0: number; estilo?: React.CSSProperties}> = ({t0, estilo}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const d = seg(t0);
  const kick = spring({frame: f - d - 4, fps, config: T.spring.smooth});
  const baj = spring({frame: f - d - 42, fps, config: T.spring.smooth});
  const logo = spring({frame: f - d, fps, config: T.spring.smooth});
  return (
    <AbsoluteFill style={{backgroundColor: T.c.paper2, backgroundImage: 'linear-gradient(rgba(20,28,64,.05) 1px, transparent 1px), linear-gradient(90deg, rgba(20,28,64,.05) 1px, transparent 1px)', backgroundSize: '27px 27px', borderBottom: `12px solid ${T.c.signal}`, overflow: 'hidden', ...estilo}}>
      <Logo width={190} style={{position: 'absolute', left: 80, top: 80, opacity: logo, transform: `translateY(${(1 - logo) * -30}px)`}} />
      <Piezas desde={d + 6} width={290} style={{position: 'absolute', right: 40, bottom: 50}} />
      <div style={{position: 'absolute', left: 80, right: 80, top: 430, fontFamily: T.font, color: T.c.ink}}>
        <div style={{color: T.c.signal, fontWeight: 700, fontSize: 25, letterSpacing: '0.12em', textTransform: 'uppercase', opacity: kick, transform: `translateX(${(1 - kick) * -40}px)`}}>Lo que ve mi cliente</div>
        <Palabras texto="Mis clientes ya no me preguntan" desde={d + 10} estilo={{fontSize: 90, fontWeight: 800, lineHeight: 1.02, letterSpacing: '-0.025em', marginTop: 26}} />
        <Palabras texto="«¿cómo va lo mío?»" desde={d + 28} por={5} estilo={{fontSize: 90, fontWeight: 800, lineHeight: 1.02, letterSpacing: '-0.025em', color: T.c.signal}} resalta={T.c.signal} />
        <div style={{marginTop: 14}}><Subrayado desde={d + 46} ancho={600} grosor={9} /></div>
        <p style={{margin: '34px 0 0', fontSize: 34, lineHeight: 1.35, color: T.c.ink2, fontWeight: 500, opacity: baj, transform: `translateY(${(1 - baj) * 26}px)`}}>Lo ven solos. Te muestro cómo.</p>
      </div>
    </AbsoluteFill>
  );
};
