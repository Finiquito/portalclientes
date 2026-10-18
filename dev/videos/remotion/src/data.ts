// Línea de tiempo del video 1. Los «tramos» dicen qué parte del crudo se ve en cada momento del video final
// (con su velocidad); las transiciones, los textos y los zooms se anclan a esos tiempos. Tiempos PROVISORIOS:
// se ajustan a la duración real de cada MP3 de la narración (ver NARRACION).
import eventos from './eventos-1.json';
import {T} from './theme';

export const FPS = T.FPS;
export const seg = (s: number) => Math.round(s * FPS);

export type Tramo = {out: [number, number]; raw: [number, number]; congelar?: boolean};
export const TRAMOS: Tramo[] = [
  {out: [9.0, 10.4], raw: [0.3, 0.3], congelar: true},   // la pantalla de Josefa queda debajo del caos
  {out: [10.4, 16.8], raw: [0.3, 6.4]},                  // «Buenas noches, Josefa»
  {out: [16.8, 20.4], raw: [7.65, 13.87]},               // la grilla
  {out: [20.4, 25.4], raw: [13.95, 20.55]},              // pasa las láminas
  {out: [25.4, 30.7], raw: [20.55, 25.8]},               // le encanta
  {out: [30.7, 32.4], raw: [25.8, 25.8], congelar: true},// se sostiene el «aprobado»
  {out: [32.4, 42.4], raw: [25.95, 37.0]},               // marca el punto
  {out: [42.4, 50.4], raw: [37.0, 45.24]},               // pide el cambio
  {out: [50.4, 53.4], raw: [45.3, 48.03]},               // la story, aprobada
  {out: [53.4, 60.0], raw: [48.1, 54.7]},                // todo junto, en un aviso
];

export const T_SWAP = 9.0;          // bajo el caos, la portada se cambia por la pantalla de Josefa
export const T_CIERRE_V = 56.0;     // la pantalla se mete en la ventana y se achica hasta la tarjeta
export const DURACION = 64.6;

// Transiciones entre pantallas (se elige distinta en cada caso; algunas sin movimiento).
export type Trans = {out: number; tipo: 'zoom' | 'encima' | 'empuje' | 'corte'; D: number; rawA: number};
export const TRANS: Trans[] = [
  {out: 16.8, tipo: 'zoom', D: 44, rawA: 6.35},     // Josefa → grilla: se achica en la ventana y la grilla la agranda
  {out: 20.4, tipo: 'encima', D: 24, rawA: 13.8},   // grilla → lámina: se sobrepone
  {out: 32.4, tipo: 'empuje', D: 44, rawA: 25.8},   // aprobado → siguiente pieza: una ventana empuja a la otra
  {out: 50.4, tipo: 'corte', D: 8, rawA: 45.2},     // la story: sin movimiento
  {out: 53.4, tipo: 'encima', D: 24, rawA: 47.95},  // → resumen: se sobrepone
];

export const rawToOut = (r: number): number | null => {
  for (const t of TRAMOS) {
    if (t.congelar) continue;
    if (r >= t.raw[0] && r <= t.raw[1]) return t.out[0] + (r - t.raw[0]) * (t.out[1] - t.out[0]) / (t.raw[1] - t.raw[0]);
  }
  return null;
};

// Zoom dentro de la ventana: la pantalla se achica en el marco, se acerca al cursor y vuelve. Solo en 2 momentos.
export type ZoomV = {f: number; fx: number; fy: number; z: number; sostiene: number};
const ev: any[] = (eventos as any).eventos;
const focoDe = (rawClic: number) => {
  let ir: any = null;
  for (const e of ev) { if (e.t > rawClic) break; if (e.tipo === 'ir') ir = e; }
  return {fx: ir.x / (eventos as any).ancho, fy: ir.y / (eventos as any).alto};
};
export const ZOOMV: ZoomV[] = [
  {raw: 17.098, z: 1.10, sostiene: 40},   // carrusel: la flecha «siguiente»
  {raw: 32.964, z: 1.12, sostiene: 44},   // el punto marcado en la imagen
].map(z => { const o = rawToOut(z.raw)!; return {f: seg(o) - 30, ...focoDe(z.raw), z: z.z, sostiene: z.sostiene}; });

// ----- Textos que se descubren (tiempos en el video final) -----
export type Aviso = {t: number; dur: number; titulo: string; sub?: string; pos: 'arriba' | 'abajo'};
export const AVISOS: Aviso[] = [
  {t: 11.0, dur: 3.0, titulo: 'Josefa entra con su *correo* y un código.', sub: 'Sin contraseñas que olvidar.', pos: 'abajo'},
  {t: 17.9, dur: 2.4, titulo: 'La grilla le llega *tal como se verá* en Instagram.', pos: 'abajo'},
  {t: 21.4, dur: 2.7, titulo: 'Pasa las láminas *como en el celular.*', pos: 'abajo'},
  {t: 26.0, dur: 3.4, titulo: 'Le encanta. Lo *aprueba con un toque.*', pos: 'arriba'},
  {t: 34.0, dur: 4.4, titulo: '¿Algo no la convence?', sub: 'Marca el *punto exacto* en la imagen.', pos: 'abajo'},
  {t: 39.6, dur: 1.9, titulo: '*Punto* marcado.', pos: 'arriba'},
  {t: 43.4, dur: 4.5, titulo: 'Y pide el cambio *ahí mismo,* con el punto marcado.', pos: 'abajo'},
  {t: 50.9, dur: 2.2, titulo: 'La story, *aprobada.*', pos: 'abajo'},
  {t: 53.9, dur: 2.0, titulo: 'Y a mí me llega *todo junto,* en un solo aviso:', sub: 'qué aprobó, qué cambia y dónde.', pos: 'abajo'},
];

// Guion de la narración: cuándo empieza cada bloque en el video final.
export const NARRACION = [
  [1, 0.0, 4], [2, 4.0, 8], [3, 12.0, 7], [4, 20.4, 5], [5, 25.4, 7],
  [6, 32.4, 10], [7, 42.4, 8], [8, 50.4, 3], [9, 53.4, 7], [10, 61.0, 4],
] as const;
