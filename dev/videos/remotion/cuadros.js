// Cuadros sueltos (PNG) de la composición real, sin renderizar el video: sirven para el storyboard.
//   node cuadros.js <salida> <segundo> [<segundo> …]      (ej.: node cuadros.js out 1.9 9.6 56.8)
const path = require('path'), fs = require('fs');
const {bundle} = require('@remotion/bundler');
const {renderStill, selectComposition} = require('@remotion/renderer');
const navegador = process.env.REMOTION_CHROME || '/opt/pw-browsers/chromium_headless_shell-1194/chrome-linux/headless_shell';
(async () => {
  const [salida, ...tiempos] = process.argv.slice(2);
  fs.mkdirSync(salida, {recursive: true});
  const serveUrl = await bundle({entryPoint: path.join(__dirname, 'src/index.ts')});
  const opciones = {serveUrl, browserExecutable: fs.existsSync(navegador) ? navegador : undefined, logLevel: 'error'};
  const composition = await selectComposition({...opciones, id: 'V1'});
  for (const t of tiempos) {
    const frame = Math.round(parseFloat(t) * composition.fps);
    await renderStill({...opciones, composition, frame, output: path.join(salida, `cuadro-${t}.png`)});
    console.log('cuadro', t);
  }
})().catch(e => { console.error(e); process.exit(1); });
