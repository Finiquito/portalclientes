import React from 'react';
import {AbsoluteFill, interpolate, spring, useCurrentFrame, useVideoConfig} from 'remotion';
import {T, azar} from './theme';
import {Palabras, clamp} from './capas';
import {seg} from './data';

// Gancho: mientras se cuenta el caos de llevar clientes, la pantalla se llena de post-its desordenados.
const TEXTOS = [
  'Pedir logo en alta', '¿Aprobó el calendario?', 'Audio de 3 min: ¿qué dijo?', 'Cambiar el post 4', 'Responder a Josefa',
  'Factura Panadería Ruiz', '¿Quién sube el reel?', 'Reunión martes: ¿qué quedó?', 'Colores de la marca (???)', 'Subir la grilla a Drive',
  'Cobrar el saldo', '¿Dónde quedó el PDF?', 'Aprobación por WhatsApp (captura)', 'Mandar propuesta', 'Textos del reel',
  'Fotos del horno', 'Hacer la minuta', '¿Qué falta?', 'Revisar la story', 'Cliente nuevo: briefing',
  'Recordar a Camila', 'Subir el carrusel', '¿Ya lo vieron?', 'Cambios v3_FINAL_final', 'Firmar contrato',
  'Pedir las fotos otra vez', 'Horarios de publicación', 'Mismo correo, otro hilo…', 'Pagar el hosting', 'Respuesta del cliente (¿dónde?)',
  'Fotos en alta (otra vez)', 'Revisar el contrato', 'Cambiar la tipografía', 'Pasar a PDF', 'Agendar reunión', 'Enviar la boleta',
  'Corregir una tilde', 'Subir al Drive v2', 'Pedir accesos', 'Armar el presupuesto', 'Cotizar la impresión', 'Actualizar la web',
  'Llamar a Camila', 'Reservar el dominio',
];
const ESPECIALES = [
  {t: 5.1, texto: 'Tareas de Google: me quedó chico', color: '#a9d6f2', w: 430, x: 90, y: 760, rot: -5},
  {t: 7.9, texto: 'Jira: más tiempo manteniéndolo que trabajando', color: '#d5c6f2', w: 480, x: 420, y: 330, rot: 4},
];
const T_CAOS = 3.4, T_FIN = 10.6;

type Nota = {t: number; texto: string; color: string; x: number; y: number; w: number; rot: number; marca: number; esp?: boolean};


const NOTAS: Nota[] = (() => {
  const r = azar(11);
  const n = TEXTOS.length;
  const base = TEXTOS.map((texto, k) => ({
    t: k < 17 ? 0.3 + 0.185 * k * (1 - 0.012 * k) + (k > 3 ? 0 : 0.0) : 3.55 + 6.5 * Math.pow((k - 17) / (n - 17), 0.85), texto, color: T.c.notas[Math.floor(r() * T.c.notas.length)],
    x: -30 + r() * 800, y: (k < 12 ? 400 : 40) + r() * (k < 12 ? 700 : 1020), w: 280 + r() * 60, rot: (r() - 0.5) * 30, marca: Math.floor(r() * 4),
  }));
  return [...base, ...ESPECIALES.map(e => ({...e, marca: 0, esp: true}))].sort((a, b) => a.t - b.t);
})();
export const TIEMPOS_NOTAS = NOTAS.map(n => n.t);
export {T_CAOS, T_FIN};

