---
name: pr
description: INVOKE THIS SKILL before creating any PR to ensure compliance with branch naming and reviewer assignment.
---

# ATmosphere PR Workflow

## Branch Naming

| Prefix | Use |
|--------|-----|
| `add/{feature}` | New features |
| `update/{feature}` | Iterating on existing features |
| `fix/{bug}` | Bug fixes |
| `try/{idea}` | Experimental ideas |

**Reserved:** `trunk` (main branch).

## Pre-PR Review

Before creating a PR, delegate to the **code-review** agent to review all changes on the branch. Address any critical issues before proceeding.

## PR Creation

**Every PR must:**
- Assign `@me`
- Pass CI checks
- Merge cleanly with trunk

```bash
# Create PR (includes required assignment)
gh pr create --assignee @me
```

## Workflow

### Create Branch
```bash
git checkout trunk && git pull origin trunk
git checkout -b fix/notification-issue
```

### Pre-Push Checks
```bash
composer lint         # PHP standards (composer lint:fix to auto-fix)
npm run env-test      # Run tests
```

### Keep Branch Updated
```bash
git fetch origin
git rebase origin/trunk
# Resolve conflicts if any
git push --force-with-lease
```

## Special Cases

**Hotfixes:** Branch `fix/critical-issue`, minimal changes, add "Hotfix" label, request expedited review.

**Experimental:** Use `try/` prefix, mark as draft, get early feedback, convert to proper branch type once confirmed.

**Multi-PR features:** Create tracking issue, link all PRs, use consistent naming (`add/feature-part-1`, etc.), merge in order.

## Labels

| Label | Use |
|-------|-----|
| `Bug` | Bug fixes |
| `Enhancement` | New features |
| `Documentation` | Doc updates |
| `Code Quality` | Refactoring, cleanup, etc. |
| `Needs Review` | Ready for review |
| `In Progress` | Still working |
| `Hotfix` | Urgent fix |
