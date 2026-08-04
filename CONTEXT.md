# Dash DAM

Mounts a Dash (dash.app) asset library into Craft as a read-only volume. Dash is the system
of record for the files and their metadata; Craft holds the elements that reference them.

## Language

### The library

**Dash asset**:
An asset as Dash holds it, identified by a UUID that survives renames, moves and file
replacements.
_Avoid_: source asset, remote asset

**Asset element**:
The Craft element standing in for a Dash asset. Craft identifies assets by path; Dash does
not, which is why the two need a mapping.
_Avoid_: Craft asset, local asset

**Mapping**:
The record that a given asset element is a given Dash asset, together with what the last
sync saw of that file. Reconciling on the mapping rather than on path is what stops a move
in Dash reading as one file deleted plus one file created.
_Avoid_: link, association, id map

**Tenant**:
The Dash account an environment is pointed at. Switching tenants invalidates every mapping,
because the UUIDs belong to the old account.

### Scope

**Sync folder**:
A Dash folder an editor has chosen to bring into Craft. A chosen folder carries its
descendants. Choosing none syncs nothing.
_Avoid_: selected folder, included folder

**Canonical folder**:
The single Dash folder an asset is filed under in Craft, picked deterministically because
Dash allows many folders per asset and Craft allows one.

**In scope**:
Inside the chosen sync folders. An asset that leaves scope is left alone, never deleted —
narrowing the selection is not a deletion.

### The sync

**Probe**:
The cheap check for whether Dash has changed since the last reconcile. Never changes
anything.

**Reconcile**:
The full pass that brings the Craft side into step with Dash: creating, moving, retitling,
and detecting deletions.
_Avoid_: import, pull, refresh

**Watermark**:
When the last clean reconcile started. Doubles as "when everything was last verified",
because every reconcile rescans the whole library.

**Adopt**:
Claim an existing asset element that has no mapping yet, so a library synced before the
mapping existed is not recreated from scratch.

**Orphan**:
An asset element whose Dash asset no longer exists. Distinct from one that has merely left
scope.

**Missing since**:
When an orphan was first seen to be gone. Stamped once, so the control panel reports how
long an asset has been broken rather than how recently a sync noticed.

**In use**:
Referenced by another element. An orphan that is in use is reported rather than trashed,
because deleting it would break a live page.
_Avoid_: referenced, related, linked

**Reframe**:
The warning that an image changed shape or content while carrying a focal point, so that
focal point may now aim at a different part of the picture. Reported, never acted on —
Dash has no focal point to compare against.
