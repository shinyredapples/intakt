#!/bin/sh
set -eu

# Secrets arrive at run time (-e or --env-file), never in the image; fail here with a clear message instead of in the hub.
: "${APP_SECRET:?set APP_SECRET}"
: "${MERCURE_JWT_SECRET:?set MERCURE_JWT_SECRET}"

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

exec frankenphp run --config /app/Caddyfile --adapter caddyfile
