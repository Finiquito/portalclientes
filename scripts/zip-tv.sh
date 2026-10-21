#!/usr/bin/env bash
# Arma el zip de «Tiempo de TV» (tv-lg/).
#   scripts/zip-tv.sh   → dist/tiempo-tv.zip
set -euo pipefail
cd "$(dirname "$0")/.."

python3 tv-lg/tests/test_tv.py > /dev/null || { echo "Las pruebas de tv-lg fallan: no se arma el zip." >&2; exit 1; }
mkdir -p dist
rm -f dist/tiempo-tv.zip
zip -qr dist/tiempo-tv.zip tv-lg -x '*.DS_Store' 'tv-lg/datos/*' '*/__pycache__/*'
ls -1 dist/tiempo-tv.zip
