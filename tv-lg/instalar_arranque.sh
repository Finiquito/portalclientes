#!/usr/bin/env bash
# Deja «Tiempo de TV» corriendo siempre en el Mac: parte al iniciar sesión y se levanta solo si se cae.
#   bash instalar_arranque.sh
set -euo pipefail
cd "$(dirname "$0")"
AQUI="$(pwd)"
PY="$(command -v python3)"
ETIQUETA="cl.gonzalezarias.tiempotv"
PLIST="$HOME/Library/LaunchAgents/$ETIQUETA.plist"

"$PY" -c "import websockets" 2>/dev/null || { echo "Falta la librería: pip3 install websockets" >&2; exit 1; }
[ -f "$HOME/.lg_tv_key" ] || echo "Ojo: no existe ~/.lg_tv_key. Corre primero «python3 tiempo_tv.py» a mano y acepta el permiso en la TV."

mkdir -p "$HOME/Library/LaunchAgents" "$AQUI/datos"
cat > "$PLIST" <<PLIST_FIN
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key><string>$ETIQUETA</string>
  <key>ProgramArguments</key>
  <array><string>$PY</string><string>$AQUI/tiempo_tv.py</string></array>
  <key>WorkingDirectory</key><string>$AQUI</string>
  <key>RunAtLoad</key><true/>
  <key>KeepAlive</key><true/>
  <key>ThrottleInterval</key><integer>10</integer>
  <key>StandardOutPath</key><string>$AQUI/datos/tiempo_tv.log</string>
  <key>StandardErrorPath</key><string>$AQUI/datos/tiempo_tv.log</string>
</dict>
</plist>
PLIST_FIN

launchctl bootout "gui/$(id -u)/$ETIQUETA" 2>/dev/null || true
launchctl bootstrap "gui/$(id -u)" "$PLIST"
echo "Listo. «Tiempo de TV» ya está corriendo y partirá solo al iniciar sesión."
echo "Ábrelo en http://localhost:8090 — el registro de lo que pasa queda en datos/tiempo_tv.log"
