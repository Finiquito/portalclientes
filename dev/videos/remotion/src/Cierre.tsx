import React from 'react';
import {AbsoluteFill, interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';
import {T} from './theme';
import {Logo, Palabras, Piezas, Subrayado, clamp} from './capas';
import {seg} from './data';

export const Cierre: React.FC<{t0: number; estilo?: React.CSSProperties}> = ({t0, estilo}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const d = seg(t0);
  const logo = spring({frame: f - d, fps, config: T.spring.smooth});
  const cta = spring({frame: f - d - 50, fps, config: T.spring.caja});
  const nota = spring({frame: f - d - 66, fps, config: T.spring.smooth});
  const respira = 1 + Math.sin((f - d) / 24) * 0.012;
  return (
    <AbsoluteFill style={{backgroundColor: T.c.paper2, backgroundImage: 'linear-gradient(rgba(20,28,64,.05) 1px, transparent 1px), linear-gradient(90deg, rgba(20,28,64,.05) 1px, transparent 1px)', backgroundSize: '27px 27px', borderBottom: `12px solid ${T.c.signal}`, overflow: 'hidden', ...estilo}}>
      <Logo width={170} style={{position: 'absolute', left: 80, top: 80, opacity: logo, transform: `translateY(${(1 - logo) * -30}px)`}} />
      <Piezas desde={d + 4} width={210} style={{position: 'absolute', right: 56, top: 120}} />
      <div style={{position: 'absolute', left: 80, right: 80, top: 450, fontFamily: T.font, color: T.c.ink}}>
        <Palabras texto="Hizo más rápido mi trabajo diario." desde={d + 12} estilo={{fontSize: 72, fontWeight: 800, lineHeight: 1.06, letterSpacing: '-0.022em', maxWidth: 800}} />
        <Palabras texto="Pruébalo y me cuentas cómo te va." desde={d + 30} por={3} estilo={{fontSize: 72, fontWeight: 800, lineHeight: 1.06, letterSpacing: '-0.022em', color: T.c.signal, maxWidth: 800, marginTop: 6}} />
      </div>
      <div style={{position: 'absolute', left: 80, right: 80, bottom: 120, fontFamily: T.font}}>
        <div style={{display: 'inline-flex', alignItems: 'center', gap: 20, background: T.c.ink, color: '#fff', fontWeight: 800, fontSize: 40, padding: '26px 40px', borderRadius: 20, transform: `scale(${(0.7 + 0.3 * cta) * respira}) translateY(${(1 - cta) * 50}px)`, transformOrigin: '0 50%', opacity: cta, boxShadow: `0 24px 60px rgba(20,28,64,${0.3 * cta})`}}>
          Prueba la beta gratis <span style={{fontWeight: 600, color: T.c.soft}}>→ usaprisma.com</span>
        </div>
        <p style={{margin: '22px 0 0', fontSize: 30, color: T.c.ink3, opacity: nota, transform: `translateY(${(1 - nota) * 16}px)`}}>Cupos limitados, mientras lo afinamos.</p>
      </div>
    </AbsoluteFill>
  );
};
