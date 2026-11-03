#!/bin/sh
set -eu

# Secrets arrive at run time (-e or --env-file), never in the image; fail here with a clear message instead of in the hub.
# The Caddyfile reads MERCURE_JWT_ISSUER without a default, so a missing one is named here instead of ending the web process silently.
: "${APP_SECRET:?set APP_SECRET}"
: "${MERCURE_JWT_SECRET:?set MERCURE_JWT_SECRET}"
: "${MERCURE_JWT_ISSUER:?set MERCURE_JWT_ISSUER}"

php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration

# Validating before exec prints why FrankenPHP rejects the Caddyfile and keeps FrankenPHP as PID 1 for signals.
frankenphp validate --config /app/Caddyfile --adapter caddyfile

exec frankenphp run --config /app/Caddyfile --adapter caddyfile
