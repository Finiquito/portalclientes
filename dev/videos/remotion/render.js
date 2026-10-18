// Renderiza con Remotion.   node render.js final [salida.mp4] | previa | cuadros 1.5 12.5 20 ...
//   final   → 1080×1350, calidad de entrega        previa → mitad de tamaño, para revisar el ritmo
//   cuadros → PNG sueltos (segundos) en ./out, para revisar el diseño sin renderizar todo el video
const {spawnSync} = require('child_process'), fs = require('fs'), path = require('path');
const modo = process.argv[2] || 'previa';
const navegador = process.env.REMOTION_CHROME || ['/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell'].find(p => fs.existsSync(p));
const base = ['remotion'];
const comun = ['--overwrite'].concat(navegador ? ['--browser-executable=' + navegador] : []);
const out = path.join(__dirname, 'out'); fs.mkdirSync(out, {recursive: true});
const corre = args => { const r = spawnSync('npx', [...base, ...args, ...comun], {cwd: __dirname, stdio: 'inherit'}); if (r.status) process.exit(r.status); };
if (modo === 'cuadros') {
  for (const s of process.argv.slice(3)) corre(['still', 'src/index.ts', 'V1', path.join(out, `cuadro-${s}.png`), `--frame=${Math.round(parseFloat(s) * 30)}`]);
} else {
  const salida = process.argv[3] || path.join(out, modo === 'final' ? 'video-1.mp4' : 'previa-1.mp4');
  corre(['render', 'src/index.ts', 'V1', salida, '--codec=h264', `--crf=${modo === 'final' ? 17 : 24}`, '--concurrency=4'].concat(modo === 'final' ? [] : ['--scale=0.5']));
  console.log(salida);
}
