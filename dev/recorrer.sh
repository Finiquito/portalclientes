#!/usr/bin/env bash
# Recorre rutas y avisa si alguna no responde 200 o trae errores de PHP.
#   dev/recorrer.sh BASE cookiejar ruta...
cd "$(dirname "$0")"
B=$1; J=$2; shift 2
n=0
for u in "$@"; do
  n=$((n+1))
  c=$(curl -s -b "$J" -c "$J" -o storage/o.html -w '%{http_code}' "$B/$u")
  e=$(grep -c "Fatal\|Uncaught\|Warning:\|Notice:\|Deprecated:" storage/o.html)
  if [ "$c" != 200 ] || [ "$e" != 0 ]; then
    echo "PROBLEMA $u → $c"
    grep -o "Fatal[^<]\{0,300\}\|Uncaught[^<]\{0,300\}\|Warning:[^<]\{0,300\}" storage/o.html | head -2
  fi
done
echo "recorrido: $n rutas"
