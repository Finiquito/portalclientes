# Videos de recorrido (1080×1350)

Cursor, globos y tarjeta final sobre el panel real, con datos de demo.

1. Servidor con la IA simulada (sólo dev, sin clave): `PORTAL_IA_DEMO=1 php -S 127.0.0.1:8081 -t dev/public dev/public/index.php`
2. Grabar: `node dev/videos/grilla-ia.js` (Playwright; captura cuadros a 1,25× y arma `lista.txt`).
3. Armar el MP4: `ffmpeg -f concat -safe 0 -i dev/videos/lista.txt -vf "scale=1080:1350:flags=lanczos,fps=30" -c:v libx264 -crf 18 -pix_fmt yuv420p -movflags +faststart grilla-ia.mp4`

`overlay.js` es la capa de cursor y globos; cada video es un guion que la usa.
