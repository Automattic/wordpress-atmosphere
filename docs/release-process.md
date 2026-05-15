# Release Process

This document outlines the process for releasing the WordPress ATmosphere plugin. The process differs between major / minor releases (driven by the release script off `trunk`) and patch releases (cherry-picked onto a previous release branch).

## Table of Contents
- [Major and Minor Releases](#major-and-minor-releases)
- [Patch Releases](#patch-releases)
- [Changelog Management](#changelog-management)
- [Marking Unreleased Code](#marking-unreleased-code)
- [Version File Locations](#version-file-locations)
- [Manual Steps Around the Release](#manual-steps-around-the-release)
- [Troubleshooting](#troubleshooting)

## Major and Minor Releases

Major and minor releases share the same workflow. They are cut off `trunk` by the release script.

### Steps

1. **Generate the version-bump PR.**

   From a clean working tree on `trunk`:

   ```bash
   npm run release
   ```

   The script (`bin/release.js`):

   1. Runs `composer changelog:write` to roll up `.github/changelog/` entries into `CHANGELOG.md`. The next semver version is inferred from the entries' significance.
   2. Creates a `release/X.Y.Z` branch off `trunk`.
   3. Updates the version in `atmosphere.php` (plugin header `Version:` and the `ATMOSPHERE_VERSION` constant), `readme.txt` (`Stable tag:`), and `package.json`.
   4. Mirrors the new changelog block into `readme.txt`'s `== Changelog ==` section (same-major-version history, with a trailing link to the full GitHub `CHANGELOG.md`).
   5. Replaces `@since unreleased` / `@deprecated unreleased` and the equivalent `_deprecated_*` / `_doing_it_wrong` / `apply_filters_deprecated` literals across all `*.php` files (excluding `vendor/`).
   6. Prompts for an optional `== Upgrade Notice ==` entry.
   7. Commits, pushes the branch, and opens a `Release X.Y.Z` PR against `trunk` (reviewer: `Automattic/fediverse`).

2. **Review and merge the PR.**

   Walk the diff:

   - Versions match across `atmosphere.php`, `readme.txt`, `package.json`.
   - `CHANGELOG.md` and `readme.txt` changelog blocks match.
   - `unreleased` markers were replaced.
   - The Upgrade Notice (if any) makes sense to a non-technical user.

   Once approved, merge into `trunk`.

3. **Create the GitHub Release.**

   - Repository → **Releases** → **Draft a new release**.
   - **Choose a tag:** type `X.Y.Z`, click **Create new tag**.
   - **Target:** `trunk`.
   - **Previous tag:** the most recent prior tag.
   - **Generate release notes** to seed the body.
   - Trim the auto-generated notes to match the `CHANGELOG.md` voice (end-user friendly).
   - Attach the distributable ZIP if one was built (see [Manual Steps](#manual-steps-around-the-release)).
   - **Publish release.**

### Prerequisites

- The `gh` CLI installed and authenticated.
- Working tree clean — the script does `git checkout trunk` + `git pull origin trunk` before branching.
- At least one entry in `.github/changelog/` (otherwise `composer changelog:write` will fail).

## Patch Releases

The release script only handles major / minor. Patch releases (e.g. `1.2.1` on top of `1.2.0`) are cherry-picked onto the previous release branch manually.

### Steps

1. **Restore the release branch.**

   Find the most recent `release/X.Y.0` branch on GitHub. If it was deleted after merge, click **Restore branch**. Then locally:

   ```bash
   git fetch origin release/X.Y.0
   git checkout release/X.Y.0
   ```

2. **Cherry-pick the fixes from `trunk`.**

   Identify the merge commits that need to ride this patch (the bottom of each PR shows the merge hash). Cherry-pick each one with `-m 1`:

   ```bash
   git cherry-pick -m 1 <merge-commit-hash>
   ```

   `-m 1` tells Git to apply the changes as they appeared on the main-branch side of the merge. Resolve conflicts as they come up.

3. **Update changelog and version numbers manually.**

   ```bash
   composer changelog:write
   ```

   This adds a new section to `CHANGELOG.md` from the entries the cherry-picked PRs brought in and prints the next patch version. Then:

   - Edit `readme.txt` and paste the new section into `== Changelog ==`.
   - Bump `Version:` and `ATMOSPHERE_VERSION` in `atmosphere.php`.
   - Bump `Stable tag` in `readme.txt`.
   - Bump `"version"` in `package.json`.
   - Replace any `unreleased` markers (`@since unreleased`, `_deprecated_*`, etc.) introduced by the cherry-picked commits.

4. **Push and open a PR.**

   ```bash
   git push origin release/X.Y.0
   ```

   Open a PR from `release/X.Y.0` against `release/X.Y.0` for review (the patch release is merged back into its own branch, not `trunk`).

5. **Create the GitHub Release.**

   - Repository → **Releases** → **Draft a new release**.
   - **Choose a tag:** type `X.Y.Z`, click **Create new tag**.
   - **Target:** the `release/X.Y.0` branch (not `trunk`).
   - **Previous tag:** the previous patch (or the `.0` if this is the first patch).
   - **Generate release notes** to seed the body.
   - **Publish release.**

## Changelog Management

Changelogs are managed via the [Jetpack Changelogger](https://github.com/Automattic/jetpack/tree/trunk/projects/packages/changelogger).

### Add an entry

```bash
composer changelog:add
```

Prompts:
- **Significance** — Patch / Minor / Major.
- **Type** — Added / Changed / Deprecated / Removed / Fixed / Security.
- **Message** — end with punctuation, end-user friendly.

Result: a new file at `.github/changelog/{slug}`. Commit it on the same branch as your code change.

### Write the changelog

```bash
composer changelog:write
```

Aggregates `.github/changelog/*` into `CHANGELOG.md`, picks the next semver version from the significance, and clears the changelog folder. The release script runs this automatically; you only need to invoke it directly for patch releases.

See [Pull Request Guide → Changelog Entry](pull-request.md#changelog-entry) for writing guidance.

## Marking Unreleased Code

When adding a new public hook, deprecation, or `_doing_it_wrong` call, use the literal `unreleased` in the version slot. The release script rewrites it at release time:

```php
/**
 * @since unreleased
 */

\_deprecated_function( __FUNCTION__, 'unreleased', 'new_function' );
\apply_filters_deprecated( 'atmosphere_old_filter', array( $value ), 'unreleased', 'atmosphere_new_filter' );
\_doing_it_wrong( __METHOD__, \__( 'Use new_method() instead.', 'atmosphere' ), 'unreleased' );
```

`version_compare()` callsites that gate behaviour by version can also use `'unreleased'`; the script rewrites them too.

Do not hardcode a future version. Existing already-released version tags in the codebase are fine.

## Version File Locations

For patch releases (where the script doesn't help), bump these files:

- `atmosphere.php` — plugin header `Version: X.Y.Z` and `ATMOSPHERE_VERSION` constant.
- `readme.txt` — `Stable tag: X.Y.Z`.
- `package.json` — `"version": "X.Y.Z"`.
- `CHANGELOG.md` — auto-updated by `composer changelog:write`.
- Any PHP file with `@since unreleased` / `@deprecated unreleased` introduced by the cherry-picked commits.

## Manual Steps Around the Release

The release script does not handle these — do them yourself:

### Build the distributable ZIP

After the release PR merges and the tag is in place:

```bash
git checkout X.Y.Z
composer install --no-dev --optimize-autoloader
git archive HEAD --format=zip -o atmosphere-X.Y.Z.zip
```

`git archive` respects `.gitattributes export-ignore`, so `tests/`, `node_modules/`, `bin/`, `docs/`, etc. don't ship in the ZIP.

### Upload WordPress.org assets

`.wordpress-org/` (banner, icon) is committed to git for review but lives in the wp.org plugin SVN, not the release ZIP. Push asset updates through the WordPress.org plugin team's SVN workflow.

### Tag and publish the GitHub Release

After the version-bump PR merges, follow the GitHub Release UI steps above. Attach the ZIP if you built one.

## Troubleshooting

### "Branch release/X.Y.Z already exists"

The script aborts if a release branch is left over. Delete it (`git branch -D release/X.Y.Z`) and re-run. If a release PR is already open, close or finish it first.

### `composer changelog:write` fails or produces an empty section

No entries in `.github/changelog/`. Add at least one with `composer changelog:add` before running the release script.

### Mirrored `readme.txt` changelog looks wrong

The script formats `#### Type` subheadings from the Jetpack Changelogger type list and strips trailing `[#123]` PR references. If the output looks off, check the type / significance fields in each `.github/changelog/{slug}` file.

### Released the wrong version

Don't try to "undo" a published release. Open a follow-up patch release on top of the same `release/X.Y.0` branch and roll forward.
