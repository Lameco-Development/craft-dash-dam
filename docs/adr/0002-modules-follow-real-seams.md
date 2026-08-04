# ADR 0002: Modules follow real seams, not table symmetry

Date: 2026-08-04
Status: accepted

## Context

Lifting the mapping out of `DashSync` left the plugin looking inconsistent, and
inconsistency invites someone to "finish the job". Three questions came up, and the
answers are asymmetric on purpose.

`dash_asset_map` got `DashAssetMap`. Sixteen raw-SQL sites across `DashSync` and
`DashController` addressed that table directly, and four callers ask it questions for four
different reasons.

`dash_sync_state` did not get a module. `probe()` reads it, `reconcileAndAdvance()` writes
it, `reset()` clears it, and exactly one caller outside the sync reads it — `DashUtility`,
to show when the last run happened. Extracting it would move the private `state()` and
`setState()` helpers to a class of their own and change nothing else.

The `{{%relations}}` in-use query got `AssetUsage`, even though it is not a Dash concern at
all. Four callers need it, and while it lived on `DashSync` the transforms pass had to call
back into the sync — a runtime cycle between two modules that each called the other through
`Plugin::getInstance()`.

## Decision

A seam earns a module when something actually varies across it, which in practice means more
than one caller with more than one reason. One consumer is a hypothetical seam; two are a
real one. Table symmetry is not a reason on its own.

Collaborators keep resolving through `Plugin::getInstance()` rather than being injected,
matching the sixteen existing call sites.

## Consequences

- The asymmetry is deliberate. `dash_sync_state` staying inside `DashSync` is not an
  oversight, and extracting it for consistency would add an interface without adding a seam.
- `AssetUsage` holds no Dash-specific logic. That is fine: it is named for the question it
  answers, not for the system it belongs to.
- Static analysis cannot see collaborators resolved through the service locator, so tooling
  that reads the call graph will under-report how coupled — or how decoupled — these modules
  are. The `DashSync`/`DashTransforms` cycle this refactor removed was invisible for exactly
  that reason. Injecting them everywhere is a reasonable future change; it is a plugin-wide
  convention change, not something to do to two modules in passing.
