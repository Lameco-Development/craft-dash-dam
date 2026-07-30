# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

Craft CMS 5 plugin, PHP 8.2+ (Composer platform pinned to 8.4). Handle `dash-dam`, package `lameco/craft-dash-dam`, namespace `lameco\dash`. No frontend build — the plugin ships Twig templates only.

## Commands

```bash
composer check-cs   # ECS, dry run
composer fix-cs     # ECS, applying fixes
composer phpstan    # PHPStan (config in phpstan.neon)
composer test       # PHPUnit — unit tests in tests/unit
```

## Repo operations

- PRs are squash-merged into `main`. The PR title must be a Conventional Commit (`feat:`/`fix:`/`refactor:`/`test:`/`chore:`/`ci:`/`docs:`) — it becomes the squash commit message that release-please reads.
- release-please owns versioning, tags, releases, and `CHANGELOG.md`; never edit those by hand. The first release is `1.0.0` (pinned via `initial-version` in `release-please-config.json`).
- CI (`.github/workflows/ci.yml`) runs ECS and PHPStan on PHP 8.2 and 8.4, on PRs and pushes to `main`. The `tests` job runs `composer test` (PHPUnit, unit only) but stays gated behind the `ENABLE_TESTS` repo variable until the full suite lands (issue #6).
- Branch protection on `main` stays off until the cut-over (issue #10): pre-cut-over syncs arrive as direct `git subtree split` pushes, which protection would block. Enable it (require PRs + green CI) as part of the cut-over.

## Agent skills

### Issue tracker

GitHub Issues on Lameco-Development/craft-dash-dam (pin `--repo` until the cut-over to a standalone checkout, issue #10). See `docs/agents/issue-tracker.md`.

### Triage labels

Default five-role vocabulary (`needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix`). See `docs/agents/triage-labels.md`.

### Domain docs

Single-context: `CONTEXT.md` + `docs/adr/` at the repo root. See `docs/agents/domain.md`.
