// Fuente única de colores, curvas y resortes. Los colores son los del landing de Prisma (theme/prisma).
import {Easing} from 'remotion';

export const T = {
  W: 1080, H: 1350, FPS: 30,
  c: {
    ink: '#141c40',        // azul marino del logo
    ink2: '#3b4366', ink3: '#5a6286',
    paper: '#f9fafd', paper2: '#eceef4',
    signal: '#e8432f',     // rojo: el único color «héroe»
    peach: '#ff8a6b', soft: '#c7cdf0',
    sky1: '#5fb0e4', sky2: '#a9d6f2',
    notas: ['#f9e27d', '#f6b7cb', '#a9d6f2', '#bfe6c6', '#ffc9a3', '#d5c6f2'],
  },
  font: 'Schibsted, ui-sans-serif, system-ui, sans-serif',
  ease: {
    out: Easing.bezier(0.16, 1, 0.3, 1),
    inOut: Easing.bezier(0.83, 0, 0.17, 1),
    in: Easing.bezier(0.7, 0, 0.84, 0),
  },
  spring: {
    snappy: {damping: 14, stiffness: 160, mass: 0.6},
    smooth: {damping: 20, stiffness: 90, mass: 1},
    bouncy: {damping: 11, stiffness: 170, mass: 0.7},
    caja: {damping: 15, stiffness: 120, mass: 0.9},
  },
} as const;

// Números pseudoaleatorios deterministas: el render debe dar siempre lo mismo.
export const azar = (semilla: number) => () => {
  semilla |= 0; semilla = (semilla + 0x6d2b79f5) | 0;
  let t = Math.imul(semilla ^ (semilla >>> 15), 1 | semilla);
  t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
  return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
};
