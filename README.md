# Dash DAM

Mounts the Dash ([dash.app](https://www.dash.app)) DAM as a **read-only** Craft 5 volume.
Dash owns the files; Craft gets real `craft\elements\Asset` elements, so native transforms,
`alt` text and every Assets field keep working untouched.

Private plugin (handle `dash-dam`) — not for the Plugin Store.

## Setup

Add the credentials to `.env` per environment. They are deliberately **not** plugin
settings: settings serialise into project config, and secrets must not travel between
environments.

```dotenv
DASH_CLIENT_ID=
DASH_CLIENT_SECRET=
DASH_SUBDOMAIN=
DASH_REFRESH_TOKEN=

# Optional
DASH_REDIRECT_URI=
DASH_FULL_RECONCILE_MINUTES=30
DASH_MAX_ORPHAN_SHARE=0.1
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
| Credentials | `.env` | developer, per environment, never committed |
| Reconcile interval, orphan threshold, trash behaviour | **Settings → Dash DAM** (project config) | developer, committed |
| Which Dash folders are synced | **Utilities → Dash** (plugin table) | the client, changeable any time |

The middle row is version-controlled and applied on deploy, so it must not hold secrets. The
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
