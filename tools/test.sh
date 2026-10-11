#!/usr/bin/env bash
# Runs every integration test in tests/integration against a WordPress that has WooCommerce
# and this plugin active, and fails if any of them does.
#
#   tools/test.sh              run them all
#   tools/test.sh renewal      run only those whose name contains "renewal"
#
# SUBMEX_WP is the command that runs WP-CLI inside that WordPress. It defaults to the wp-env
# environment CI uses; point it at your own site instead, for example:
#   SUBMEX_WP="docker compose -f ~/wp-docker/docker-compose.yml exec -T wordpress wp --allow-root"
set -uo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/.."

read -r -a wp <<< "${SUBMEX_WP:-npx wp-env run cli wp}"
slug="$(basename "$PWD")"
filter="${1:-}"

passed=0
failed=()
log="$(mktemp)"
trap 'rm -f "$log"' EXIT

for test in tests/integration/test-*.php; do
	name="$(basename "$test" .php)"
	[ -n "$filter" ] && [[ "$name" != *"$filter"* ]] && continue

	printf '\n── %s\n' "$name"

	# Environment noise that is not about the test: a mailer with no sendmail, compose's
	# own warnings. The exit status is still the test's.
	"${wp[@]}" eval-file "wp-content/plugins/$slug/$test" 2>&1 \
		| grep -vE 'sendmail: not found|attribute `version` is obsolete' \
		| tee "$log"
	status="${PIPESTATUS[0]}"

	# eval-file can exit 0 having run nothing, so a pass must also say so.
	if [ "$status" = 0 ] && ! grep -qx 'all checks passed' "$log"; then
		echo "── $name exited 0 without reporting 'all checks passed'; counting it as failed"
		status=1
	fi

	if [ "$status" = 0 ]; then
		passed=$((passed + 1))
	else
		failed+=("$name")
		[ -n "${GITHUB_ACTIONS:-}" ] && echo "::error title=Integration test failed::$name"
	fi
done

total=$((passed + ${#failed[@]}))
[ "$total" -gt 0 ] || { echo "no tests matched '${filter}'" >&2; exit 1; }

printf '\n%s\n' "────────────────────────────────"
if [ "${#failed[@]}" -gt 0 ]; then
	printf '%d of %d test files failed:\n' "${#failed[@]}" "$total"
	printf '  %s\n' "${failed[@]}"
	exit 1
fi

printf 'all %d test files passed\n' "$total"
