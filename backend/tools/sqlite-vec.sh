#!/usr/bin/env bash
set -euo pipefail

version="0.1.9"
target="${1:-$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)/var/sqlite-vec}"

case "$(uname -s)-$(uname -m)" in
    Linux-x86_64) platform="linux-x86_64"; sha="b959baa1d8dc88861b1edb337b8587178cdcb12d60b4998f9d10b6a82052d5d7"; file="vec0.so" ;;
    Linux-aarch64) platform="linux-aarch64"; sha="ea03d39541e478fab5974253c461e1cb5d77742f69e40cf96e3fad5bc309a37c"; file="vec0.so" ;;
    Darwin-arm64) platform="macos-aarch64"; sha="8282126333399ddfe98bbbcc7a1936e7252625aac49df056a98be602e46bfd29"; file="vec0.dylib" ;;
    Darwin-x86_64) platform="macos-x86_64"; sha="53ad76e400786515e2edcaed2f01271dda846316390b761fadbd2dcf56aa4713"; file="vec0.dylib" ;;
    *) echo "No pinned sqlite-vec build for $(uname -s)-$(uname -m)" >&2; exit 1 ;;
esac

if [ -f "$target/$file" ] && [ "$(cat "$target/VERSION" 2>/dev/null)" = "$version" ]; then
    echo "$target"
    exit 0
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
archive="$work/sqlite-vec.tar.gz"
curl -fsSL -o "$archive" "https://github.com/asg017/sqlite-vec/releases/download/v$version/sqlite-vec-$version-loadable-$platform.tar.gz"
actual="$( (command -v sha256sum >/dev/null && sha256sum "$archive" || shasum -a 256 "$archive") | cut -d' ' -f1)"
[ "$actual" = "$sha" ] || { echo "sqlite-vec checksum mismatch: $actual" >&2; exit 1; }
tar -xzf "$archive" -C "$work" "$file"
mkdir -p "$target"
install -m 0644 "$work/$file" "$target/$file"
echo "$version" > "$target/VERSION"
echo "$target"
