#!/usr/bin/env bash
# Cuts a release from main: bump, check, build, commit, tag. Pushes only with --push.
# Usage: tools/release.sh MAJOR.MINOR.PATCH [--push] [--dry-run]
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

fail() { echo "release: $*" >&2; exit 1; }

version="${1:-}"
printf '%s' "$version" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$' || fail "usage: tools/release.sh MAJOR.MINOR.PATCH [--push] [--dry-run]"
shift

push=0
dry=0
while [ $# -gt 0 ]; do
	case "$1" in
		--push) push=1 ;;
		--dry-run) dry=1 ;;
		*) fail "unknown option $1" ;;
	esac
	shift
done
[ "$push" = 1 ] && [ "$dry" = 1 ] && fail "--push and --dry-run cannot be combined"

main="$(basename "$(grep -l '^ \* Plugin Name:' ./*.php | head -1)")"
name="$(sed -n 's/^ \* Plugin Name:[[:space:]]*//p' "$main" | head -1)"
tag="v$version"

echo "== preflight: $name $version =="
[ "$(git rev-parse --abbrev-ref HEAD)" = main ] || fail "release from main, not $(git rev-parse --abbrev-ref HEAD)"
git fetch --quiet origin main --tags
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || fail "main is not level with origin/main; pull or push first"
git rev-parse -q --verify "refs/tags/$tag" >/dev/null && fail "tag $tag already exists"
if git ls-remote --exit-code --tags origin "refs/tags/$tag" >/dev/null 2>&1; then fail "tag $tag already exists on origin"; fi

# Only the files a version bump touches may be uncommitted; anything else belongs in its own commit.
# grep, not a case statement: bash 3.2, which macOS ships, ends $( ) at a case pattern's ")".
unrelated="$(git diff --name-only HEAD | grep -vxF -e "$main" -e tools/phpstan-bootstrap.php -e readme.txt -e docs/USER-GUIDE.md -e docs/FEATURES.md || true)"
[ -z "$unrelated" ] || fail "commit or stash these first: $(echo "$unrelated" | tr '\n' ' ')"

echo "== version =="
bash tools/bump-version.sh "$version"
bash tools/check-version.sh "$version" || fail "fix the version problems above (for Submex, write the '= $version =' changelog entry in readme.txt), then run this again"

echo "== checks =="
[ -x vendor/bin/phpcs ] || fail "composer dependencies are missing; run composer install"
[ -d node_modules ] || fail "npm dependencies are missing; run npm ci"
composer check
npm run --silent lint:js
npm run --silent test:unit
npm run --silent build
git diff --quiet -- build/ || fail "rebuilding changed build/; commit the rebuilt assets, then release"

notes="$(bash tools/release-notes.sh "$version")"

if [ "$dry" = 1 ]; then
	bash tools/build.sh --allow-dirty
	echo
	echo "== notes ==" && echo "$notes"
	echo
	echo "dry run: stopped before the commit and the tag. The version bump is left uncommitted."
	exit 0
fi

echo "== commit =="
git diff --name-only HEAD | while IFS= read -r f; do git add -- "$f"; done
[ -z "$(git diff --name-only)" ] || fail "some changes did not stage: $(git diff --name-only | tr '\n' ' ')"
if git diff --cached --quiet; then
	echo "nothing to commit: $version is already committed"
else
	git commit --quiet -m "Release $version"
fi

echo "== build =="
bash tools/build.sh

echo "== tag =="
git tag -a "$tag" -m "$name $version" -m "$notes"
echo "tagged $tag at $(git rev-parse --short HEAD)"

if [ "$push" = 1 ]; then
	git push origin main
	git push origin "$tag"
	echo "pushed. The Release workflow now checks $tag, builds the zip and publishes the GitHub release."
else
	echo
	echo "Not pushed. When you are ready:"
	echo "  git push origin main && git push origin $tag"
fi
