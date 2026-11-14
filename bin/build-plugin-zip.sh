#!/usr/bin/env bash
#
# Build an installable WordPress plugin zip from a git ref.
#
# Usage:
#   bin/build-plugin-zip.sh [--ref <git-ref>] [--version <x.y.z>] [--out <dir>]
#
# The archive is built from tracked files only (git archive), so untracked
# local files never leak into the package. Development-only paths are removed
# after extraction. The runtime AWS SDK under vendor/s3/ is kept.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REF="HEAD"
VERSION=""
OUT="$ROOT/dist"

usage() {
	cat <<'EOF'
Usage: bin/build-plugin-zip.sh [options]

Options:
  --ref <git-ref>      Git ref to package (default: HEAD)
  --version <x.y.z>    Expected plugin version; must match the plugin header
  --out <dir>          Output directory (default: <repo>/dist)
  -h, --help           Show this help

Outputs:
  <out>/sakura-tools-<version>.zip
  <out>/sakura-tools-<version>.zip.sha256
EOF
}

while [ "$#" -gt 0 ]; do
	case "$1" in
		--ref)
			REF="${2:?--ref requires a git ref}"
			shift 2
			;;
		--version)
			VERSION="${2:?--version requires a version}"
			shift 2
			;;
		--out)
			OUT="${2:?--out requires a directory}"
			shift 2
			;;
		-h|--help)
			usage
			exit 0
			;;
		*)
			echo "Unknown argument: $1" >&2
			usage >&2
			exit 2
			;;
	esac
done

for command in git tar zip unzip; do
	command -v "$command" >/dev/null 2>&1 || {
		echo "Required command not found: $command" >&2
		exit 1
	}
done

cd "$ROOT"

COMMIT="$(git rev-parse --verify "${REF}^{commit}")"
HEADER_VERSION="$(git show "${COMMIT}:sakura-tools.php" | sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*//p' | head -n1 | tr -d '\r')"
CONST_VERSION="$(git show "${COMMIT}:sakura-tools.php" | sed -n "s/^define( 'SAKURA_TOOLS_VERSION', '\([^']*\)' );$/\1/p" | head -n1)"
STABLE_TAG="$(git show "${COMMIT}:readme.txt" | sed -n 's/^Stable tag:[[:space:]]*//p' | head -n1 | tr -d '\r')"

if [ -z "$HEADER_VERSION" ]; then
	echo "Cannot read Version from sakura-tools.php at $REF" >&2
	exit 1
fi

if [ -z "$VERSION" ]; then
	VERSION="$HEADER_VERSION"
fi

if [ "$VERSION" != "$HEADER_VERSION" ]; then
	echo "Requested version $VERSION does not match plugin header $HEADER_VERSION at $REF" >&2
	exit 1
fi

if ! printf '%s' "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$'; then
	echo "Invalid version: $VERSION" >&2
	exit 1
fi

if [ "$CONST_VERSION" != "$VERSION" ]; then
	echo "SAKURA_TOOLS_VERSION is $CONST_VERSION, expected $VERSION" >&2
	exit 1
fi

if [ "$STABLE_TAG" != "$VERSION" ]; then
	echo "readme.txt Stable tag is $STABLE_TAG, expected $VERSION" >&2
	exit 1
fi

mkdir -p "$OUT"
OUT="$(cd "$OUT" && pwd)"
TMP="$(mktemp -d "${TMPDIR:-/tmp}/sakura-tools-build.XXXXXX")"
trap 'rm -rf "$TMP"' EXIT

mkdir -p "$TMP/sakura-tools"
git archive --format=tar "$COMMIT" | tar -xf - -C "$TMP/sakura-tools"
PLUGIN="$TMP/sakura-tools"

# Remove development-only files. Keep vendor/s3/ because it is the runtime
# Straussian-prefixed AWS SDK; all other Composer dev dependencies are not shipped.
rm -rf \
	"$PLUGIN/.github" \
	"$PLUGIN/bin" \
	"$PLUGIN/docs" \
	"$PLUGIN/tests" \
	"$PLUGIN/stubs" \
	"$PLUGIN/.phpunit.cache"
rm -f \
	"$PLUGIN/.gitignore" \
	"$PLUGIN/composer.json" \
	"$PLUGIN/composer.lock" \
	"$PLUGIN/phpcs.xml.dist" \
	"$PLUGIN/phpstan.neon" \
	"$PLUGIN/phpunit.xml.dist" \
	"$PLUGIN/DEVELOPMENT.md" \
	"$PLUGIN/DECISIONS.md" \
	"$PLUGIN/AGENTS.md" \
	"$PLUGIN/CONTRIBUTING.md" \
	"$PLUGIN/SECURITY.md" \
	"$PLUGIN/CODE_OF_CONDUCT.md"

if [ -d "$PLUGIN/vendor" ]; then
	find "$PLUGIN/vendor" -mindepth 1 -maxdepth 1 ! -name s3 -exec rm -rf {} +
fi

if [ ! -f "$PLUGIN/vendor/s3/vendor-prefixed/autoload.php" ]; then
	echo "Runtime dependency vendor/s3/vendor-prefixed/autoload.php is missing" >&2
	exit 1
fi

ZIP="$OUT/sakura-tools-$VERSION.zip"
rm -f "$ZIP" "$ZIP.sha256"
(
	cd "$TMP"
	zip -qr "$ZIP" sakura-tools
)

LIST="$(unzip -Z1 "$ZIP")"
for required in \
	sakura-tools.php \
	uninstall.php \
	readme.txt \
	includes/class-image-processor.php \
	src/Plugin.php \
	vendor/s3/vendor-prefixed/autoload.php; do
	if ! grep -qxF "sakura-tools/$required" <<< "$LIST"; then
		echo "Package is missing required file: $required" >&2
		exit 1
	fi
done

BAD="$(printf '%s\n' "$LIST" | grep -E '^sakura-tools/(docs/|tests/|stubs/|bin/|\.github/|composer\.(json|lock)$|php(cs|stan|unit)\.|\.phpunit\.cache/|DEVELOPMENT\.md$|DECISIONS\.md$|AGENTS\.md$|CONTRIBUTING\.md$|SECURITY\.md$|CODE_OF_CONDUCT\.md$|\.gitignore$)' || true)"
if [ -n "$BAD" ]; then
	echo "Package contains development files:" >&2
	printf '%s\n' "$BAD" >&2
	exit 1
fi

VENDOR_BAD="$(printf '%s\n' "$LIST" | grep '^sakura-tools/vendor/' | grep -vE '^sakura-tools/vendor/($|s3/)' || true)"
if [ -n "$VENDOR_BAD" ]; then
	echo "Package contains vendor files outside vendor/s3:" >&2
	printf '%s\n' "$VENDOR_BAD" >&2
	exit 1
fi

unzip -tq "$ZIP" >/dev/null

if command -v sha256sum >/dev/null 2>&1; then
	HASH="$(sha256sum "$ZIP" | awk '{print $1}')"
else
	HASH="$(shasum -a 256 "$ZIP" | awk '{print $1}')"
fi
printf '%s  %s\n' "$HASH" "$(basename "$ZIP")" > "$ZIP.sha256"

printf 'Built %s\n' "$ZIP"
printf 'Files: %s\n' "$(printf '%s\n' "$LIST" | wc -l | tr -d ' ')"
printf 'Size:  %s bytes\n' "$(wc -c < "$ZIP" | tr -d ' ')"
printf 'SHA256: %s\n' "$HASH"
