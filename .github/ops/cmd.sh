#!/usr/bin/env bash
# Current ops command (executed by .github/workflows/ops.yml on the VPS runner).
# Rebuild web with the Postman kit zip baked in and verify it downloads.
set -euxo pipefail

docker compose build web
docker compose up -d
sleep 5

echo "--- kit zip header:"
curl -sI http://localhost/postman/SOPHIX-postman.zip | grep -iE 'HTTP|content-length|content-type'
echo "--- postman index entries:"
curl -s http://localhost/postman/ | grep -oE 'href="[^"]+"' | head -6
echo OPS_DONE
