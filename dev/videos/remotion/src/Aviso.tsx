import React from 'react';
import {interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';
import {T} from './theme';
import {Palabras, clamp} from './capas';
import {Aviso as A, seg} from './data';

// Aviso: una caja azul marino que se abre de izquierda a derecha y descubre el texto palabra por palabra.
export const Aviso: React.FC<{a: A}> = ({a}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const d = seg(a.t), fin = seg(a.t + a.dur);
  if (f < d - 1 || f > fin + 14) return null;
  const abre = spring({frame: f - d, fps, config: T.spring.caja});
  const sale = interpolate(f, [fin, fin + 9], [0, 1], {...clamp, easing: T.ease.in});
  const barra = spring({frame: f - d, fps, config: T.spring.snappy});
  const y = (1 - abre) * (a.pos === 'abajo' ? 40 : -40) + sale * (a.pos === 'abajo' ? 30 : -30);
  const lado = a.pos === 'abajo' ? {bottom: 120} : {top: 150};
  return (
    <div style={{position: 'absolute', left: 54, right: 54, ...lado, fontFamily: T.font, transform: `translateY(${y}px)`, opacity: 1 - sale, filter: sale > 0 ? `blur(${sale * 5}px)` : undefined}}>
      <div style={{position: 'relative', background: T.c.ink, borderRadius: 26, padding: '30px 38px 30px 44px', clipPath: `inset(0 ${(1 - Math.min(1, abre)) * 100}% 0 0 round 26px)`, boxShadow: '0 28px 60px rgba(20,28,64,.4)'}}>
        <div style={{position: 'absolute', left: 0, top: 0, bottom: 0, width: 12, background: T.c.signal, transform: `scaleY(${barra})`, transformOrigin: '50% 0'}} />
        <Palabras texto={a.titulo} desde={d + 5} por={2.5} resalta={T.c.peach} estilo={{color: '#fff', fontSize: 46, fontWeight: 800, lineHeight: 1.16, letterSpacing: '-0.012em'}} />
        {a.sub && <div style={{marginTop: 10}}><Palabras texto={a.sub} desde={d + 5 + a.titulo.split(' ').length * 2.5 + 3} por={2} resalta={T.c.peach} estilo={{color: T.c.soft, fontSize: 32, fontWeight: 500, lineHeight: 1.3}} /></div>}
      </div>
    </div>
  );
};
