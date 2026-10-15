# Videos de recorrido (1080×1350)

Siete videos cortos del panel real con datos de demo: portada con gancho en primera persona, cursor, globos
y cierre «Beta gratis · Cupos limitados». La IA es simulada (sólo dev): no hace falta clave.

1. Datos: `bash dev/videos/preparar.sh` (crea `dev/storage/video.sqlite` y su copia base; cada video parte de ella).
2. Servidor (otra terminal):
   `PORTAL_IA_DEMO=1 PORTAL_DB_DSN=sqlite:$PWD/dev/storage/video.sqlite PORTAL_UPLOAD_DIR=$PWD/dev/storage/video_uploads php -S 127.0.0.1:8082 -t dev/public dev/public/index.php`
3. Grabar uno: `node dev/videos/v1-cliente.js` → `entregas/videos/prisma-1-…-1080x1350.mp4`.
   Necesita Playwright y ffmpeg (`pip install imageio-ffmpeg` sirve). `VIDEO_HOJAS=<carpeta>` deja una hoja de cuadros para revisar el ritmo.

- `lib.js`: motor (tarjetas, cursor, globos, tiempos de lectura, captura a 1,25× y MP4).
- `overlay.js`: la capa visual. `arte/`: piezas de ejemplo (se regeneran con `node arte.js` y `node logo.js`).
- Guiones: `v1-cliente`, `v2-grilla`, `v3-imagenes`, `v4-reunion`, `v5-pedir-archivos`, `v6-mensajes`, `v7-mi-dia`.
