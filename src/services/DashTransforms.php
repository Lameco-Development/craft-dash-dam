<?php

namespace lameco\dash\services;

use Craft;
use craft\elements\Asset;
use craft\imagetransforms\ImageTransformer;
use craft\models\ImageTransform;
use lameco\dash\Plugin;
use Throwable;
use yii\base\Component;

/**
 * Generates image transforms for Dash assets ahead of the first page view.
 *
 * Without this, the first render of a page showing a Dash image is *uncacheable*.
 * ImageTransformer::getTransformUrl() takes a different path when the transform does not
 * exist yet and `generateTransformsBeforePageLoad` is false: it queues a generation job,
 * returns a temporary `assets/generate-transform` action URL, and calls
 * `setNoCacheHeaders()` on the response. This site runs Blitz, so that means the page is
 * skipped by the static cache — and had it been cached, the HTML would carry an action URL
 * instead of a real transform URL.
 *
 * Only assets something actually relates to are worth generating for. On this install 1 of 9
 * Dash assets is referenced, and that ratio is the point of a DAM: most of the library is
 * never on the site. It is also what keeps this affordable — every asset generated for costs
 * one Dash download against a metered monthly allowance.
 */
class DashTransforms extends Component
{
    /**
     * How many assets one run may generate for.
     *
     * Each one costs a download from Dash, and the allowance is monthly, so a first pass over
     * an established library has to be spread across runs rather than taken in one go. What
     * is left over is reported, never silently dropped, and the next run picks it up.
     */
    public int $maxAssetsPerRun = 25;

    /** @var callable|null called with each progress line */
    public $logger = null;

    /**
     * The declared value above is a fallback; the settings are the source. Overriding it
     * for a single run still works, because callers do that after the component is built.
     */
    public function init(): void
    {
        parent::init();

        $this->maxAssetsPerRun = Plugin::getInstance()->getSettings()->maxAssetsPerRun;
    }

    /**
     * Generate every named transform for each referenced Dash asset that is missing them.
     *
     * Batched per asset rather than per transform: getLocalImageSource() only downloads when
     * the source is absent, and the cached copy lives until the process ends, so all fifteen
     * transforms for one asset cost a single download. Measured on a 5.2 MB asset: 3038 ms
     * for the first, then ~365 ms each. Generating per transform instead would cost fifteen
     * downloads.
     *
     * @param int[] $assetIds Dash-mapped asset ids to consider
     * @return array{generated: int, deferred: int, failed: int}
     */
    public function ensureTransforms(array $assetIds): array
    {
        $result = ['generated' => 0, 'deferred' => 0, 'failed' => 0];

        if ($assetIds === []) {
            return $result;
        }

        // An unreferenced asset is not on a page, so nothing would ask for its transforms.
        $referenced = array_keys(Plugin::getInstance()->getAssetUsage()->counts($assetIds));

        if ($referenced === []) {
            return $result;
        }

        $transforms = Craft::$app->getImageTransforms()->getAllTransforms();

        if ($transforms === []) {
            return $result;
        }

        $due = [];

        foreach (Asset::find()->id($referenced)->kind(Asset::KIND_IMAGE)->status(null)->all() as $asset) {
            if ($this->isMissingAnyTransform($asset, $transforms)) {
                $due[] = $asset;
            }
        }

        if ($due === []) {
            return $result;
        }

        $batch = array_slice($due, 0, $this->maxAssetsPerRun);
        $result['deferred'] = count($due) - count($batch);

        foreach ($batch as $asset) {
            try {
                foreach ($transforms as $transform) {
                    // true = generate now rather than queue a job and return a temporary URL.
                    $asset->getUrl($transform, true);
                }

                $this->log("  TRANSFORMS #{$asset->id}  {$asset->getPath()}  (" . count($transforms) . ' generated)');
                $result['generated']++;
            } catch (Throwable $e) {
                Craft::warning("Could not generate transforms for Dash asset #{$asset->id}: {$e->getMessage()}", __METHOD__);
                $this->log("  FAILED    #{$asset->id}  transforms: {$e->getMessage()}");
                $result['failed']++;
            }
        }

        if ($result['deferred'] > 0) {
            $this->log(sprintf(
                '  note: %d more asset(s) need transforms — deferred to the next run to stay inside the Dash download allowance',
                $result['deferred'],
            ));
        }

        return $result;
    }

    /**
     * @param ImageTransform[] $transforms
     */
    private function isMissingAnyTransform(Asset $asset, array $transforms): bool
    {
        foreach ($transforms as $transform) {
            $transformer = $transform->getImageTransformer();

            // getTransformIndex() belongs to Craft's own transformer, not to
            // ImageTransformerInterface. Anything else — a CDN transformer, say — tracks
            // existence itself and has no placeholder path to avoid, so it needs nothing here.
            if (!$transformer instanceof ImageTransformer) {
                continue;
            }

            if (!$transformer->getTransformIndex($asset, $transform)->fileExists) {
                return true;
            }
        }

        return false;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
