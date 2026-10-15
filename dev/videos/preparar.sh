#!/bin/bash
# Base limpia para grabar videos: dev/storage/video.sqlite + dev/storage/video_uploads (y su copia «base»).
set -e
cd "$(dirname "$0")/.."
export PORTAL_DB_DSN="sqlite:$PWD/storage/video.sqlite" PORTAL_UPLOAD_DIR="$PWD/storage/video_uploads"
rm -rf storage/video.sqlite storage/video_uploads storage/video-base.sqlite storage/video_uploads-base
php seed-demo.php >/dev/null
php videos/preparar.php
cp storage/video.sqlite storage/video-base.sqlite && cp -r storage/video_uploads storage/video_uploads-base
