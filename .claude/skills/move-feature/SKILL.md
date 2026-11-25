---
name: move-feature
description: Move a feature between the free Submex plugin (wordpress.org) and Submex Pro, in either direction, without breaking stores running any mix of old and new versions. Use for "move X from Pro to free", "make X a Pro feature", "promote X to free".
---

# Move a feature between Submex (free) and Submex Pro

The two plugins are separate repos beside each other (`submex/`, `submex-pro/`). Pro boots from free's `submex_loaded`, uses only free's public hooks / REST shapes / `window.submex.*` globals, and must never be required for free to work.

## Facts that shape every move

- **Stores run every combination of versions** for weeks: old free + new Pro, new free + old Pro. Both copies of a feature must never be active at once — two copies of a gateway, email, cron job or checkout hook means double emails or **double charges**.
- **The precedent is Stripe** (moved Pro → free in Submex 1.2.0): same option names, gateway id, meta keys and return URL, and free yields while an older Pro is active (`Submex\Plugin::owns_stripe()`). Read that code first and follow the same pattern.
- **Data continuity:** keep option names, meta keys, table names, gateway ids, hook names, REST routes, email ids and template paths identical, so stores keep their settings and data.
- **WordPress.org rules apply to free:** code in the free plugin must be fully usable; it may not ship locked, trial or "unlock with Pro" code. Moving a feature to Pro means removing its code from free (free may keep or add public *hooks* that Pro uses).

## Procedure

Create a task list. Phase 1 needs the user's approval before any edit.

### 1. Inventory and plan (no edits yet)

Grep both repos for everything the feature touches: module in `includes/Modules/`, domain folder, REST controller, React route in `src/` (and `webpack.config.js` entry), templates, emails, options and their defaults, meta keys, custom tables (`*_Installer`, `uninstall.php`), `Upgrade\` migrations, Action Scheduler hooks, settings sections, phpstan stubs, tests, docs (`docs/FEATURES.md`, `docs/GATEWAYS.md`, `docs/USER-GUIDE.md`), text-domain strings.

Show the user a plan with:
- files moving and files changing in each repo;
- the **yield guard**: which side yields to which, detected how (`SUBMEX_VERSION` / `SUBMEX_PRO_VERSION` with `version_compare`, or class existence) — the plugin receiving the feature yields while the old copy is still active;
- what happens to an existing store **without** Pro after the move (Free → Pro especially: their feature disappears — e.g. a gateway's subscriptions fall back to manual invoices). Ask the user how to handle that: keep data, show an admin notice, a changelog/upgrade notice;
- who owns tables/options at uninstall now (uninstalling Pro must not drop data free now uses, and vice versa);
- release order and the `SUBMEX_PRO_MIN_FREE` value needed.

Wait for approval.

### 2. Pro → Free

1. In `../submex`: add the code under `Submex\` in the matching `includes/` folder, wire it in `Plugin.php`, switch the text domain to `'submex'`, add the yield guard so it stays off while an older Pro that still has it is active.
2. Move and adapt the tests to free's `tests/integration/bootstrap.php`; add a test for the yield guard (with a fake `SUBMEX_PRO_VERSION` defined or simulated through the guard's filter if one exists).
3. Free docs: `docs/FEATURES.md`, `readme.txt` changelog line (no version bump).
4. In Pro: remove the module and its code, its React route and `build/` entry, its tests; keep any upgrade/migration needed for data; note in `CHANGELOG.md` that it now comes from free. Do not bump versions — record in the report that Pro's release must raise `SUBMEX_PRO_MIN_FREE` to the free version that contains the feature (`tools/bump-version.sh X.Y.Z --min-free A.B.C`).

### 3. Free → Pro

1. In Pro: add a module (wiring only) plus a domain folder; keep every option/meta/id name. Guard against an older free still registering it (check `SUBMEX_VERSION`).
2. In free: remove the code completely (wordpress.org rule), keep any public hooks Pro needs, and implement the agreed behaviour for stores without Pro. Add a test proving free still works with the feature gone and existing data untouched.
3. Move tests; update docs on both sides.

### 4. Verify both repos

Run the `verify` skill in **both** plugins, including `test-uninstall` in each and Pro's full suite. Then tell the user the release order: **free first** (wordpress.org), then Pro with the raised `SUBMEX_PRO_MIN_FREE` and `requires_submex` set on the licence server.
