#!/usr/bin/env bash
# Servidor local en http://127.0.0.1:8080 (en segundo plano; log en dev/storage/server.log)
cd "$(dirname "$0")"
mkdir -p storage
[ -f storage/server.pid ] && kill "$(cat storage/server.pid)" 2>/dev/null
nohup php -S 127.0.0.1:8080 -t public public/index.php > storage/server.log 2>&1 &
echo $! > storage/server.pid
sleep 1
echo "http://127.0.0.1:8080"
