#!/usr/bin/env bash
# Arma el zip instalable del plugin (se sube con el zip-uploader del admin de TypeDock).
#   scripts/zip.sh            → dist/portal-plugin-v<versión>.zip
set -euo pipefail
cd "$(dirname "$0")/.."

(cd portal && ../node_modules/.bin/tailwindcss -i assets-src/portal.src.css -o assets/portal.css --minify)
php tests/run.php > /dev/null || { echo "Las pruebas fallan: no se arma el zip." >&2; exit 1; }

v=$(php -r 'echo json_decode(file_get_contents("portal/plugin.json"), true)["version"];')
mkdir -p dist
rm -f "dist/portal-plugin-v${v}.zip"
zip -qr "dist/portal-plugin-v${v}.zip" portal -x '*.DS_Store'
echo "dist/portal-plugin-v${v}.zip"
