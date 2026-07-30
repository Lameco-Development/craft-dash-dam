# Dash DAM

Mounts the Dash ([dash.app](https://www.dash.app)) DAM as a **read-only** Craft 5 volume.
Dash owns the files; Craft gets real `craft\elements\Asset` elements, so native transforms,
`alt` text and every Assets field keep working untouched.

Private plugin (handle `dash-dam`) — not for the Plugin Store.

## Setup

Add the credentials to `.env` per environment. The plugin's credential settings hold
env-variable *references* (Craft env syntax, resolved at runtime), so project config
carries variable names and never secrets. The defaults reference the variables below —
with those set, the settings screen needs no touching. To use different variable names,
point the settings at them under **Settings → Dash DAM**.

```dotenv
DASH_CLIENT_ID=
DASH_CLIENT_SECRET=
DASH_SUBDOMAIN=
DASH_REFRESH_TOKEN=

# Optional — defaults to the primary site's origin
DASH_REDIRECT_URI=
```

Dash supports neither the client-credentials nor the password grant, so the refresh token
is obtained once in a browser:

```bash
php craft dash/auth
```

The token is a credential rather than an environment-bound value, so the same one works in
any environment using that API client. Dash does not rotate refresh tokens.

Then create a filesystem of type **Dash**, and a volume using it with a writable
`transformFs`. Name the volume whatever you like — the plugin finds it by its filesystem
type, not by its handle. Only one volume may use a Dash filesystem: the plugin is
single-tenant, and the sync refuses to run when more than one does.

## Where configuration lives

Three places, split by who owns each one:

| | Where | Owner |
|---|---|---|
| Credentials | `.env`, referenced from plugin settings | developer, per environment, never committed |
| Reconcile interval, orphan threshold, trash behaviour, transform batch size | **Settings → Dash DAM** (project config) | developer, committed |
| Which Dash folders are synced | **Utilities → Dash** (plugin table) | the client, changeable any time |

The middle row is version-controlled and applied on deploy, so it must not hold secrets —
which is why the credential settings hold `$DASH_CLIENT_ID`-style references rather than
values. The
bottom row deliberately is not: plugin settings go to project config, and a deploy applies
the committed YAML over whatever is there — so anything the client changes in the control
panel has to live outside it.

Settings can be overridden per environment from `config/dash-dam.php`:

```php
return [
    '*' => ['fullReconcileMinutes' => 30],
    'dev' => ['trashOrphans' => false],
];
```

## When an asset is in more than one Dash folder

Dash allows an asset any number of folders; Craft allows exactly one. The plugin picks the
Craft folder with a fixed rule: **folders inside the sync selection win, then the
alphabetically first full path**. An asset in no folder at all lands in `Unfiled`.

The rule is deterministic on purpose — it never depends on the order Dash happens to
return folder assignments, so the same library state always produces the same paths. It
also means an asset filed in both a selected and an unselected folder syncs into the
selected one rather than being skipped. Each multi-folder asset is noted in the log, in
case the ambiguity is worth cleaning up in Dash itself.

## Commands

```bash
php craft dash/sync            # probe, and reconcile only if something changed
php craft dash/sync --force    # reconcile regardless
php craft dash/probe           # report only, change nothing
php craft dash/auth            # one-time, interactive: get a refresh token
php craft dash/reset           # forget everything synced, to point at another tenant
```

## Pointing an environment at a different tenant

The mapping table is keyed on Dash asset UUIDs, so swapping the credentials makes every
mapped asset stop coming back from the search at once. That is indistinguishable from a bulk
deletion, and the reconcile refuses outright rather than trashing the library. `dash/reset`
is how you clear that state deliberately:

```bash
php craft dash/reset
```

It trashes the volume's assets, drops the mappings and clears the watermark — but keeps the
folder selection, which is configuration rather than synced state. Assets are trashed, not
erased, so a reset against the wrong environment is recoverable. It lists anything still in
use before asking, and refuses to run non-interactively unless given `--force`.

Do **not** use `sync --allowMassDeletion` for this. That flag is for a deletion that genuinely
happened in Dash; against a tenant switch it would trash the assets rather than forget them.

`sync` is the cron entry point, and the only one the integration needs:

```cron
*/5 * * * * cd /path/to/site && php craft dash/sync
```

A count-only Dash search is 49 bytes regardless of library size, so most of those runs exit
without doing work. The probe escalates to a full pass by itself once the last one is older
than the reconcile interval, which is how a replaced file gets noticed — there is no second
schedule to add.

Exit codes: `0` synced, `3` nothing changed, `4` another run holds the lock, `1` failed.
`3` and `4` are both normal for cron.

## Why the reconciler owns the lifecycle

Craft's `AssetIndexer` must **never** be run against a Dash volume. It matches on filename
plus folder id, so a move or rename in Dash reads as one file missing and one file new,
orphaning every relation pointing at the asset. It also learns width and height by reading
the file. Measured over 8 assets: 18.2 MB via the indexer against 11.2 KB when elements are
built from API data instead.

So `DashSync` owns create, move, retitle, alt, transform invalidation and deletion
detection, reconciling on the Dash asset UUID rather than on path.

## Tests

```bash
composer test                          # whole suite
vendor/bin/phpunit --testsuite unit    # pure logic only, no database needed
```

The integration suite boots a real Craft app against a disposable MySQL database and runs
the full reconcile lifecycle with only the Dash API faked. Copy `tests/.env.example` to
`tests/.env` and create the database it names — the harness drops every table in it on
each run, so the name must contain `test`. See `docs/adr/0001` for how the harness works
and why it is plain PHPUnit.
