---
name: release
description: Version management and release processes using Jetpack Changelogger. Use when creating releases, managing changelogs, bumping versions, or preparing releases.
---

# ATmosphere Release Process

Quick-reference for cutting a release. Full reference: [`docs/release-process.md`](../../../docs/release-process.md).

## Major / Minor Release

```bash
npm run release
```

The script (`bin/release.js`) handles everything: changelog roll-up, version bump in `atmosphere.php` / `readme.txt` / `package.json`, `readme.txt` changelog mirror, `unreleased` marker replacement across `*.php`, optional Upgrade Notice prompt, then commits / pushes / opens a `Release X.Y.Z` PR.

**Prerequisites:** clean working tree, on `trunk` (or it'll switch you), `gh` CLI authenticated, at least one entry in `.github/changelog/`.

After the PR merges: draft the GitHub Release against `trunk` with the new tag.

## Patch Release

The script doesn't handle these — patches are cherry-picked manually onto the previous release branch:

```bash
git fetch origin release/X.Y.0
git checkout release/X.Y.0
git cherry-pick -m 1 <merge-commit-hash>
composer changelog:write
# Update readme.txt changelog block, bump versions in atmosphere.php / readme.txt / package.json,
# replace any remaining `unreleased` markers.
git push origin release/X.Y.0
# Open PR against release/X.Y.0; draft GitHub Release with the new tag targeting that branch.
```

Full step-by-step: [`docs/release-process.md → Patch Releases`](../../../docs/release-process.md#patch-releases).

## Changelog Entries

```bash
composer changelog:add       # Add one entry to .github/changelog/.
composer changelog:write     # Roll up entries into CHANGELOG.md (script does this for you).
```

**Required:** end the message with punctuation, write for end users (no class names, no AI/assistant references).

```
✅ Fix posts not appearing on Bluesky when published via Quick Edit.
✅ Add option to disable standard.site document records.
❌ Refactor Publisher class to handle edge case in applyWrites batch.
❌ Fix TID collision in transformer output.
```

## Marking Unreleased Code

When adding a new public hook, deprecation, or `_doing_it_wrong` call, use the literal `unreleased`. The release script rewrites it:

```php
/**
 * @since unreleased
 */

\_deprecated_function( __FUNCTION__, 'unreleased', 'new_function' );
\apply_filters_deprecated( 'atmosphere_old_filter', array( $value ), 'unreleased', 'atmosphere_new_filter' );
\_doing_it_wrong( __METHOD__, \__( 'Use new_method().', 'atmosphere' ), 'unreleased' );
```

## Semver Bumps

| Bump | When |
|------|------|
| **Major (X.0.0)** | Breaking public-hook signature changes, removed features, schema requires migration. |
| **Minor (0.X.0)** | New features, new public hooks, backward-compatible behaviour changes. |
| **Patch (0.0.X)** | Bug fixes only — no public hook changes. |

If any pending entry is marked `Significance: major`, the changelogger bumps to a new major version. Otherwise minor if any is `minor`, else patch.

## When to Read the Full Docs

Read [`docs/release-process.md`](../../../docs/release-process.md) when you need:

- The **full step-by-step** for major / minor releases.
- The **patch-release cherry-pick workflow** with conflict-resolution tips.
- **Version file locations** for manual bumps (patch path).
- **Manual steps** the script doesn't handle (distributable ZIP, wp.org SVN asset upload, GitHub Release tagging).
- **Troubleshooting** (branch-already-exists, empty changelog section, mirrored readme.txt looks wrong).

## Related Skills

- **pr** — branching, pre-PR checklist, changelog entry format.
- **dev** — wp-env, testing the release branch locally.