export const Gancho: React.FC = () => {
  const f = useCurrentFrame();
  const {fps} = useVideoConfig();
  const t = f / fps;

  const notas = NOTAS;
  const caos = Math.max(0, t - T_CAOS);
  const amp = (1.5 + 8 * Math.min(1, t / T_FIN)) + 24 * Math.exp(-caos * 5) * (t >= T_CAOS ? 1 : 0);
  const sx = amp * (Math.sin(f * 1.7) + Math.sin(f * 2.9 + 1)) * 0.5;
  const sy = amp * (Math.sin(f * 2.3 + 2) + Math.sin(f * 3.7)) * 0.5;

  const caosP = spring({frame: f - seg(T_CAOS), fps, config: T.spring.snappy});
  const caosSale = interpolate(t, [4.9, 5.2], [0, 1], clamp);
  const anillo = interpolate(t, [T_CAOS, T_CAOS + 0.55], [0, 1], {...clamp, easing: T.ease.out});
  const badgeP = spring({frame: f - seg(0.5), fps, config: T.spring.bouncy});
  const visibles = NOTAS.filter(n => t >= n.t).length;
  const contador = Math.min(99, Math.round(visibles * 2.35));
  const fin = interpolate(t, [T_FIN, T_FIN + 0.85], [0, 1], clamp);
  const destello = interpolate(t, [T_FIN + 0.55, T_FIN + 0.75, T_FIN + 1.0], [0, 0.85, 0], clamp);

  return (
    <AbsoluteFill style={{fontFamily: T.font}}>
      <div style={{position: 'absolute', inset: 0, transform: `translate(${sx}px, ${sy}px) rotate(${sx * 0.01}deg)`}}>
        {notas.map((n, k) => {
          const fk = f - seg(n.t);
          if (fk < 0) return null;
          const p = spring({frame: fk, fps, config: T.spring.bouncy});
          // Al final todo se succiona hacia el centro y se ordena en la portada.
          const c = interpolate(f - seg(T_FIN) - (k % 9) * 2, [0, 22], [0, 1], {...clamp, easing: T.ease.in});
          const cx = T.W / 2 - (n.x + n.w / 2), cy = T.H / 2 - (n.y + n.w / 2);
          const alto = n.esp ? n.w * 0.78 : n.w * 0.92;
          const gira = n.rot + (1 - p) * (k % 2 ? 14 : -14) + c * (k % 2 ? 220 : -220);
          return (
            <div key={k} style={{
              position: 'absolute', left: n.x, top: n.y, width: n.w, height: alto, padding: '34px 26px 20px', boxSizing: 'border-box',
              backgroundColor: n.color, backgroundImage: 'linear-gradient(165deg, rgba(255,255,255,0.28), rgba(20,28,64,0.07) 90%)',
              boxShadow: `0 ${8 + (1 - p) * 34}px ${18 + (1 - p) * 30}px rgba(20,28,64,${0.22 * (1 - c)})`,
              transform: `translate(${c * cx}px, ${c * cy + (1 - p) * -70}px) rotate(${gira}deg) scale(${(1.55 - 0.55 * p) * (1 - 0.88 * c)})`,
              opacity: Math.min(1, fk / 3) * (1 - Math.max(0, (c - 0.82) / 0.18)),
              filter: c > 0 ? `blur(${c * 6}px)` : undefined,
              color: T.c.ink, fontWeight: 800, fontSize: n.esp ? 44 : 35, lineHeight: 1.12, letterSpacing: '-0.015em', overflow: 'hidden',
            }}>
              <div style={{position: 'absolute', left: 0, right: 0, top: 0, height: 16, background: 'rgba(20,28,64,0.08)'}} />
              {n.texto}
              {n.marca === 1 && <div style={{position: 'absolute', left: 22, right: 22, top: '52%', height: 5, background: T.c.ink, opacity: 0.75, transform: 'rotate(-3deg)'}} />}
              {n.marca === 2 && <div style={{position: 'absolute', right: 18, bottom: 12, fontSize: 56, lineHeight: 1}}>!!</div>}
              {n.marca === 3 && <div style={{position: 'absolute', left: 26, right: 26, bottom: 22, height: 6, borderRadius: 6, background: T.c.ink, opacity: 0.55}} />}
            </div>
          );
        })}
      </div>

      {/* texto de arranque */}
      {t < 3.3 && (
        <div style={{position: 'absolute', left: 80, right: 80, top: 150, color: T.c.ink}}>
          <Palabras texto="Hace dos años soy *freelancer.*" desde={seg(0.2)} salida={seg(2.9)} estilo={{fontSize: 84, fontWeight: 800, lineHeight: 1.02, letterSpacing: '-0.025em', maxWidth: 820}} />
        </div>
      )}

      {/* contador de pendientes */}
      <div style={{position: 'absolute', right: 56, top: 70, transform: `scale(${badgeP}) translate(${sx * 0.6}px, ${sy * 0.6}px)`, textAlign: 'center', opacity: 1 - fin}}>
        <div style={{width: 148, height: 148, borderRadius: '50%', background: T.c.ink, color: '#fff', display: 'grid', placeItems: 'center', fontSize: contador >= 99 ? 58 : 70, fontWeight: 800, fontVariantNumeric: 'tabular-nums', boxShadow: '0 14px 32px rgba(20,28,64,.35)'}}>{contador >= 99 ? '99+' : contador}</div>
        <div style={{marginTop: 10, fontSize: 24, fontWeight: 700, color: T.c.ink, background: 'rgba(249,250,253,.85)', borderRadius: 8, padding: '2px 10px'}}>pendientes</div>
      </div>

      {/* ¡CAOS! */}
      {t >= T_CAOS && t < 5.3 && (
        <>
          <div style={{position: 'absolute', left: T.W / 2 - 400, top: 600 - 400, width: 800, height: 800, borderRadius: '50%', border: `14px solid ${T.c.signal}`, opacity: (1 - anillo) * 0.8, transform: `scale(${0.2 + anillo * 1.5})`}} />
          <div style={{position: 'absolute', left: 0, right: 0, top: 500, textAlign: 'center', fontSize: 262, fontWeight: 900, letterSpacing: '-0.04em', color: T.c.signal, transform: `translate(${sx}px, ${sy}px) rotate(-7deg) scale(${(2.6 - 1.6 * caosP) * (1 + caosSale * 0.35)})`, opacity: 1 - caosSale, textShadow: '0 0 0 #fff, 6px 6px 0 #fff, -6px 6px 0 #fff, 6px -6px 0 #fff, -6px -6px 0 #fff, 0 24px 50px rgba(20,28,64,.35)'}}>¡CAOS!</div>
        </>
      )}
      <AbsoluteFill style={{background: '#fff', opacity: destello, pointerEvents: 'none'}} />
      <AbsoluteFill style={{background: T.c.paper2, opacity: 0 * fin}} />
    </AbsoluteFill>
  );
};
