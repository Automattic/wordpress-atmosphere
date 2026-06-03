---
name: pr
description: INVOKE THIS SKILL before creating any PR to ensure compliance with branch naming, changelog requirements, and reviewer assignment.
---

# ATmosphere PR Workflow

Quick-reference for opening a PR. Full reference: [`docs/pull-request.md`](../../../docs/pull-request.md).

## Branch Naming

| Prefix | Use |
|--------|-----|
| `add/{feature}` | New feature. |
| `update/{feature}` | Iterating on an existing feature. |
| `fix/{bug}` | Bug fix. |
| `try/{idea}` | Experimental — open as draft. |

**Reserved:** `release/{X.Y.Z}` (owned by the release script), `trunk`.

```bash
git checkout trunk && git pull origin trunk
git checkout -b fix/something
```

## Required Before Opening

1. **Lint + tests must pass.**
   ```bash
   composer lint
   npm run env-test
   ```

2. **Changelog entry or `Skip Changelog` label.**
   ```bash
   composer changelog:add
   ```
   Commit the entry on the same branch as the code change. End the message with punctuation. Write for end users — no class names, no internal jargon.

   If neither a changelog entry nor a `Skip Changelog` label applies, **stop and ask the user** which they prefer. Don't open the PR with neither.

3. **Code review.** Delegate the diff to the **code-review** agent. Address every critical finding before opening the PR.

## Opening the PR

```bash
gh pr create --assignee @me
```

Use `.github/PULL_REQUEST_TEMPLATE.md` as-is — don't invent custom formatting. The body must include:

- Summary explaining *why* (not just *what* — the diff covers that).
- Testing instructions a reviewer can reproduce locally.
- Screenshots for any visual change (before / after).

## Changelog Messages

Write for end users; they appear in the WordPress update screen.

```
✅ Fix posts not appearing on Bluesky when published via Quick Edit.
✅ Add option to disable standard.site document records.
❌ Refactor Publisher class to handle edge case in applyWrites batch.
❌ Fix TID collision in transformer output.
```

End with punctuation. Never mention AI tools or coding assistants.

## When to Read the Full Docs

Read [`docs/pull-request.md`](../../../docs/pull-request.md) when you need:

- The **full pre-PR checklist** (code preparation, testing, documentation, review).
- **Commit message format** (the `Type: ` prefix table).
- **Special situations** — Hotfix, Breaking Change, New Feature, Bug Fix, Experimental, Multi-PR Feature checklists.
- **Common review feedback patterns** — what reviewers typically ask for and how to respond.
- **Label reference**.

## Related Skills

- **release** — release script, patch releases, GitHub Release UI.
- **dev** — running tests, linting, troubleshooting wp-env.
- **code-style** — PHP conventions the reviewer will be checking against.
