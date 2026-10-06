#!/usr/bin/env bash
# WP-CLI wrapper for the isolated FluentCart lab (Local PHP 8.2 with bundled libs).
set -euo pipefail

# Override for your own lab/php install when the defaults do not match.
PHP_DIR="${FCH_PHP_DIR:-/opt/Local/resources/extraResources/lightning-services/php-8.2.29+0}"
LAB="${FCH_LAB:-/tmp/kilo/fc-lab}"
export LD_LIBRARY_PATH="$PHP_DIR/bin/linux/shared-libs:$PHP_DIR/bin/linux/lib"

exec "$PHP_DIR/bin/linux/bin/php" "$LAB/wp-cli.phar" --path="$LAB/wp" "$@"
