---
name: verify
description: Run every check CI runs for Submex / Submex Pro (phpcs, PHPStan, JS lint and unit tests, build/ freshness, version agreement, integration tests) and fix failures. Use after any code change and always before saying work is done.
---

# Verify a change

Run these from the plugin you changed. Report each command and its real result; never say something passed that you did not run.

1. `git status` and `git diff --stat` — know exactly what changed (and run them in `../submex` or `../submex-pro` too if you touched the other plugin).
2. **PHP changed:** `composer check`. Must be zero errors. Fix the code; add a phpcs/phpstan exclusion only with a written reason in the config file.
3. **`src/` changed:** `npm run lint:js`, `npm run test:unit`, then `npm run build`. The rebuilt `build/` is part of the change — CI fails if it differs.
4. **Integration tests.** `npx wp-env start` (if Docker is not running, tell the user and stop here rather than skipping silently). Then:
   - `tools/test.sh <filter>` for the area you touched (e.g. `gateway`, `renewal`, `plans`, `coupon`).
   - The **full** `tools/test.sh` if the change touches billing, gateways, checkout, `Plugin.php`, uninstall, upgrades, or any free-plugin hook, REST shape or `window.submex.*` export that Pro uses.
   - In the free plugin, also run `tools/concurrency-test.php` when `includes/Billing/` changed (see `docs/TESTING.md`); it must show exactly one gateway charge.
5. **Crossed the plugin boundary?** Changed free while working on Pro → run free's `composer check` and free's tests in `../submex`, then Pro's full suite (Pro's wp-env mounts `../submex`). Changed a free public API → run Pro's full suite.
6. `tools/check-version.sh`.
7. **New top-level file or folder?** Add it to `.distignore` (or `tools/build.sh`'s allow list if it must ship), or the release build fails.
8. If a test fails, fix the cause and re-run that test, then the wider filter. If a test looks flaky, re-run it once and say so in the report — do not mark it passed.

End with a short table: check → command → result.
