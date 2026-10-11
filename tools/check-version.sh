#!/usr/bin/env bash
# Fails unless every place Submex's version is written agrees - and matches $1, when given.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

expected="${1:-}"
problems=0
say() { echo "version: $*" >&2; problems=1; }

header="$(sed -n 's/^ \* Version:[[:space:]]*//p' submex.php | head -1 | tr -d '[:space:]')"
constant="$(sed -n "s/^define( 'SUBMEX_VERSION', '\([^']*\)' );/\1/p" submex.php)"
bootstrap="$(sed -n "s/^define( 'SUBMEX_VERSION', '\([^']*\)' );/\1/p" tools/phpstan-bootstrap.php)"
stable="$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | tr -d '[:space:]')"

printf '%s' "$header" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$' || say "plugin header version '$header' is not MAJOR.MINOR.PATCH"
[ "$constant" = "$header" ] || say "SUBMEX_VERSION is '$constant', plugin header is '$header'"
[ "$bootstrap" = "$header" ] || say "tools/phpstan-bootstrap.php has '$bootstrap', plugin header is '$header'"
[ "$stable" = "$header" ] || say "readme.txt Stable tag is '$stable', plugin header is '$header'"
grep -q "^= ${header//./\\.} =$" readme.txt || say "readme.txt has no changelog entry '= $header ='"
[ -z "$expected" ] || [ "$expected" = "$header" ] || say "expected $expected, but the plugin says $header"

[ "$problems" = 0 ] && echo "version $header: header, constant, bootstrap, Stable tag and changelog agree"
exit "$problems"
