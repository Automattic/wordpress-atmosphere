---
name: release
description: Version management and release processes using Jetpack Changelogger. Use when creating releases, managing changelogs, bumping versions, or preparing releases.
---

# ATmosphere Release Process

Quick reference for managing releases and changelogs for the WordPress ATmosphere plugin.

## Quick Reference

### Release Commands
```bash
bin/release.sh [version]    # Build release ZIP with production deps only.
composer changelog:add      # Add a changelog entry.
composer changelog:write    # Write changelog entries to CHANGELOG.md.
```

### Version File Locations
When updating versions manually, change these files:
- `atmosphere.php` - Plugin header (`Version: X.Y.Z`) and `ATMOSPHERE_VERSION` constant.
- `readme.txt` - WordPress.org readme (`Stable tag: X.Y.Z`).
- `package.json` - npm version (`"version": "X.Y.Z"`).
- `CHANGELOG.md` - Changelog file (auto-updated by changelogger).

## Changelog Management

### How It Works

Changelogs are managed through the Jetpack Changelogger:

1. **Adding entries:**
   ```bash
   composer changelog:add
   ```
   - Select significance: Patch/Minor/Major.
   - Select type: Added/Fixed/Changed/Deprecated/Removed/Security.
   - Write message **ending with punctuation!**
   - Saves to `.github/changelog/` directory.

2. **Writing changelog:**
   ```bash
   composer changelog:write
   ```
   - Aggregates all entries from `.github/changelog/`.
   - Updates `CHANGELOG.md` automatically.

### Critical Requirements

**Always end changelog messages with punctuation:**
```
Good: Add support for custom post types.
Good: Fix OAuth token refresh failing silently.
Bad:  Add support for custom post types
Bad:  Fix OAuth token refresh failing silently
```

**Write end-user friendly messages:**
- Focus on user benefit, not implementation details.
- Avoid technical jargon where possible.
- Describe what users can now do, not how it works internally.
```
Good: Fix posts not appearing on Bluesky when published via Quick Edit.
Good: Add option to disable standard.site document records.
Bad:  Refactor Publisher class to handle edge case in applyWrites batch.
Bad:  Fix TID collision in transformer output.
```

**Never mention AI tools or coding assistants in changelog messages.**

## Version Numbering

**Semantic versioning:**
- **Major (X.0.0)** - Breaking changes.
- **Minor (0.X.0)** - New features, backward compatible.
- **Patch (0.0.X)** - Bug fixes only.

## Release Workflow

```bash
# 1. Write changelog.
composer changelog:write

# 2. Update version numbers in all version file locations.

# 3. Commit version bump.
git add -A
git commit -m "Release version X.Y.Z"

# 4. Build release ZIP.
bin/release.sh X.Y.Z

# 5. Create GitHub release with the ZIP.
gh release create X.Y.Z atmosphere-X.Y.Z.zip --title "X.Y.Z" --generate-notes
```
