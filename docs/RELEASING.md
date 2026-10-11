# Releasing

How a version of Submex or Submex Pro goes from `main` to a zip a store can install. The two plugins release the same way; the differences are at the end.

---

## What ships

The zip holds only what a store runs:

| Free | Pro |
|---|---|
| `submex.php`, `includes/`, `assets/`, `build/`, `templates/`, `languages/`, `readme.txt` | `submex-pro.php`, `includes/`, `assets/`, `build/`, `templates/` |

**Nothing else.** Not `docs/`, not the React source in `src/`, not `tools/`, not `vendor/` or `node_modules/`, not the CI or lint configuration.

That is enforced two ways, so a mistake stops the build instead of shipping:

- **`.distignore`** lists what to remove.
- **An allow list in `tools/build.sh`** fails the build on any top-level file that is not expected, so a new config file added to the repository cannot slip into a release unnoticed. The build also refuses `docs`, `src`, `node_modules`, `.git`, source maps and `.env` files anywhere in the zip, and lints every PHP file inside it.

The zip is built from **committed** files only (`git archive HEAD`), and its folder is always the real plugin slug — never the `submex-main` a GitHub download produces.

---

## Versions

`MAJOR.MINOR.PATCH`. While the major version is `0` the plugins are development releases, and GitHub marks every `0.x` release as a pre-release.

The version is written in several places — the plugin header, a PHP constant, the PHPStan bootstrap and, for the free plugin, the readme's `Stable tag`. **Never edit them by hand.** `tools/bump-version.sh` sets them all, and `tools/check-version.sh` fails CI if they ever disagree.

---

## Before the first release

- `composer install` and `npm ci` in the plugin.
- For Pro: the free plugin checked out beside it, and the `SUBMEX_FREE_REPO_TOKEN` secret set in the Pro repository.

---

## Cutting a release

From an up-to-date `main`:

**1. Write the changelog.** For the free plugin, add the entry at the top of `readme.txt`'s changelog, headed `= X.Y.Z =`. For Pro, add it at the top of `CHANGELOG.md`, headed `## X.Y.Z`. Add an Upgrade Notice too if updating changes something a merchant would notice. The release refuses to go ahead without the changelog entry.

**2. Rehearse it.**

```bash
tools/release.sh X.Y.Z --dry-run
```

This sets the version everywhere, runs **every** check — coding standards, PHPStan, JavaScript lint and tests, a rebuild that must match the committed `build/` — builds the zip and prints the release notes. It commits nothing and tags nothing; the version bump is left uncommitted so you can look at it.

**3. Cut it.**

```bash
tools/release.sh X.Y.Z
```

The same checks again, then a `Release X.Y.Z` commit, the zip, and an annotated `vX.Y.Z` tag. It does **not** push. Add `--push` to push in the same step.

**4. Push.**

```bash
git push origin main && git push origin vX.Y.Z
```

Pushing the tag starts the **Release** workflow, which does not trust the laptop that made it:

1. Re-runs every CI check.
2. Confirms the tagged commit is on `main`.
3. Confirms the plugin's version matches the tag.
4. Builds the zip and a SHA-256 checksum.
5. Publishes a GitHub release with both attached, and the changelog entry as its notes.

If any step fails, nothing is published.

---

## What `release.sh` refuses, and why

| It stops when | Because |
|---|---|
| You are not on `main` | Releases come from `main` |
| `main` is not level with `origin/main` | A release must be of what everyone else has |
| The tag already exists, locally or on GitHub | A version is released once |
| Anything other than the version files is uncommitted | Unrelated changes do not ride along in a release commit |
| The changelog entry is missing | Every release says what changed |
| Rebuilding changes `build/` | The committed JavaScript would not match its source |

---

## Building a zip without releasing

```bash
tools/build.sh
```

Writes `dist/<slug>-<version>.zip` and `<zip>.sha256`. It refuses uncommitted changes, because the zip comes from `HEAD` and would silently leave them out; `--allow-dirty` builds `HEAD` anyway, with a warning. `--out DIR` writes somewhere other than `dist/`.

Every push to `main` and every pull request also builds the zip in CI, as the **Plugin zip** job; download it from the run's artifacts.

## Rehearsing on GitHub

**Actions → Release → Run workflow**, with the version the plugin currently has. It runs every check and builds the zip as an artifact of the run, and publishes nothing.

---

## What CI checks on every push

| Job | Fails when |
|---|---|
| PHPStan and coding standards | PHPStan level 5 or the WordPress coding standard reports an error |
| PHP 8.1 / 8.2 / 8.3 / 8.4 syntax | Any shipped PHP file prints anything under that version — a deprecation included |
| Version consistency | The version disagrees anywhere, the changelog entry is missing, or (Pro) the `Update URI` header is gone |
| Plugin zip | The zip cannot be built, contains something it should not, or a PHP file in it fails lint |
| Lint, test and build | ESLint, a Jest test, or a build whose output differs from the committed `build/` |

---

## Differences for Submex Pro

- **Release notes** come from `CHANGELOG.md`, since Pro is not on wordpress.org and has no `readme.txt`. Without an entry for the version, they fall back to the commits since the previous tag.
- **When Pro needs a newer free plugin**, raise its minimum in the same bump: `tools/bump-version.sh X.Y.Z --min-free A.B.C`.
- **Release the free plugin first** when Pro depends on a free change. Pro's CI analyses against the free plugin's latest `main`, and fails on classes that are not there yet.
- **Customers get Pro updates from the licence server**, not from GitHub. The GitHub release is the artifact; uploading its zip to the licence server is still a manual step.
- The free plugin's docs name the Pro version. Update them there after a Pro release.

---

## Worth setting on GitHub

These are repository settings, not files, so nothing here configures them:

- **Protect `main`**: require the CI jobs above to pass before anything merges.
- **Protect `v*` tags**: allow only maintainers to create them, since a tag is what publishes a release.
