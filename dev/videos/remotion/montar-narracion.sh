#!/usr/bin/env bash
# Monta los MP3 de la narración (01.mp3 … 10.mp3) sobre el video editado, cada uno en su segundo.
#   bash montar-narracion.sh <video.mp4> <carpeta-con-los-mp3> <salida.mp4>
# El sonido del video (efectos) baja a ~55 % para que la voz mande. Necesita ffmpeg (FFMPEG=/ruta si no está en el PATH).
set -euo pipefail
video="${1:?uso: montar-narracion.sh video.mp4 carpeta-mp3 salida.mp4}"; mp3="${2:?falta la carpeta de MP3}"; salida="${3:?falta el archivo de salida}"
FF="${FFMPEG:-ffmpeg}"
# Segundo de inicio de cada clip: debe coincidir con la tabla de narracion-v1.md y con NARRACION en src/data.ts.
inicios=(0.0 4.0 12.0 20.4 25.4 32.4 42.4 50.4 53.4 60.4)
entradas=(-i "$video"); filtro="[0:a]volume=0.55[fx]"; mezcla="[fx]"
for i in "${!inicios[@]}"; do
  n=$(printf '%02d' $((i + 1))); f="$mp3/$n.mp3"
  [ -f "$f" ] || { echo "Falta $f" >&2; exit 1; }
  ms=$(python3 -c "print(int(round(${inicios[$i]} * 1000)))")
  entradas+=(-i "$f"); filtro+=";[$((i + 1)):a]adelay=${ms}|${ms},volume=1.0[v$n]"; mezcla+="[v$n]"
done
filtro+=";${mezcla}amix=inputs=$(( ${#inicios[@]} + 1 )):normalize=0:duration=first[a]"
"$FF" -y -loglevel error "${entradas[@]}" -filter_complex "$filtro" -map 0:v -map "[a]" -c:v copy -c:a aac -b:a 192k "$salida"
echo "Listo: $salida"
