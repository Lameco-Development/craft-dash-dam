# ADR 0001: Plain PHPUnit with a hand-rolled Craft boot for the test suite

Date: 2026-07-30
Status: accepted

## Context

Issue #6 needs two kinds of tests: pure unit tests with no Craft boot, and
integration tests that run the whole reconcile lifecycle against a real test
database with only the Dash API stubbed. Both must run locally and in GitHub
Actions on PHP 8.2 and 8.4. The repo already carries plain PHPUnit ^11.5 unit
tests — issue #5 deliberately picked the least-commitment runner and deferred
the framework decision to this ticket.

Three candidates, researched 2026-07-30:

**Craft's official Codeception framework** (`craft\test\TestSetup` +
`codeception/module-yii2`). Pixel & Tonic still uses it internally — cms 5.x
pins `codeception/codeception ^5.2`, commerce `^5.0.11` — but the testing
chapter was dropped from the 5.x docs entirely (4.x has `docs/testing/`, 5.x
does not). For third-party plugin authors it is official but undocumented and
in maintenance limbo. Adopting it would put the suite on `Codeception\Test\Unit`
— a second runner style over the existing PHPUnit tests plus codeception.yml
suite config — for functionality this plugin needs a fraction of.

**Pest tooling** (`markhuot/craft-pest-core`). Actively maintained (3.2.2,
2026-04-07; Craft ^4.5|^5, Pest ^2.26|^3|^4) and the loudest community option.
But it is designed around an installed Craft *project* (`./craft pest`); a
standalone plugin repo needs a Craft app skeleton either way, the runner would
change to Pest, and a sizeable third-party layer would own the app/database
lifecycle that these tests care most about.

**Plain PHPUnit 11 with a small hand-rolled boot** borrowing TestSetup's own
technique. `craftcms/cms` is already a direct dependency, and
`craftcms/plugin-installer` registers the root package itself in
`vendor/craftcms/plugins.php`, so plugin discovery works with zero shims. The
boot is the same sequence TestSetup performs: bootstrap a console app from env
vars, cleanse the test database, run `craft\migrations\Install`, install the
plugin, seed a Dash filesystem + volume, then rebuild the app and wrap each
test in a rolled-back DB transaction (module-yii2's cleanup strategy).

## Decision

Plain PHPUnit 11 for the whole suite, with a hand-rolled Craft boot for the
integration tests (`tests/integration/`). The Dash API is faked at the
transport seam — `DashApi::get()`/`post()`/`fetch()` — so the real pagination,
envelope-unwrapping and folder-tree logic stays under test.

## Consequences

- One runner, one config, no new dependencies; the existing unit tests stay
  untouched and ECS/PHPStan keep scanning everything.
- We own the boot code and track Craft's boot changes ourselves. Accepted: the
  undocumented `craft\test` surface carries the same risk, with less control.
- A later switch to Pest stays cheap if the DX is ever wanted — Pest runs
  classic PHPUnit TestCases as-is.
