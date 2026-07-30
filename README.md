# Laméco Dash

Mounts the Dash ([dash.app](https://www.dash.app)) DAM as a **read-only** Craft 5 volume.
Dash owns the files; Craft gets real `craft\elements\Asset` elements, so native transforms,
`alt` text and every Assets field keep working untouched.

Private plugin (handle `_craft-dash`) — not for the Plugin Store.

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
`transformFs`.

## Commands

```bash
php craft dash/sync            # probe, and reconcile only if something changed
php craft dash/sync --force    # reconcile regardless
php craft dash/probe           # report only, change nothing
php craft dash/auth            # one-time, interactive: get a refresh token
```

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
