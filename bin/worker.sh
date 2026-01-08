#!/usr/bin/env bash
set -euo pipefail
php "$(cd "$(dirname "$0")" && pwd)/worker.php"
