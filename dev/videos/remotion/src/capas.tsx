import React from 'react';
import {AbsoluteFill, interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';
import {T} from './theme';
import {LOGO, PIEZAS} from './svgs';

const lim = {extrapolateLeft: 'clamp', extrapolateRight: 'clamp'} as const;
export const clamp = lim;

// Capa 1: el mismo fondo de las tarjetas (papel con grilla) y dos manchas de cielo que se mueven despacio.
export const Fondo: React.FC = () => {
  const f = useCurrentFrame();
  return (
    <AbsoluteFill style={{backgroundColor: T.c.paper2, backgroundImage: 'linear-gradient(rgba(20,28,64,.05) 1px, transparent 1px), linear-gradient(90deg, rgba(20,28,64,.05) 1px, transparent 1px)', backgroundSize: '27px 27px'}}>
      <div style={{position: 'absolute', width: 1100, height: 1100, borderRadius: '50%', top: -520 + Math.sin(f / 70) * 40, left: -380 + Math.cos(f / 90) * 50, background: `radial-gradient(circle, ${T.c.sky2}88, transparent 62%)`, filter: 'blur(40px)'}} />
      <div style={{position: 'absolute', width: 900, height: 900, borderRadius: '50%', bottom: -460, right: -300 + Math.sin(f / 80) * 40, background: `radial-gradient(circle, ${T.c.sky1}44, transparent 64%)`, filter: 'blur(50px)'}} />
    </AbsoluteFill>
  );
};

// Fondo suave de las ventanas: degradado de color claro con grano visible (para que no se vea plano).
export const FondoMarco: React.FC<{opacity?: number}> = ({opacity = 1}) => {
  const f = useCurrentFrame();
  return (
    <AbsoluteFill style={{opacity, background: `linear-gradient(155deg, ${T.c.marcoA}, ${T.c.marcoB} 60%, #efe3ee)`}}>
      <div style={{position: 'absolute', width: 900, height: 900, borderRadius: '50%', top: -380, right: -280, background: `radial-gradient(circle, ${T.c.sky2}77, transparent 64%)`, filter: 'blur(40px)', transform: `translateY(${Math.sin(f / 80) * 24}px)`}} />
      <AbsoluteFill style={{backgroundImage: RUIDO, backgroundSize: '220px', backgroundPosition: `${(f * 5) % 220}px ${(f * 9) % 220}px`, opacity: 0.17, mixBlendMode: 'multiply'}} />
    </AbsoluteFill>
  );
};

export const RUIDO = `url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='220' height='220'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2'/%3E%3C/filter%3E%3Crect width='220' height='220' filter='url(%23n)' opacity='0.5'/%3E%3C/svg%3E")`;
// Capas finales: grano de película (muy leve, para no ensuciar la interfaz) y viñeta.
export const GranoViñeta: React.FC = () => {
  const f = useCurrentFrame();
  return (
    <>
      <AbsoluteFill style={{pointerEvents: 'none', backgroundImage: RUIDO, backgroundSize: '220px', backgroundPosition: `${(f * 7) % 220}px ${(f * 13) % 220}px`, opacity: 0.035, mixBlendMode: 'multiply'}} />
      <AbsoluteFill style={{pointerEvents: 'none', background: 'radial-gradient(ellipse at center, transparent 62%, rgba(20,28,64,0.13) 100%)'}} />
    </>
  );
};

export const Logo: React.FC<{width?: number; style?: React.CSSProperties}> = ({width = 190, style}) => (
  <div style={{width, color: T.c.ink, ...style}} dangerouslySetInnerHTML={{__html: LOGO.replace('width="300" height="78"', 'width="100%" height="auto"')}} />
);

// Las cinco piezas del prisma: caen desde arriba, una tras otra, y quedan separadas, respirando.
const DY: Record<string, number> = {portal: 0, tareas: -64, reuniones: -206, revisiones: -268, archivos: -324, prisma: -398};
export const Piezas: React.FC<{desde: number; width?: number; style?: React.CSSProperties}> = ({desde, width = 300, style}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  let html = PIEZAS;
  Object.keys(DY).forEach((k, i) => {
    const p = spring({frame: f - desde - i * 5, fps, config: T.spring.bouncy});
    const y = DY[k] - (1 - p) * 560 + Math.sin((f - desde) / 28 + i) * 5 * Math.min(1, p);
    html = html.replace(new RegExp(`(<g class="capa capa-${k}") style="[^"]*"`), `$1 style="transform:translateY(${y}px);opacity:${Math.min(1, p * 2.4)}"`);
  });
  html = '<style>.sombra ellipse{fill:rgba(40,50,100,.14)}</style>' + html;
  html = html.replace('<svg class="diagrama-piezas despiece-diagrama"', '<svg style="width:100%;height:auto;overflow:visible" class="diagrama-piezas despiece-diagrama"');
  return <div style={{width, ...style}} dangerouslySetInnerHTML={{__html: html}} />;
};

// Texto que se descubre: cada palabra sube desde una máscara, con un pequeño desfase.
export const Palabras: React.FC<{texto: string; desde: number; por?: number; estilo?: React.CSSProperties; resalta?: string; salida?: number}> = ({texto, desde, por = 3, estilo, resalta = T.c.signal, salida}) => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const partes = texto.split(/(\*[^*]+\*)/).filter(Boolean);
  const palabras: {w: string; r: boolean}[] = [];
  partes.forEach(p => { const r = p.startsWith('*'); p.replace(/\*/g, '').split(' ').filter(Boolean).forEach(w => palabras.push({w, r})); });
  return (
    <div style={{display: 'flex', flexWrap: 'wrap', columnGap: 18, rowGap: 0, ...estilo}}>
      {palabras.map(({w, r}, i) => {
        const p = spring({frame: f - desde - i * por, fps, config: T.spring.snappy});
        const sale = salida === undefined ? 0 : interpolate(f, [salida + i * 1.5, salida + 8 + i * 1.5], [0, 1], {...lim, easing: T.ease.in});
        return (
          <span key={i} style={{display: 'inline-block', overflow: 'hidden', paddingBottom: '0.12em', marginBottom: '-0.12em'}}>
            <span style={{display: 'inline-block', color: r ? resalta : undefined, transform: `translateY(${(1 - p) * 115 + sale * -115}%)`, opacity: 1 - sale}}>{w}</span>
          </span>
        );
      })}
    </div>
  );
};

// Línea que se dibuja de izquierda a derecha (subrayado del texto clave).
export const Subrayado: React.FC<{desde: number; ancho: number; color?: string; grosor?: number}> = ({desde, ancho, color = T.c.signal, grosor = 10}) => {
  const f = useCurrentFrame();
  const p = interpolate(f, [desde, desde + 14], [0, 1], {...lim, easing: T.ease.out});
  return <div style={{height: grosor, width: ancho * p, background: color, borderRadius: grosor}} />;
};
