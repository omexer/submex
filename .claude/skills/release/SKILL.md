---
name: release
description: Prepare and cut a release of Submex or Submex Pro with the repo's own release scripts. Only when the user explicitly asks to release.
disable-model-invocation: true
---

# Release

Argument: the version, e.g. `/release 0.56.0`. Ask for it if missing. Read `RELEASING.md` (Pro) or `docs/RELEASING.md` (free) first.

1. `git status` must be clean and on `main`, up to date. Stop and tell the user otherwise.
2. **Pro only:** does this release depend on an unreleased free change? Check `git log` since the last tag for new free hooks/APIs used. If yes: free must be released first, and this release needs `--min-free`. Ask the user to confirm the free version.
3. **Changelog:** free → `= X.Y.Z =` at the top of `readme.txt`'s changelog (and `CHANGELOG.md`); Pro → `## X.Y.Z` at the top of `CHANGELOG.md`. Draft it from the commits since the last tag, written for a merchant deciding whether to update; show it to the user and wait for approval.
4. Run the full integration suite: `tools/test.sh` (the release script does not run it).
5. `tools/release.sh X.Y.Z --dry-run` (Pro with a new free minimum: run `tools/bump-version.sh X.Y.Z --min-free A.B.C` as the script's docs say). Show the output.
6. Only after the user says go: `tools/release.sh X.Y.Z`. Do **not** push — give the user the push command (`git push origin main && git push origin vX.Y.Z`) unless they explicitly ask you to push.
7. Remind them of the manual steps: Pro → upload the zip to the licence server and set `requires_submex`; free → the wordpress.org deploy workflow, and update the free plugin's docs if they name the Pro version.
