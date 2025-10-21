#!/usr/bin/env bash
set -euo pipefail
ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
OUT="$ROOT/build"; mkdir -p "$OUT"
slug="${1:-}"

case "$slug" in
  core)
    DIR="$ROOT/plugins/ebts-core-ld"
    NAME="ebts-core-ld"
    FILE="$DIR/ebts-core-ld.php"
    ;;
  sync)
    DIR="$ROOT/plugins/ebts-ld-woo-sync-variations"
    NAME="ebts-ld-woo-sync-variations"
    FILE="$DIR/ebts-ld-woo-sync-variations.php"
    ;;
  selfreg)
    DIR="$ROOT/plugins/ebts-selfreg-addon"
    NAME="ebts-selfreg-addon"
    FILE="$DIR/ebts-selfreg-addon.php"
    ;;
  mu)
    DIR="$ROOT/mu-plugins/ebts-debug-payslip"
    NAME="ebts-debug-payslip"
    FILE="$DIR/ebts-debug-payslip.php"
    ;;
  *)
    echo "Uso: scripts/build-one.sh <core|sync|selfreg|mu>"
    exit 1
    ;;
esac

VER=$(grep -E "^[[:space:]]*\*[[:space:]]*Version:" "$FILE" | sed -E 's/.*Version:[[:space:]]*([^[:space:]]+).*/\1/')
( cd "$(dirname "$DIR")" && zip -r "$OUT/${NAME}-${VER}.zip" "$NAME" \
  -x "*/.*" "*/node_modules/*" "*/vendor/*" "*/tests/*" "*/.git/*" "*/build/*" )
echo "Creato: $OUT/${NAME}-${VER}.zip"
