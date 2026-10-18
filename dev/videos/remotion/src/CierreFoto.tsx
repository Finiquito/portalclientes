import React from 'react';
import {AbsoluteFill, Img, interpolate, staticFile, useCurrentFrame} from 'remotion';
import {T} from './theme';
import {FondoMarco, Palabras, clamp} from './capas';
import {Ventana, geom, Rect} from './Ventana';
import {Vivo} from './Pantallas';
import {T_CIERRE_V, seg} from './data';

const e = (u: number) => T.ease.inOut(Math.max(0, Math.min(1, u)));
const lerp = (a: number, b: number, k: number) => a + (b - a) * k;

// La tarjeta alargada y centrada (referencia): ocupa el 80 % del ancho y tapa la parte baja del rostro.
export const TARJETA: Rect = {x: 108, y: 560, w: 864, h: 236, bar: 0, radius: 34};
export const T_TEXTO = 64;             // cuadros desde el inicio del cierre hasta que sube el texto
export const DUR_CIERRE = 150;         // cuadros hasta que todo se va en fade

// Cierre: la pantalla se mete en la ventana, el fondo ya es la persona, la ventana se achica hasta la tarjeta
// y el texto sube dentro de su mismo espacio.
export const CierreFoto: React.FC = () => {
  const f = useCurrentFrame();
  const c = f - seg(T_CIERRE_V);
  if (c < 0 || c > DUR_CIERRE + 4) return null;
  const mm = c < 26 ? e(c / 26) : 1;
  const k = e((c - 34) / 30);                       // de ventana a tarjeta
  const g = geom(mm);
  const rect: Rect = {x: lerp(g.x, TARJETA.x, k), y: lerp(g.y, TARJETA.y, k), w: lerp(g.w, TARJETA.w, k), h: lerp(g.h, TARJETA.h, k), bar: g.bar * (1 - k), radius: lerp(g.radius, TARJETA.radius, k)};
  const fondoOp = interpolate(c, [0, 14], [0, 1], {...clamp, easing: T.ease.out});
  const fotoOp = interpolate(c, [2, 16], [0, 1], {...clamp, easing: T.ease.out});
  const contenido = 1 - e((c - 34) / 14);
  const barOp = 1 - e((c - 34) / 14);
  const respira = 1 + interpolate(c, [0, DUR_CIERRE], [0, 0.05], {...clamp, easing: T.ease.inOut});   // Ken Burns: la foto se acerca muy despacio
  const paneo = interpolate(c, [0, DUR_CIERRE], [0, -14], {...clamp, easing: T.ease.inOut});
  const salida = e((c - (DUR_CIERRE - 20)) / 20);   // zoom leve y fade rápido
  const d = seg(T_CIERRE_V) + T_TEXTO;
  return (
    <AbsoluteFill style={{opacity: 1 - salida, transform: `scale(${1 + salida * 0.06})`}}>
      <FondoMarco opacity={fondoOp} />
      <AbsoluteFill style={{opacity: fotoOp, overflow: 'hidden'}}>
        <Img src={staticFile('persona.webp')} style={{position: 'absolute', left: paneo, top: -148, width: 1080, height: 1620, objectFit: 'cover', transform: `scale(${respira})`, transformOrigin: '50% 35%', filter: 'saturate(0.95)'}} />
        <AbsoluteFill style={{background: 'radial-gradient(ellipse at center, transparent 55%, rgba(20,28,64,0.28) 100%)'}} />
      </AbsoluteFill>
      <Ventana rect={rect} sombra={1} contenidoOp={contenido} barOp={barOp}
        extra={k > 0.5 && (
          <div style={{position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', padding: '0 54px', fontFamily: T.font, color: T.c.ink}}>
            <Palabras texto="Simple, para no gastar mi tiempo en *ordenar.*" desde={d} por={4} estilo={{fontSize: 58, fontWeight: 800, lineHeight: 1.08, letterSpacing: '-0.02em'}} />
          </div>
        )}>
        <Vivo />
      </Ventana>
    </AbsoluteFill>
  );
};
