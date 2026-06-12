#!/usr/bin/env bash
# Current ops command (executed by .github/workflows/ops.yml on the VPS runner).
# Rebuild with the Tailwind + statefulApi fixes, then verify: containers, real CSS
# size, and the health endpoint.
set -euxo pipefail

docker compose build
docker compose up -d
sleep 8
docker compose ps

CSS=$(curl -s http://localhost/login | grep -oE '/build/assets/app-[A-Za-z0-9_-]+\.css' | head -1)
echo "css=$CSS"
curl -sI "http://localhost$CSS" | grep -iE 'HTTP|content-length|content-type'

curl -s http://localhost/api/health
echo
echo DEPLOY_VERIFY_DONE
