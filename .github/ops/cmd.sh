#!/usr/bin/env bash
# Current ops command (executed by .github/workflows/ops.yml on the VPS runner).
# Rebuild the web image with the published /postman/ kit and verify it serves.
set -euxo pipefail

docker compose build web
docker compose up -d
sleep 5

echo "--- postman index:"
curl -s http://localhost/postman/ | grep -oE 'href="[^"]+"' | head -10
echo "--- one collection header:"
curl -sI http://localhost/postman/collections/billing.postman_collection.json | head -3
echo "--- env file header:"
curl -sI http://localhost/postman/SOPHIX-VPS.postman_environment.json | head -3
echo OPS_DONE
