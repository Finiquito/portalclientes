#!/usr/bin/env bash
# Arma los zips del landing: el tema (theme/prisma) y el plugin de invitaciones.
#   scripts/zip-landing.sh   → dist/tema-prisma-v<fecha>.zip y dist/invitaciones-v<versión>.zip
set -euo pipefail
cd "$(dirname "$0")/.."

php tests/invitaciones.php > /dev/null || { echo "Las pruebas de invitaciones fallan: no se arman los zips." >&2; exit 1; }
python3 theme/prisma/tools/despiece.py > /dev/null
python3 theme/prisma/tools/bloques.py > /dev/null

mkdir -p dist
v=$(php -r 'echo json_decode(file_get_contents("invitaciones/plugin.json"), true)["version"];')
rm -f "dist/invitaciones-v${v}.zip" dist/tema-prisma-*.zip
zip -qr "dist/invitaciones-v${v}.zip" invitaciones -x '*.DS_Store'
(cd theme && zip -qr "../dist/tema-prisma-$(date +%Y%m%d).zip" prisma -x '*.DS_Store' 'prisma/tools/__pycache__/*')
ls -1 dist/invitaciones-v${v}.zip dist/tema-prisma-*.zip
