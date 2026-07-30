<?php

namespace lameco\dash\console\controllers;

use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use lameco\dash\errors\DashApiException;
use lameco\dash\Plugin;
use lameco\dash\services\DashSync;
use Throwable;
use yii\console\ExitCode;

/**
 * Syncs the `dash` volume with the Dash DAM.
 *
 *     php craft dash/sync            # probe, and reconcile only if something changed
 *     php craft dash/sync --force    # reconcile regardless
 *     php craft dash/probe           # report only, change nothing
 *     php craft dash/auth            # one-time, interactive: get a refresh token
 *     php craft dash/reset           # forget everything synced, to point at another tenant
 *
 * Dash has no webhooks, so change detection is polling — but a count-only search is 49
 * bytes regardless of library size, which makes running this every few minutes from cron
 * affordable. One command rather than two because the order is load-bearing: reconcile
 * has to happen before anything else touches the volume.
 *
 * One cron entry running `sync` every few minutes is enough: the probe escalates to a
 * full pass by itself once the last one is older than DashSync::$fullReconcileMinutes,
 * which is how file replacements are caught — no second scheduled command needed.
 */
class DashController extends Controller
{
    /** Nothing changed, so nothing was done — distinct from both success and failure. */
    private const EXIT_UNCHANGED = 3;

    /** A previous run is still going. Benign for cron, but worth telling apart. */
    private const EXIT_LOCKED = 4;

    /**
     * @var bool for `sync`, reconcile even when the probe reports no changes; for `reset`,
     *           skip the confirmation prompt
     */
    public bool $force = false;

    /** @var bool proceed even when most mapped assets have vanished from Dash */
    public bool $allowMassDeletion = false;

