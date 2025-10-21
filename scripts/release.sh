#!/usr/bin/env bash
set -euo pipefail
SLUG="$1"; VER="$2"; ROOT="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
case "$SLUG" in
 core) FILE="$ROOT/plugins/ebts-core-ld/ebts-core-ld.php"; TAG="core-v$VER";;
 sync) FILE="$ROOT/plugins/ebts-ld-woo-sync-variations/ebts-ld-woo-sync-variations.php"; TAG="sync-variations-v$VER";;
 selfreg) FILE="$ROOT/plugins/ebts-selfreg-addon/ebts-selfreg-addon.php"; TAG="selfreg-v$VER";;
 mu) FILE="$ROOT/mu-plugins/ebts-debug-payslip/ebts-debug-payslip.php"; TAG="debug-payslip-v$VER";;
 *) echo 'slug non valido'; exit 1;;
esac
perl -0777 -pe "s/(^\s*\*\s*Version:\s*)([0-9]+\.[0-9]+\.[0-9]+)/\1$VER/m" -i "$FILE"
git add "$FILE"; git commit -m "chore($SLUG): bump version to $VER"; git tag "$TAG"
