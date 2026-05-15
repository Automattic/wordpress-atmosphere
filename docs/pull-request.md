# Pull Request Guide

This guide covers the complete lifecycle of a pull request — from planning to merge.

## Table of Contents
- [Planning Your PR](#planning-your-pr)
- [Creating a Branch](#creating-a-branch)
- [Development Process](#development-process)
- [Changelog Entry](#changelog-entry)
- [Before Creating the PR](#before-creating-the-pr)
- [Creating the PR](#creating-the-pr)
- [After Creating the PR](#after-creating-the-pr)
- [Before Merge](#before-merge)
- [Commit Message Guidelines](#commit-message-guidelines)
- [Special Situations](#special-situations)
- [Common Review Feedback](#common-review-feedback)
- [Labels](#labels)

## Planning Your PR

**Break large features into small pieces — one PR per piece.** Long-running branches are harder to review, more likely to conflict with `trunk`, and tend to ship subtly different code than what was reviewed. If a PR grows past ~3 logical changes or its description starts needing sub-headings for distinct features, split it.

## Creating a Branch

All branches are cut from the latest `trunk`:

| Prefix | Use |
|--------|-----|
| `add/{something}` | New feature. |
| `update/{something}` | Iterating on an existing feature. |
| `fix/{something}` | Bug fix. |
| `try/{something}` | Experimental idea — open as a draft. |

**Reserved branch names:**

- `release/{X.Y.Z}` — owned by the release script (`npm run release`). Do not create these by hand.
- `trunk` — the integration branch.

```bash
git checkout trunk && git pull origin trunk
git checkout -b fix/notification-issue
```

## Development Process

### Develop and Commit

- Push your changes out frequently. Smaller commits are easier to review and reduce merge pain.
- Don't be afraid to rewrite history on a feature branch you own. Force-push with `--force-with-lease` (never plain `--force`) so you don't clobber someone else's push.
- Squash typo fixes and review-feedback commits into the original commits before review (or use [fixup commits with autosquash](http://fle.github.io/git-tip-keep-your-branch-clean-with-fixup-and-autosquash.html)).
- If you have [Composer installed](https://getcomposer.org/), run `composer lint` on the files you changed.

### Splitting Mid-PR

If the change grows beyond what fits in one focused PR, comment in the PR explaining the split, open the smaller follow-ups, and link them. Reviewers far prefer two small PRs to one sprawling one.

## Changelog Entry

Every PR must either:

- Include a changelog file in `.github/changelog/`, **or**
- Be labelled `Skip Changelog`.

```bash
composer changelog:add
```

The prompts walk through `Significance` (Patch / Minor / Major), `Type` (Added / Changed / Deprecated / Removed / Fixed / Security), and the message. The file is written to `.github/changelog/{slug}` — commit it on the same branch as your code change, not as a follow-up.

### Writing good entries

**Always end the message with punctuation:**

```
Good: Add support for custom post types.
Good: Fix OAuth token refresh failing silently.
Bad:  Add support for custom post types
Bad:  Fix OAuth token refresh failing silently
```

**Write for end users, not developers.** Messages appear in the WordPress update screen and in `readme.txt`:

```
Good: Fix posts not appearing on Bluesky when published via Quick Edit.
Good: Add option to disable standard.site document records.
Bad:  Refactor Publisher class to handle edge case in applyWrites batch.
Bad:  Fix TID collision in transformer output.
```

**Never reference AI tools, coding assistants, or internal class / method names** in the message.

See the [Release Process](release-process.md) for the full pipeline that consumes these entries.

## Before Creating the PR

Walk this checklist. Required checks are blocking.

### Code preparation
- [ ] Branch cut from latest `trunk`.
- [ ] Branch follows the `add/` / `update/` / `fix/` / `try/` convention.
- [ ] Changes are focused and single-purpose.
- [ ] No debug code, `var_dump`, `console.log`, or temporary `error_log` calls left in.
- [ ] Code follows [WordPress Coding Standards](php-coding-standards.md) and the project's [class structure](php-class-structure.md).

### Testing
- [ ] PHP tests pass: `npm run env-test`.
- [ ] Linting passes: `composer lint`.
- [ ] New behaviour is covered by a test where reasonable.
- [ ] No regressions in adjacent features (run the full suite, not just `--filter`).

### Documentation
- [ ] Changelog entry created via `composer changelog:add` and committed, **or** `Skip Changelog` will be applied.
- [ ] Changelog message ends with punctuation and is end-user friendly.
- [ ] Inline docblocks added or updated for new / changed public methods, hooks, constants.
- [ ] `README.md` / `readme.txt` updated if a user-visible feature changed.
- [ ] Relevant doc in `docs/` updated if architecture changed.

### Code review
- [ ] Delegate the diff to the **code-review** agent and address every critical finding before opening the PR.

## Creating the PR

Use the template at `.github/PULL_REQUEST_TEMPLATE.md` — don't invent custom formatting.

```bash
gh pr create --assignee @me
```

Every PR must:

- Be assigned to the author (`--assignee @me`).
- Include a changelog file **or** the `Skip Changelog` label.
- Have PHPCS + PHPUnit green.
- Merge cleanly with `trunk`.

### PR description
- [ ] Clear, descriptive title (under ~70 characters).
- [ ] Summary section explains *why*, not just *what* — the diff covers *what*.
- [ ] Testing instructions provided. Reviewer should be able to reproduce locally.
- [ ] Screenshots attached for any visual change (before / after).
- [ ] Linked issue (if applicable).

### Required checks
- [ ] All CI checks at the bottom of the PR are green.
- [ ] Branch merges cleanly. Rebase against `trunk` if there are conflicts.
- [ ] Changelog entry committed (or `Skip Changelog` label set).
- [ ] If the change is visual, before-and-after screenshots are in the PR description.
- [ ] If possible, add unit tests.
- [ ] Helpful testing instructions for the reviewer.

## After Creating the PR

### CI/CD
- [ ] All CI checks passing.
- [ ] No merge conflicts with `trunk`.
- [ ] Code coverage maintained or improved.

### Review process
- [ ] Reply to every review comment inline.
- [ ] Mark resolved threads as resolved.
- [ ] Re-request review after addressing feedback.
- [ ] If review surfaces a scope that should be its own PR, open a follow-up — don't expand the current PR.

## Before Merge

### Final checks
- [ ] Branch is up to date with `trunk`.
- [ ] All review feedback addressed.
- [ ] CI still green after the final push.
- [ ] Changelog entry still accurate (a rebase may have stale wording).
- [ ] No accidentally committed files (vendored deps, `.env`, screenshots in random folders).

### Clean history
- [ ] Commits are logical and well-organised.
- [ ] Fixup commits squashed.
- [ ] Commit messages are clear.
- [ ] No merge commits in the branch (prefer rebase over merge for keeping the branch updated).

## Commit Message Guidelines

```
Type: Brief description

Longer explanation if needed.
Multiple paragraphs are fine.

Fixes #123.
```

### Types

| Type | Use |
|------|-----|
| `Add:` | New feature. |
| `Fix:` | Bug fix. |
| `Update:` | Enhancement to an existing feature. |
| `Remove:` | Removed functionality. |
| `Refactor:` | Code restructuring without behaviour change. |
| `Test:` | Test additions or changes only. |
| `Docs:` | Documentation only. |

### Example

```
Fix: Skip update_post fresh-publish fallback for unsynced legacy posts

Routine edits of pre-Atmosphere WordPress posts no longer silently mint
a fresh Bluesky record. The retry path for failed initial publishes
(rkey reserved, URI never written) is preserved.

Fixes Automattic/fosse#145.
```

The first line is for everyone (PR list, release notes). Use the body for *why*, not *what* — the diff already explains *what*.

## Special Situations

### Hotfix PR
- [ ] Marked with the `Hotfix` label.
- [ ] Minimal changeset.
- [ ] Tested thoroughly despite urgency.
- [ ] Changelog marks as `patch` release.

### Breaking Change
- [ ] Marked with the `Breaking Change` label.
- [ ] Migration guide in the PR body.
- [ ] Deprecation notices added (`_deprecated_function`, `apply_filters_deprecated`) for removed/renamed public symbols.
- [ ] Major version bump indicated.

### New Feature
- [ ] `README.md` / `readme.txt` updated.
- [ ] Documentation added in `docs/` if the feature has its own surface.
- [ ] Performance impact assessed (mention in the PR body if non-trivial).
- [ ] Inline examples or docs entry for any new public hook.

### Bug Fix
- [ ] Root cause identified — explain it in the PR body, not just the symptom.
- [ ] Regression test added that points back at the issue / report.
- [ ] Related issues linked.
- [ ] Verified the fix doesn't break adjacent features.

### Experimental
- [ ] Use the `try/` prefix.
- [ ] Open as a draft PR.
- [ ] Once direction is confirmed, rename / recreate the branch under `add/` / `update/` / `fix/`.

### Multi-PR Feature
- [ ] Tracking issue opened.
- [ ] Every related PR linked.
- [ ] Consistent naming (`add/feature-part-1`, `add/feature-part-2`, …).
- [ ] PRs merged in order.

## Common Review Feedback

If a reviewer says any of the following, you should usually act on it directly rather than push back:

### Code quality
- "Please add error handling here." — surface `WP_Error`, don't swallow.
- "This could use a comment explaining why." — non-obvious behaviour or hidden constraints.
- "Consider extracting this." — long methods, repeated patterns.
- "Please add type hints." — match the rest of the file's strictness.

### Testing
- "Please add a test for this edge case."
- "Can you verify this works with [scenario]?"
- "What happens when [condition]?"

### Documentation
- "Please update the docblock."
- "The changelog entry needs more detail."
- "Can you add an example?"

### Performance
- "This could cause N+1 queries." — batch or cache.
- "Consider caching this result." — transient or object cache.
- "This might be expensive for large datasets." — add a hard cap or paginate.

## Labels

| Label | Use |
|-------|-----|
| `Bug` | Bug fix. |
| `Enhancement` | New feature. |
| `Documentation` | Documentation-only change. |
| `Code Quality` | Refactoring, cleanup. |
| `Breaking Change` | Public-API break. |
| `Skip Changelog` | No changelog entry needed (docs / CI only). |
| `Needs Review` | Ready for review. |
| `In Progress` | Draft, still working. |
| `Hotfix` | Urgent fix; expedite. |

## Resources

- [Development Environment Setup](development-environment.md)
- [PHP Coding Standards](php-coding-standards.md)
- [Class Structure](php-class-structure.md)
- [Code Linting](code-linting.md)
- [Release Process](release-process.md)
- [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/)
- [GitHub PR Documentation](https://docs.github.com/en/pull-requests)
