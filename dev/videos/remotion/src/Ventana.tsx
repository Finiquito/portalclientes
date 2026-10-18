import React from 'react';
import {T} from './theme';

// Geometría de la ventana de navegador: m = 0 → pantalla completa; m = 1 → ventana chica con marco.
export const geom = (m: number) => {
  const w = 1080 - 120 * m, bar = 58 * m;
  const h = bar + 1350 * (w / 1080);
  return {w, bar, h, x: (1080 - w) / 2, y: (T.H - h) / 2, radius: 26 * m};
};

export type Rect = {x: number; y: number; w: number; h: number; bar: number; radius: number};
type Props = {
  rect: Rect; tx?: number; ty?: number; sc?: number; op?: number; sombra?: number;
  z?: number; fx?: number; fy?: number; contenidoOp?: number; barOp?: number; children?: React.ReactNode; extra?: React.ReactNode;
};

// La pantalla dentro de su ventana. El contenido siempre conserva su proporción (se escala por ancho) y
// el zoom (z) ocurre DENTRO de la ventana, sin salirse del marco.
export const Ventana: React.FC<Props> = ({rect, tx = 0, ty = 0, sc = 1, op = 1, sombra = 0, z = 1, fx = 0.5, fy = 0.5, contenidoOp = 1, barOp = 1, children, extra}) => {
  const {x, y, w, h, bar, radius} = rect;
  const k = w / 1080;
  return (
    <div style={{position: 'absolute', left: x, top: y, width: w, height: h, borderRadius: radius, overflow: 'hidden', opacity: op, transform: `translate(${tx}px, ${ty}px) scale(${sc})`, transformOrigin: '50% 50%', background: T.c.paper, boxShadow: sombra > 0 ? `0 ${34 * sombra}px ${90 * sombra}px rgba(20,28,64,${0.30 * sombra})` : undefined, willChange: 'transform'}}>
      {bar > 1 && (
        <div style={{position: 'absolute', left: 0, right: 0, top: 0, height: bar, background: T.c.barra, borderBottom: '1px solid rgba(20,28,64,0.08)', opacity: barOp, display: 'flex', alignItems: 'center', gap: 10, paddingLeft: 24, overflow: 'hidden'}}>
          {[0, 1, 2].map(i => <div key={i} style={{width: 15, height: 15, borderRadius: '50%', background: 'rgba(20,28,64,0.20)'}} />)}
          <div style={{marginLeft: 28, height: 30, width: '44%', borderRadius: 15, background: 'rgba(20,28,64,0.07)'}} />
        </div>
      )}
      <div style={{position: 'absolute', left: 0, top: bar, width: w, height: 1350 * k, overflow: 'hidden', opacity: contenidoOp}}>
        <div style={{width: 1080, height: 1350, transform: `scale(${k})`, transformOrigin: '0 0'}}>
          <div style={{width: 1080, height: 1350, transform: `scale(${z})`, transformOrigin: `${fx * 100}% ${fy * 100}%`}}>{children}</div>
        </div>
      </div>
      {extra}
    </div>
  );
};
