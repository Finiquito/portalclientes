#!/usr/bin/env bash
# Arma dist/prisma-herramienta-<fecha>.zip con la herramienta `prisma` (se sube a ~/prisma del hosting).
set -euo pipefail
cd "$(dirname "$0")/.."
php tests/prisma.php > /dev/null || { echo "Las pruebas de prisma fallan: no se arma el zip." >&2; exit 1; }
mkdir -p dist
out="dist/prisma-herramienta.zip"
rm -f "$out"
zip -qr "$out" prisma -x '*.DS_Store'
echo "$out"
