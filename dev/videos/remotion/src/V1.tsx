import React from 'react';
import {AbsoluteFill, interpolate, useCurrentFrame} from 'remotion';
import {T} from './theme';
import {GranoViñeta, clamp} from './capas';
import {Caos} from './Caos';
import {Portada} from './Portada';
import {Cierre} from './Cierre';
import {Aviso} from './Aviso';
import {Pantallas} from './Pantallas';
import {CierreFoto, DUR_CIERRE} from './CierreFoto';
import {AVISOS, T_CIERRE_V, T_SWAP, seg} from './data';

// Video 1 · «Lo que ve mi cliente»: portada → caos de post-its y avisos → recorrido en ventana → tarjeta final → lámina de cierre.
export const V1: React.FC = () => {
  const f = useCurrentFrame();
  const portadaOp = interpolate(f, [seg(T_SWAP), seg(T_SWAP) + 8], [1, 0], {...clamp, easing: T.ease.in});   // bajo el caos, se cambia por la pantalla de Josefa
  const inicioLamina = seg(T_CIERRE_V) + DUR_CIERRE - 14;
  const lam = interpolate(f, [inicioLamina, inicioLamina + 14], [0, 1], {...clamp, easing: T.ease.out});
  return (
    <AbsoluteFill style={{backgroundColor: T.c.paper2}}>
      <Pantallas desde={T_SWAP} hasta={T_CIERRE_V} />
      {f < seg(T_SWAP) + 10 && <AbsoluteFill style={{opacity: portadaOp}}><Portada t0={0} /></AbsoluteFill>}
      <Caos />
      {AVISOS.map((a, i) => <Aviso key={i} a={a} />)}
      <CierreFoto />
      {f >= inicioLamina - 1 && <AbsoluteFill style={{opacity: lam, transform: `scale(${1.03 - 0.03 * lam})`}}><Cierre t0={inicioLamina / T.FPS} /></AbsoluteFill>}
      <GranoViñeta />
    </AbsoluteFill>
  );
};