    public $defaultAction = 'sync';

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), match ($actionID) {
            'sync' => ['force', 'allowMassDeletion'],
            'reset' => ['force'],
            default => [],
        });
    }

    /**
     * Forgets everything synced from Dash, so this environment can be pointed at a different
     * tenant.
     *
     * Needed because the mapping table is keyed on Dash asset UUIDs. Swap the credentials
     * without clearing it and every mapped asset stops coming back from the search at once,
     * which is indistinguishable from a bulk deletion — refuseMassDisappearance() then
     * refuses to reconcile, correctly, and the sync never runs again until this is done.
     *
     * Do not reach for `sync --allowMassDeletion` instead. That flag is for a deletion that
     * genuinely happened in Dash; against a tenant switch it would trash the assets rather
     * than forget them.
     *
     * The folder selection is deliberately left alone — it is configuration, not synced
     * state, and it lives in its own table for exactly this reason.
     */
    public function actionReset(): int
    {
        try {
            $sync = $this->sync();
            $volume = Craft::$app->getVolumes()->getVolumeByHandle(DashSync::VOLUME_HANDLE);

            if ($volume === null) {
                $this->stderr("No volume with handle '" . DashSync::VOLUME_HANDLE . "'.\n", Console::FG_RED);

                return ExitCode::UNSPECIFIED_ERROR;
            }

            $assetIds = Asset::find()->volumeId($volume->id)->status(null)->ids();
            $uses = $sync->usageCounts($assetIds);
            $mapped = (int)Craft::$app->getDb()
                ->createCommand('SELECT COUNT(*) FROM {{%dash_asset_map}}')->queryScalar();

            $this->stdout("\nThis will forget everything synced from Dash on this environment:\n\n");
            $this->stdout('  ' . count($assetIds) . " asset(s) in the '" . DashSync::VOLUME_HANDLE . "' volume → trash\n");
            $this->stdout("  {$mapped} Dash id mapping(s) → deleted\n");
            $this->stdout("  the sync watermark → cleared\n");
            $this->stdout("  the folder selection → kept\n");

            if ($uses !== []) {
                $this->stdout("\n");
                $this->stdout(count($uses) . " of those assets are still used by other elements:\n", Console::FG_YELLOW);

                foreach ($uses as $assetId => $count) {
                    $asset = Craft::$app->getElements()->getElementById((int)$assetId, Asset::class);
                    $this->stdout(sprintf(
                        "  #%s  %s  — used by %d element(s)\n",
                        $assetId,
                        $asset !== null ? $asset->getPath() : '(not loadable)',
                        $count,
                    ), Console::FG_YELLOW);
                }

                $this->stdout("Whatever references them will render without an image until something else is picked.\n", Console::FG_YELLOW);
            }

            // Trashed rather than hard-deleted, so a reset against the wrong environment is
            // recoverable. Craft's garbage collection clears them out later.
            $this->stdout("\nAssets are moved to the trash, not erased.\n");

            if (!$this->force) {
                if (!$this->interactive) {
                    $this->stderr("\nRefusing to reset non-interactively. Re-run with --force if this is scripted.\n", Console::FG_RED);

                    return ExitCode::UNSPECIFIED_ERROR;
                }

                if (!$this->confirm("\nGo ahead?")) {
                    $this->stdout("Nothing was changed.\n");

                    return ExitCode::OK;
                }
            }

            $counts = $sync->reset();

            $this->stdout(sprintf(
                "\n%d asset(s) trashed, %d mapping(s) removed, watermark cleared.\n",
                $counts['trashed'],
                $counts['unmapped'],
            ), Console::FG_GREEN);

            if ($counts['failed'] > 0) {
                $this->stderr("{$counts['failed']} asset(s) could not be trashed — see the logs.\n", Console::FG_RED);
            }

            $this->stdout("\nPoint .env at the new tenant, then run `php craft dash/probe`.\n");
        } catch (Throwable $e) {
            return $this->fail($e, 'Reset failed.');
        }

        return ExitCode::OK;
    }

    /**
     * Probes Dash for changes and reconciles the volume if there are any.
     */
    public function actionSync(): int
    {
        try {
            $sync = $this->sync();
            $probe = $sync->probe();
            $this->reportProbe($probe);

            if (!$probe['changed'] && !$this->force) {
                $this->stdout("\nNothing changed. Skipping sync. (--force to override)\n");

                return self::EXIT_UNCHANGED;
            }

            $this->stdout("\n=== reconcile ===\n");
            $counts = $sync->reconcileAndAdvance($probe['now']);

            if ($counts === null) {
                $this->stdout("\nAnother sync already holds the lock — skipping.\n");

                return self::EXIT_LOCKED;
            }

            $this->stdout("\n" . $this->summarise($counts) . "\n");
            $this->stdout("Watermark advanced to {$probe['now']}\n");
        } catch (Throwable $e) {
            return $this->fail($e, 'Sync failed — watermark not advanced, so the next run retries.');
        }

        return ExitCode::OK;
    }

    /**
     * Obtains a Dash refresh token via the OAuth Authorization Code Flow.
     *
     * Dash supports neither the client-credentials nor the password grant, so a human has
     * to complete this in a browser once per API client — the resulting token is a
     * credential, not environment-bound, so it can be pasted into any environment using
     * that client. Dash does not rotate refresh tokens, so there is no second run unless
     * one is revoked. The token also inherits the authorising user's permissions, which is
     * why it should be a dedicated integration user rather than someone's own account.
     *
     * @param string|null $redirectUrl the URL you were redirected to, or the bare code.
     *                                 Omit it to be prompted, which avoids the shell
     *                                 mangling the & in a pasted URL.
     */
    public function actionAuth(?string $redirectUrl = null): int
    {
        try {
            $api = Plugin::getInstance()->getDashApi();

            if ($redirectUrl === null) {
                $this->stdout("1. Open this URL in a browser and authorise:\n\n");
                $this->stdout($api->authorizeUrl() . "\n\n", Console::FG_CYAN);
                $this->stdout("2. You'll be redirected to {$api->redirectUri()} (a 404 is fine).\n");

                if (!$this->interactive) {
                    return $this->explainManualStep();
                }

                // Deliberately not `required`: on EOF prompt() would re-ask forever.
                $redirectUrl = $this->prompt('   Paste the full URL from the address bar:');

                if (trim($redirectUrl) === '') {
                    return $this->explainManualStep();
                }
            }

            $tokens = $api->exchangeAuthorizationCode($this->extractCode($redirectUrl));
            $envName = $api->refreshTokenEnvName();

            if ($envName !== null) {
                $this->stdout("\nToken exchange OK. Add this to .env:\n\n");
                $this->stdout($envName . '="' . $tokens['refresh_token'] . "\"\n\n", Console::FG_GREEN);
            } else {
                $this->stdout("\nToken exchange OK. Store this wherever the refreshToken plugin setting reads it:\n\n");
                $this->stdout($tokens['refresh_token'] . "\n\n", Console::FG_GREEN);
            }
            $this->stdout('access token lifetime : ' . (isset($tokens['expires_in'])
                ? $tokens['expires_in'] . 's (' . round((int)$tokens['expires_in'] / 3600, 1) . 'h)'
                : 'not reported') . "\n");
            $this->stdout('granted scopes        : ' . ($tokens['scope'] ?? 'not reported') . "\n");

            return ExitCode::OK;
        } catch (Throwable $e) {
            return $this->fail($e, 'Authorization failed.');
        }
    }

    private function explainManualStep(): int
    {
        $this->stdout("   Copy the FULL url from the address bar, then run:\n\n");
        $this->stdout("   php craft dash/auth '<paste-the-url-here>'\n\n");
        $this->stdout("   Quote it — the URL contains & and your shell will otherwise mangle it.\n");

        return ExitCode::OK;
    }

    /**
     * Pull the authorization code out of whatever was pasted — the full redirect URL, or
     * the bare code. Pasting a URL into a shell often escapes ? & =, which would
     * otherwise be read as part of the parameter name.
     */
    private function extractCode(string $input): string
    {
        $input = str_replace('\\', '', trim($input));

        if (str_contains($input, 'error=')) {
            preg_match('/[?&]error=([^&\s]+)/', $input, $error);
            preg_match('/[?&]error_description=([^&\s]+)/', $input, $description);

            throw new DashApiException(
                'Dash returned an error: ' . urldecode($error[1] ?? 'unknown') . "\n" . urldecode($description[1] ?? ''),
            );
        }

        if (str_contains($input, 'code=')) {
            if (!preg_match('/[?&]code=([^&\s]+)/', $input, $matches)) {
                throw new DashApiException("Found 'code=' but couldn't extract it from:\n{$input}");
            }

            return urldecode($matches[1]);
        }

        if (str_contains($input, '://')) {
            throw new DashApiException(
                "That looks like a URL but has no ?code= in it:\n{$input}\n"
                . 'Paste the full address you were redirected to, or just the code itself.',
            );
        }

        return $input;
    }

    /**
     * Reports whether Dash has changed since the last sync, without changing anything.
     */
    public function actionProbe(): int
    {
        try {
            $probe = $this->sync()->probe();
            $this->reportProbe($probe);
            $this->stdout("\n" . ($probe['changed'] ? 'CHANGED — a sync would run' : 'no changes') . " (probe only)\n");

            return $probe['changed'] ? ExitCode::OK : self::EXIT_UNCHANGED;
        } catch (Throwable $e) {
            return $this->fail($e, 'Probe failed.');
        }
    }

    private function sync(): DashSync
    {
        $sync = Plugin::getInstance()->getDashSync();
        $sync->logger = fn(string $line) => $this->stdout($line . "\n");

        if ($this->allowMassDeletion) {
            $sync->maxOrphanShare = 1.0;
        }

        return $sync;
    }

    private function reportProbe(array $probe): void
    {
        $this->stdout('watermark      : ' . ($probe['watermark'] ?? '(none — first run)') . "\n");
        $this->stdout('modified since : ' . ($probe['modified'] ?? 'n/a') . "\n");
        $this->stdout("assets in Dash : {$probe['remoteTotal']}   (mapped in Craft: {$probe['mappedTotal']})\n");

        if ($probe['countChanged']) {
            $this->stdout($probe['knownTotal'] === null
                ? "  → no previous count recorded: treating as changed\n"
                : "  → count changed since the last sync ({$probe['knownTotal']} → {$probe['remoteTotal']}): something was added or deleted\n");
        }

        if ($probe['stale']) {
            $minutes = $probe['watermarkAgeMinutes'];
            $this->stdout(sprintf(
                "  → last full pass %s ago: reconciling anyway (a replaced file inherits its old upload date, so the probe cannot see one)\n",
                $minutes < 90 ? sprintf('%.0f min', $minutes) : sprintf('%.1fh', $minutes / 60),
            ));
        }
    }

    private function summarise(array $counts): string
    {
        return sprintf(
            '%d created, %d moved, %d retitled, %d alt-synced, %d resized, %d content-changed, %d trashed%s%s%s%s%s%s%s%s',
            $counts['created'],
            $counts['moved'],
            $counts['retitled'],
            $counts['altSynced'],
            $counts['resized'],
            $counts['restamped'],
            $counts['trashed'],
            $counts['returned'] > 0 ? ", {$counts['returned']} back in Dash" : '',
            $counts['outOfScope'] > 0 ? ", {$counts['outOfScope']} left alone (outside selected folders)" : '',
            $counts['transformed'] > 0 ? ", {$counts['transformed']} transformed" : '',
            $counts['transformsDeferred'] > 0 ? ", {$counts['transformsDeferred']} awaiting transforms" : '',
            $counts['foldersPruned'] > 0 ? ", {$counts['foldersPruned']} empty folder(s) removed" : '',
            $counts['inUse'] > 0 ? ", {$counts['inUse']} GONE FROM DASH BUT STILL IN USE" : '',
            $counts['skippedUnsupported'] > 0 ? ", {$counts['skippedUnsupported']} skipped (unsupported file type)" : '',
            $counts['failed'] > 0 ? ", {$counts['failed']} FAILED" : '',
        );
    }

    private function fail(Throwable $e, string $context): int
    {
        $this->stderr("\n{$context}\n", Console::FG_RED);
        $this->stderr($e->getMessage() . "\n", Console::FG_RED);
        Craft::error($e, __METHOD__);

        return ExitCode::UNSPECIFIED_ERROR;
    }
}
