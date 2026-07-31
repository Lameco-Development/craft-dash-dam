# Dash DAM

Mounts the Dash ([dash.app](https://www.dash.app)) DAM as a **read-only** Craft 5 volume.
Dash owns the files; Craft gets real `craft\elements\Asset` elements, so native transforms,
`alt` text and every Assets field keep working untouched.

Private Laméco plugin. Every hard-to-reverse decision — handle, license model, settings
through project config — is Plugin Store-compatible by design, but a Store submission is a
deliberate future step, not part of v1.

## Requirements

- Craft CMS 5.0+
- PHP 8.2+
- A Dash account with REST API access (in Dash: **Admin → Integrations → REST API**)

## Installation

The package lives on GitHub, not Packagist, so add the repository to the project's
`composer.json` first:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/Lameco-Development/craft-dash-dam"
        }
    ]
}
```

The repository is private, so Composer needs a GitHub credential that can read it — a
token in `auth.json` (`composer config github-oauth.github.com <token>`) or whatever
Composer already uses for other Laméco packages. Then require and install:

```bash
composer require lameco/craft-dash-dam:^1.0
php craft plugin/install dash-dam
```

Installing creates the plugin's three `dash_*` tables: the Dash-id ↔ asset-id mapping, the
sync state, and the client's folder selection.

## Setup

### 1. Credentials

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

The client id and secret come from **Admin → Integrations → REST API** in Dash. The
subdomain is the tenant part of the Dash URL — `acme` from `acme.dash.app`. The redirect
URI must exactly match a callback URL registered on the Dash API client (Dash's identity
layer matches path included, not by origin).

### 2. Get a refresh token

Dash supports neither the client-credentials nor the password grant, so the refresh token
is obtained once in a browser:

```bash
php craft dash/auth
```

The command prints an authorisation URL; open it, authorise, and paste back the URL you
were redirected to (a 404 on that page is fine). Authorise as a dedicated integration
user, not a personal account — the token inherits the authorising user's permissions.

The token is a credential rather than an environment-bound value, so the same one works in
any environment using that API client. Dash does not rotate refresh tokens.

### 3. Create the filesystem and volume

In this order, because each step selects the previous one:

1. A **writable filesystem** for image transforms (e.g. Local), unless one exists to
   reuse. The Dash filesystem is read-only, so Craft needs somewhere else to write
   generated transforms.
2. A filesystem of type **Dash**.
3. A **volume** using the Dash filesystem, with **Transform Filesystem** set to the
   writable one (give it a subpath to keep transforms tidy).

Name the volume whatever you like — the plugin finds it by its filesystem type, not by its
handle.

### 4. Pick folders and run the first sync

Under **Utilities → Dash**, choose which Dash folders are synced. Selecting a folder
includes everything filed beneath it; with nothing selected, nothing is synced and the
sync refuses to run. Then:

```bash
php craft dash/sync
```

### 5. Add the cron entry

`sync` is the cron entry point, and the only one the integration needs:

```cron
*/5 * * * * cd /path/to/site && php craft dash/sync
```

A count-only Dash search is 49 bytes regardless of library size, so most of those runs exit
without doing work. The probe escalates to a full pass by itself once the last one is older
than the reconcile interval, which is how a replaced file gets noticed — there is no second
schedule to add.

## Single tenant

The plugin talks to exactly one Dash tenant. Only one volume may use a Dash filesystem:
the sync refuses to run when more than one does. Syncing several tenants — or one tenant
into several volumes — is out of scope for v1.

## Supported file types

Dash `IMAGE` and `VIDEO` assets sync; Audio, Document, Font and Dash's generic Other
bucket are skipped and counted in the run report, never silently dropped. The list is a
code-owned constant, not a setting, because each type on it has been verified end-to-end —
checksums compared at every layer to prove the original bytes arrive, not a preview
rendition. Widening it means verifying the new type the same way and shipping a release.

## Where configuration lives

Three places, split by who owns each one:

| | Where | Owner |
|---|---|---|
| Credentials | `.env`, referenced from plugin settings | developer, per environment, never committed |
| Reconcile interval, orphan threshold, trash behaviour, transform batch size | **Settings → Dash DAM** (project config) | developer, committed |
| Which Dash folders are synced | **Utilities → Dash** (plugin table) | the client, changeable any time |

The middle row is version-controlled and applied on deploy, so it must not hold secrets —
which is why the credential settings hold `$DASH_CLIENT_ID`-style references rather than
values. The bottom row deliberately is not: plugin settings go to project config, and a
deploy applies the committed YAML over whatever is there — so anything the client changes
in the control panel has to live outside it.

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

Exit codes: `0` synced, `3` nothing changed, `4` another run holds the lock, `1` failed.
`3` and `4` are both normal for cron.

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

## Uninstalling

Remove the volume first (**Settings → Assets**), then its Dash filesystem, then uninstall:

```bash
php craft plugin/uninstall dash-dam
```

Order matters: uninstalling removes the filesystem type, which turns any volume still
using it into a broken one Craft cannot clean up. Uninstalling drops the `dash_*` tables,
including the Dash-id mapping — a later reinstall starts from nothing, and its sync
creates new asset elements that old relations do not point at.

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
