#!/usr/bin/env bash
# Runs after Claude edits or writes a file. For PHP files it catches, immediately and
# cheaply, mistakes that would otherwise only surface in CI or (worse) in production:
#   - a syntax error (php -l)
#   - wc_get_orders( ... 'status' => 'any' ...)  — never matches Submex subscription statuses
#     (HANDOVER.md §4 rule 5)
# Exit code 2 sends the message back to Claude so it fixes the file before moving on.
set -uo pipefail

command -v php >/dev/null 2>&1 || exit 0

file="$(php -r '$d = json_decode( stream_get_contents( STDIN ), true ); echo $d["tool_input"]["file_path"] ?? "";')"

case "$file" in
	*.php) ;;
	*) exit 0 ;;
esac
[ -f "$file" ] || exit 0

if ! out="$(php -l "$file" 2>&1)"; then
	echo "PHP syntax error in $file:" >&2
	echo "$out" >&2
	exit 2
fi

# Test files may build such queries on purpose to prove they fail; only guard shipped code.
case "$file" in
	*/tests/*) exit 0 ;;
esac

if grep -nE "'status'[[:space:]]*=>[[:space:]]*'any'" "$file" >/dev/null; then
	echo "$file uses 'status' => 'any'. With wc_get_orders() this resolves to shop_order statuses and silently matches no Submex subscription. Use Submex\\Data\\Subscription_Query instead (HANDOVER.md §4 rule 5). If this is not a wc_get_orders() call, ignore this message." >&2
	grep -nE "'status'[[:space:]]*=>[[:space:]]*'any'" "$file" >&2
	exit 2
fi

exit 0
