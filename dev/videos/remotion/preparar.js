// Deja listo el proyecto: SVG del logo y las piezas, tipografía y el crudo del video (grabado con VIDEO_CRUDO).
//   node preparar.js <carpeta-del-crudo> [n] [foto]     (n = número de video, por defecto 1; foto = la imagen del cierre)
const fs = require('fs'), path = require('path');
const aqui = __dirname, videos = path.resolve(aqui, '..'), raiz = path.resolve(aqui, '../../..');
const carpeta = process.argv[2], n = process.argv[3] || '1', foto = process.argv[4];
const esc = s => JSON.stringify(fs.readFileSync(path.join(videos, s), 'utf8').trim());
fs.writeFileSync(path.join(aqui, 'src/svgs.ts'), `// Generado por preparar.js desde dev/videos/logo.svg.txt y piezas.svg.txt. No editar a mano.\nexport const LOGO = ${esc('logo.svg.txt')};\nexport const PIEZAS = ${esc('piezas.svg.txt')};\n`);
fs.mkdirSync(path.join(aqui, 'public'), {recursive: true});
fs.copyFileSync(path.join(raiz, 'theme/prisma/assets/fonts/schibsted-grotesk-latin-wght-normal.woff2'), path.join(aqui, 'public/schibsted.woff2'));
fs.copyFileSync(path.join(aqui, 'node_modules/@fontsource/caveat/files/caveat-latin-700-normal.woff2'), path.join(aqui, 'public/caveat.woff2'));
if (foto) fs.copyFileSync(foto, path.join(aqui, 'public/persona.webp'));
if (carpeta) {
  fs.copyFileSync(path.join(carpeta, `crudo-${n}.mp4`), path.join(aqui, `public/crudo-${n}.mp4`));
  fs.copyFileSync(path.join(carpeta, `eventos-${n}.json`), path.join(aqui, `src/eventos-${n}.json`));
}
console.log('Listo. Siguiente: node render.js cuadros 12 | previa | final');
