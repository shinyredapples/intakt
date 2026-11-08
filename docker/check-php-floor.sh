#!/bin/bash
# The base image tag follows the PHP minor line only, so the patch level is checked on the PHP that really runs.
set -euo pipefail

php -r 'echo PHP_VERSION, PHP_EOL; exit(version_compare(PHP_VERSION, "8.5.12", ">=") ? 0 : 1);' \
    || { echo "intakt: PHP 8.5.12 or later is required (PHP2Shell, CVE-2026-109618 and CVE-2026-109619)" >&2; exit 1; }
