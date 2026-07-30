<?php

namespace lameco\dash\models;

use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;

/**
 * Developer-owned settings, version-controlled through project config.
 *
 * Project config is committed, and every deploy applies it — so the credential settings
 * hold env-variable references (`$DASH_CLIENT_ID`-style Craft env syntax, parsed at
 * runtime), never literal secrets. Client-owned configuration such as the folder selection
 * lives in the plugin's own table, which a deploy does not overwrite. See
 * services/DashConfig.php.
 *
 * Overridable per environment from `config/dash-dam.php`.
 */
class Settings extends Model
{
    /**
     * The Dash API client id, or an env reference. From Dash: Admin → Integrations → REST API.
     */
    public string $clientId = '$DASH_CLIENT_ID';

    /**
     * The Dash API client secret, or an env reference — never the literal secret.
     */
    public string $clientSecret = '$DASH_CLIENT_SECRET';

    /**
     * The tenant part of the Dash URL, e.g. "fivoor" from fivoor.dash.app, or an env reference.
     */
    public string $subdomain = '$DASH_SUBDOMAIN';

    /**
     * The OAuth refresh token `php craft dash/auth` produces, as an env reference — never
     * the literal token.
     */
    public string $refreshToken = '$DASH_REFRESH_TOKEN';

    /**
     * Where Dash sends the browser back to after authorising. Must exactly match a callback
     * URL registered on the Dash API client — Dash's identity layer is Auth0, which matches
     * path included, not by origin. Blank or unresolved, the primary site's origin is used.
     */
    public string $redirectUri = '$DASH_REDIRECT_URI';
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
     * How many assets one run may pre-generate image transforms for.
     *
     * Each one costs a download from Dash against a metered monthly allowance, so a first
     * pass over an established library is spread across runs. What is left over is reported,
     * never silently dropped, and the next run picks it up.
     */
    public int $maxAssetsPerRun = 25;

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

    protected function defineBehaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['clientId', 'clientSecret', 'subdomain', 'refreshToken', 'redirectUri'],
            ],
        ];
    }

    public function defineRules(): array
    {
        return [
            [['fullReconcileMinutes', 'maxOrphanShare', 'trashOrphans', 'maxAssetsPerRun'], 'required'],
            [['fullReconcileMinutes', 'maxAssetsPerRun'], 'integer', 'min' => 1],
            ['maxOrphanShare', 'number', 'min' => 0, 'max' => 1],
            ['trashOrphans', 'boolean'],
        ];
    }
}
