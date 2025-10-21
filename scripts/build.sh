#!/usr/bin/env bash
set -euo pipefail
ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
OUT="$ROOT/build"; mkdir -p "$OUT"
zip_plugin(){ local SRC="$1"; local NAME="$2"; local VER="$3"; local DEST="$OUT/${NAME}-${VER}.zip";
  (cd "$SRC/.." && zip -r "$DEST" "$NAME" -x "*/.*" "*/node_modules/*" "*/vendor/*" "*/tests/*" "*/.git/*" "*/build/*"); echo "Built: $DEST"; }
get_version(){ grep -E "^[ 	/*#@]*Version:" "$1" | head -n1 | sed -E 's/.*Version:[[:space:]]*([^[:space:]]+).*/\1/'; }
CORE="$ROOT/plugins/ebts-core-ld"; if [[ -f "$CORE/ebts-core-ld.php" ]]; then V="$(get_version "$CORE/ebts-core-ld.php")"; zip_plugin "$CORE" "ebts-core-ld" "$V"; fi
SYNC="$ROOT/plugins/ebts-ld-woo-sync-variations"; if [[ -f "$SYNC/ebts-ld-woo-sync-variations.php" ]]; then V="$(get_version "$SYNC/ebts-ld-woo-sync-variations.php")"; zip_plugin "$SYNC" "ebts-ld-woo-sync-variations" "$V"; fi
SELF="$ROOT/plugins/ebts-selfreg-addon"; if [[ -f "$SELF/ebts-selfreg-addon.php" ]]; then V="$(get_version "$SELF/ebts-selfreg-addon.php")"; zip_plugin "$SELF" "ebts-selfreg-addon" "$V"; fi
MU="$ROOT/mu-plugins/ebts-debug-payslip"; if [[ -f "$MU/ebts-debug-payslip.php" ]]; then V="$(get_version "$MU/ebts-debug-payslip.php")"; zip_plugin "$MU" "ebts-debug-payslip" "$V"; fi
