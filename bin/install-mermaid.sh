#!/bin/sh
# Install the official, self-contained Mermaid browser distribution. No npm lifecycle scripts.
set -eu
ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
VERSION=11.17.2
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT HUP INT TERM
npm pack "mermaid@$VERSION" --ignore-scripts --pack-destination "$TMP" --silent >/dev/null
TARBALL="$TMP/mermaid-$VERSION.tgz"
tar -xzf "$TARBALL" -C "$TMP" package/dist/mermaid.min.js package/LICENSE package/package.json
node -e 'const p=require(process.argv[1]);if(p.name!=="mermaid"||p.version!==process.argv[2])process.exit(1)' "$TMP/package/package.json" "$VERSION"
DEST="$ROOT/public/assets/mermaid"
mkdir -p "$DEST"
# Same filesystem rename; never expose a half-written JS file.
install -m 644 "$TMP/package/dist/mermaid.min.js" "$DEST/.mermaid.min.js.tmp"
mv "$DEST/.mermaid.min.js.tmp" "$DEST/mermaid.min.js"
install -m 644 "$TMP/package/LICENSE" "$DEST/LICENSE.txt"
printf '%s\n' "$VERSION" > "$DEST/VERSION"
