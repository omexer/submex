#!/usr/bin/env bash
# Prints a release's notes: every changelog entry since the previous tag, or else the commits since it.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

version="${1:?usage: tools/release-notes.sh MAJOR.MINOR.PATCH}"

ref="HEAD"
git rev-parse -q --verify "refs/tags/v$version" >/dev/null && ref="v$version"
previous="$(git describe --tags --abbrev=0 --match 'v[0-9]*' "$ref^" 2>/dev/null || true)"

# Versions between releases were never published, so their entries belong in this release's notes.
newer_than_previous() {
	[ -z "$previous" ] && return 0
	[ "$1" != "${previous#v}" ] && [ "$(printf '%s\n%s\n' "${previous#v}" "$1" | sort -V | tail -1)" = "$1" ]
}

not_after_version() {
	[ "$(printf '%s\n%s\n' "$1" "$version" | sort -V | tail -1)" = "$version" ]
}

print_entries() {
	local file="$1" pattern="$2" heading="$3"
	local versions=() v
	while IFS= read -r v; do
		if newer_than_previous "$v" && not_after_version "$v"; then
			versions+=( "$v" )
		fi
	# The Upgrade Notice repeats each version heading.
	done < <(sed -n "s/$pattern/\1/p" "$file" | awk '!seen[$0]++')

	[ "${#versions[@]}" -gt 0 ] || return 1

	for v in "${versions[@]}"; do
		[ "${#versions[@]}" -gt 1 ] && printf '### %s\n\n' "$v"
		awk -v head="$(printf "$heading" "$v")" '
			$0 == head { on = 1; next }
			on && (/^= [0-9]+\.[0-9]+\.[0-9]+ =$/ || /^== / || /^## /) { exit }
			on { print }
		' "$file" | sed '/./,$!d'
		[ "${#versions[@]}" -gt 1 ] && echo
	done
	return 0
}

if [ -f readme.txt ] && grep -q "^= ${version//./\\.} =$" readme.txt; then
	print_entries readme.txt '^= \([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\) =$' '= %s ='
	exit 0
fi

# A plugin that is not on wordpress.org keeps its changelog here instead.
if [ -f CHANGELOG.md ] && grep -q "^## ${version//./\\.}$" CHANGELOG.md; then
	print_entries CHANGELOG.md '^## \([0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\)$' '## %s'
	exit 0
fi

if [ -n "$previous" ]; then
	echo "Changes since $previous:"
	echo
	git log --no-merges --pretty='- %s' "$previous..$ref"
else
	echo "The first tagged release. Most recent changes:"
	echo
	git log --no-merges -n 30 --pretty='- %s' "$ref"
fi
