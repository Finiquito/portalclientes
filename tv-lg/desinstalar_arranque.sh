#!/usr/bin/env bash
# Detiene «Tiempo de TV» y quita el arranque automático. Los datos (datos/tv.sqlite) se quedan.
#   bash desinstalar_arranque.sh
set -euo pipefail
ETIQUETA="cl.gonzalezarias.tiempotv"
launchctl bootout "gui/$(id -u)/$ETIQUETA" 2>/dev/null || true
rm -f "$HOME/Library/LaunchAgents/$ETIQUETA.plist"
echo "Listo. Ya no parte solo. Tus datos siguen en datos/tv.sqlite."
