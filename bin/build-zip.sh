#!/usr/bin/env bash
#
# Construit un ZIP du module Magento 2. Le ZIP contient `Virevo/Payment/...`
# → décompressez dans `app/code/` de votre Magento (ou utilisez composer).
#
# Usage : bash bin/build-zip.sh
set -euo pipefail

cd "$(dirname "$0")/.."

VERSION=$(grep -m1 '"version"' composer.json | sed -E 's/.*"version"[^"]*"([^"]+)".*/\1/')

mkdir -p dist
OUT="dist/virevo-magento-${VERSION}.zip"
rm -f "$OUT"

git archive --format=zip --prefix=Virevo/Payment/ -o "$OUT" HEAD

echo "✅ Construit : $OUT (version $VERSION)"
