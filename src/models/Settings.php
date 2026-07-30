<?php

namespace lameco\dash\models;

use craft\base\Model;

/**
 * Developer-owned settings, version-controlled through project config.
 *
 * Only non-secret behaviour knobs belong here — project config is committed, and every
 * deploy applies it. Credentials stay in `.env`; client-owned configuration such as the
 * folder selection lives in the plugin's own table, which a deploy does not overwrite. See
 * services/DashConfig.php.
 *
 * Overridable per environment from `config/dash-dam.php`.
 */
class Settings extends Model
{
    /**
     * How long the cheap probe is trusted before a full pass runs regardless.
     *
     * This is the only detector that can see a file replacement. Replacing a file in Dash
     * sets the asset's `dateLastModified` to the replacement's `dateAdded`, so the stamp
     * lands whenever that file was first uploaded — in the past, and never inside the
     * `[watermark TO *]` window. The count probe is no help either, since a replacement adds
     * no asset. Spotting one needs per-asset checksums, which is the full pass; that pass
     * transfers no file bytes, so keeping this short is cheap.
     */
    public int $fullReconcileMinutes = 30;

    /**
     * The share of mapped assets that may vanish from Dash in one run before the reconcile
     * is refused outright. A folder unshared, a group permission narrowed and a partial API
     * response are indistinguishable from a bulk deletion from here, so losing a large share
     * at once is treated as a broken read rather than as intent. 1.0 disables the check.
     */
    public float $maxOrphanShare = 0.1;

    /**
     * Whether an asset deleted in Dash may be moved to Craft's trash. Never applied to one
     * that is still related to something — those are only ever reported. Set false to report
     * every deletion and trash nothing.
     */
    public bool $trashOrphans = true;

    /**
     * Which Dash field holds the title, by name, comma-separated and most specific first.
     *
     * Dash's own default is "Title", but the name follows whatever language the account was
     * set up in — Fivoor's is "titel". Matching is case-insensitive, and if none of these
     * exist the Craft title is left alone rather than blanked.
     */
    public string $titleFieldNames = 'Title, Titel';

    /**
     * Which Dash field holds alt text, by name, comma-separated and most specific first.
     *
     * Dash ships no alt-text field, so every account creates its own and names it whatever
     * suits — Fivoor's is "ALT-tekst". Matching is case-insensitive. The first name that
     * exists on the account wins, so several can be listed while a tenant settles on one.
     *
     * Nothing is synced if none of them match, which is deliberate: it distinguishes "this
     * account has no alt field" from "the field exists and is empty", and only the second
     * should ever put a value into Craft.
     */
    public string $altFieldNames = 'ALT-tekst, Alt tekst, Alt text, Alt Text (Accessibility), Alternative text, Alt, AltTextAccessibility';

    /**
     * @return string[]
     */
    public function altFieldNameList(): array
    {
        return self::splitNames($this->altFieldNames);
    }

    /**
     * @return string[]
     */
    public function titleFieldNameList(): array
    {
        return self::splitNames($this->titleFieldNames);
    }

    /**
     * @return string[]
     */
    private static function splitNames(string $names): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $names)), static fn(string $name) => $name !== ''));
    }

    public function defineRules(): array
    {
        return [
            [['fullReconcileMinutes', 'maxOrphanShare', 'trashOrphans'], 'required'],
            ['fullReconcileMinutes', 'integer', 'min' => 1],
            ['maxOrphanShare', 'number', 'min' => 0, 'max' => 1],
            ['trashOrphans', 'boolean'],
        ];
    }
}
