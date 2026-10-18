# Edición con movimiento de los videos (Remotion)

Toma la pantalla grabada de un video de recorrido y la edita: gancho, textos que se descubren, cámara que se acerca al
cursor, transiciones que desplazan y deforman cajas, portada y cierre animados, y efectos de sonido.
Hoy está editado el **video 1** («Lo que ve mi cliente», 64,6 s, 1080×1350, 30 fps).

```
dev/videos/lib.js            graba; con VIDEO_CRUDO=<carpeta> deja crudo-N.mp4 (pantalla limpia) y eventos-N.json
dev/videos/remotion/         esta carpeta (el proyecto de edición)
  src/data.ts                LÍNEA DE TIEMPO: tramos del crudo, transiciones, avisos (textos) y zooms
  src/Gancho.tsx             post-its que llenan la pantalla, «¡CAOS!» y contador de pendientes
  src/Portada.tsx, Cierre.tsx   tarjetas animadas (título que se descubre, piezas que caen)
  src/Aviso.tsx              caja azul que se abre y descubre el texto palabra por palabra
  src/Pantallas.tsx          el crudo dentro de cajas: transiciones (empuje, izq, der, abre, partir) y zoom de cámara
  src/V1.tsx                 arma las capas y el sonido
  src/theme.ts               colores del landing, curvas y resortes (nada va escrito a mano en los componentes)
  sfx.js                     genera los efectos de sonido (WAV) sin descargar nada
  montar-narracion.sh        monta 01.mp3…10.mp3 sobre el video en su segundo
```

## Cómo se rehace el video

1. **Grabar el crudo** (servidor de grabación y datos como en `dev/videos/README.md`):
   `VIDEO_CRUDO=/ruta/crudo FFMPEG=ffmpeg node dev/videos/v1-cliente.js`
2. **Preparar el proyecto** (una vez; instala Remotion): `cd dev/videos/remotion && npm install && node preparar.js /ruta/crudo 1 && node sfx.js`
3. **Mirar**: `node render.js cuadros 3.8 12.6 20.5` deja PNG sueltos en `out/`; `node render.js previa` renderiza a mitad de tamaño (~2 min);
   `npm run estudio` abre Remotion Studio para ver y ajustar en vivo.
4. **Entregar**: `node render.js final` → `out/video-1.mp4`. Después, la narración: `bash montar-narracion.sh out/video-1.mp4 <mp3> salida.mp4`.

Remotion necesita un Chromium: `render.js` usa el `headless_shell` de Playwright (`/opt/pw-browsers`); con otra ruta, `REMOTION_CHROME=/ruta`.

## Qué tocar para cambiar algo

- **Si cambias el guion del video 1** (`v1-cliente.js`): vuelve a grabar el crudo y ajusta `TRAMOS`, `TRANS` y `AVISOS` en `src/data.ts`
  (los tiempos «raw» son del crudo; los «out», del video final). `eventos-1.json` dice cuándo ocurrió cada clic y cada cambio de página.
- **Textos de los post-its**: lista `TEXTOS` en `src/Gancho.tsx`.
- **Tiempos de la narración**: `NARRACION` en `src/data.ts`, la tabla de `../narracion-v1.md` y `inicios` en `montar-narracion.sh` deben decir lo mismo.
- **Otro video** (2 a 7): se hace igual (grabar crudo → `data.ts` propio → `Root.tsx` con otra `Composition`). Los videos 2, 3 y 4 hoy no se pueden grabar
  en el entorno de desarrollo si falta `portal/templates/admin/entregas/_tapas.js.latte` (ver el commit que ancla `/entregas/` en `.gitignore`).

Reglas de movimiento que se siguieron (skill `remotion-motion-graphics`): sin interpolación lineal, entradas con 2–3 propiedades escalonadas,
salidas más rápidas que las entradas, fondo + contenido + gráficos + grano/viñeta en capas, un solo color «héroe» (el rojo) por cuadro,
tiempos derivados de `fps` y pausas deliberadas entre movimientos.
