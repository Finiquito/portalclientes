// Línea de tiempo del video 1. Los «tramos» dicen qué parte del crudo se ve en cada momento del video final
// (con su velocidad); las transiciones, los textos y los zooms se anclan a esos tiempos.
import eventos from './eventos-1.json';
import {T} from './theme';

export const FPS = T.FPS;
export const seg = (s: number) => Math.round(s * FPS);

export type Tramo = {out: [number, number]; raw: [number, number]; congelar?: boolean};
// Tiempos del crudo (s) → tiempos del video final (s). Los cortes entre tramos son las transiciones.
export const TRAMOS: Tramo[] = [
  {out: [14.4, 15.5], raw: [0.3, 1.6]},      // el portal de Josefa
  {out: [15.5, 16.8], raw: [1.6, 6.4]},      // (acelerado) camino a Revisiones
  {out: [16.8, 20.4], raw: [7.65, 13.87]},   // la grilla
  {out: [20.4, 25.4], raw: [13.95, 20.55]},  // pasa las láminas
  {out: [25.4, 30.7], raw: [20.55, 25.8]},  // le encanta
  {out: [30.7, 32.4], raw: [25.8, 25.8], congelar: true}, // se sostiene el «aprobado»
  {out: [32.4, 42.4], raw: [25.95, 37.0]},   // marca el punto
  {out: [42.4, 50.4], raw: [37.0, 45.24]},   // pide el cambio
  {out: [50.4, 53.4], raw: [45.3, 48.03]},   // la story, aprobada
  {out: [53.4, 60.4], raw: [48.1, 55.4]},    // todo junto, en un aviso
];

export const T_GANCHO = 11.55;
export const T_PORTADA = [10.9, 14.4] as const;
export const T_CIERRE = 60.4;
export const DURACION = 64.6;

export type Trans = {out: number; tipo: 'empuje' | 'izq' | 'der' | 'abre' | 'partir'; D: number; rawA?: number; desde?: 'portada'};
// Cada transición empieza en el corte: la pantalla vieja (congelada) sale y la nueva entra deformándose.
export const TRANS: Trans[] = [
  {out: 14.4, tipo: 'empuje', D: 18, desde: 'portada'},
  {out: 16.8, tipo: 'izq', D: 15, rawA: 6.35},
  {out: 20.4, tipo: 'abre', D: 16, rawA: 13.8},
  {out: 32.4, tipo: 'der', D: 15, rawA: 25.8},
  {out: 50.4, tipo: 'partir', D: 18, rawA: 45.2},
  {out: 53.4, tipo: 'izq', D: 15, rawA: 47.95},
];

export const rawToOut = (r: number): number | null => {
  for (const t of TRAMOS) {
    if (t.congelar) continue;
    if (r >= t.raw[0] && r <= t.raw[1]) return t.out[0] + (r - t.raw[0]) * (t.out[1] - t.out[0]) / (t.raw[1] - t.raw[0]);
  }
  return null;
};

// ----- Textos que se descubren (tiempos en el video final) -----
export type Aviso = {t: number; dur: number; titulo: string; sub?: string; pos: 'arriba' | 'abajo'};
export const AVISOS: Aviso[] = [
  {t: 14.8, dur: 2.3, titulo: 'Josefa entra con su *correo* y un código.', sub: 'Sin contraseñas que olvidar.', pos: 'abajo'},
  {t: 17.4, dur: 2.8, titulo: 'La grilla le llega *tal como se verá* en Instagram.', pos: 'abajo'},
  {t: 21.2, dur: 2.7, titulo: 'Pasa las láminas *como en el celular.*', pos: 'abajo'},
  {t: 26.0, dur: 3.4, titulo: 'Le encanta. Lo *aprueba con un toque.*', pos: 'arriba'},
  {t: 33.2, dur: 4.4, titulo: '¿Algo no la convence?', sub: 'Marca el *punto exacto* en la imagen.', pos: 'abajo'},
  {t: 38.4, dur: 1.9, titulo: '*Punto* marcado.', pos: 'arriba'},
  {t: 43.2, dur: 4.5, titulo: 'Y pide el cambio *ahí mismo,* con el punto marcado.', pos: 'abajo'},
  {t: 50.9, dur: 2.2, titulo: 'La story, *aprobada.*', pos: 'abajo'},
  {t: 54.0, dur: 5.8, titulo: 'Y a mí me llega *todo junto,* en un solo aviso:', sub: 'qué aprobó, qué cambia y dónde.', pos: 'abajo'},
];

// ----- Zooms hacia donde está el cursor cuando hace clic (sin cambio de página) -----
export type Zoom = {f: number; fx: number; fy: number; z: number};
const ev: any[] = (eventos as any).eventos;
export const ZOOMS: Zoom[] = (() => {
  const salida: Zoom[] = [];
  ev.forEach((e, i) => {
    if (e.tipo !== 'clic') return;
    const sigNav = ev.slice(i + 1).find(x => x.tipo === 'nav');
    if (sigNav && sigNav.t - e.t < 0.6) return;            // los clics que cambian de página son transiciones
    const o = rawToOut(e.t); if (o === null) return;
    let ir = null; for (let j = i - 1; j >= 0; j--) { if (ev[j].tipo === 'ir') { ir = ev[j]; break; } }
    if (!ir) return;
    salida.push({f: seg(o), fx: ir.x / (eventos as any).ancho, fy: ir.y / (eventos as any).alto, z: 1.24});
  });
  return salida;
})();

// Guion de la narración: cuándo empieza cada bloque en el video final.
export const NARRACION = [
  [1, 0.0, 4], [2, 4.0, 8], [3, 12.0, 7], [4, 20.4, 5], [5, 25.4, 7],
  [6, 32.4, 10], [7, 42.4, 8], [8, 50.4, 3], [9, 53.4, 7], [10, 60.4, 4],
] as const;
