<?php

namespace lameco\dash;

use Craft;
use craft\base\Event;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\DefineAssetThumbUrlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Assets;
use craft\services\Fs;
use craft\services\Utilities;
use lameco\dash\console\controllers\DashController;
use lameco\dash\fs\DashFs;
use lameco\dash\models\Settings;
use lameco\dash\services\DashApi;
use lameco\dash\services\DashConfig;
use lameco\dash\services\DashSync;
use lameco\dash\services\DashTransforms;
use lameco\dash\utilities\DashUtility;

/**
 * Mounts the Dash (dash.app) DAM as a read-only Craft volume.
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read DashApi $dashApi
 * @property-read DashConfig $dashConfig
 * @property-read DashSync $dashSync
 * @property-read DashTransforms $dashTransforms
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.2.0';
    public bool $hasCpSettings = true;

    public static function config(): array
    {
        return [
            'components' => [
                'dashApi' => DashApi::class,
                'dashConfig' => DashConfig::class,
                'dashSync' => DashSync::class,
                'dashTransforms' => DashTransforms::class,
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        // Not deferred to onInit(): Craft collects filesystem types while resolving
        // volumes, which happens before the application finishes initialising.
        Event::on(Fs::class, Fs::EVENT_REGISTER_FILESYSTEM_TYPES, static function(RegisterComponentTypesEvent $event) {
            $event->types[] = DashFs::class;
        });

        Event::on(Utilities::class, Utilities::EVENT_REGISTER_UTILITIES, static function(RegisterComponentTypesEvent $event) {
            $event->types[] = DashUtility::class;
        });

        // Craft builds an asset thumbnail by generating a 200×200 crop, which needs the
        // source file — measured at 9.5 MB pulled from Dash for one thumbnail. Opening a
        // folder of 225 assets would transfer over 2 GB and spend four times a month's
        // download allowance on one page view, which is why the assets index timed out.
        //
        // Dash's own preview URL costs nothing here: the browser fetches it from CloudFront
        // directly. It is signed and expiring, so it must never reach rendered site HTML that
        // Blitz will cache — a control panel thumbnail is the one place that is safe.
        Event::on(Assets::class, Assets::EVENT_DEFINE_THUMB_URL, static function(DefineAssetThumbUrlEvent $event) {
            if (!DashVolumes::isDashAsset($event->asset)) {
                return;
            }

            $url = Plugin::getInstance()->getDashSync()->previewUrl((int)$event->asset->id);

            if ($url !== null) {
                $event->url = $url;
            }
        });

        ReadOnlyGuard::register();

        // The route Craft resolves on its own would be `dash-dam/dash/sync`. Cron runs
        // this every few minutes, so it gets a name worth typing.
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            Craft::$app->controllerMap['dash'] = DashController::class;
        }
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('dash-dam/_settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    public function getDashApi(): DashApi
    {
        return $this->get('dashApi');
    }

    public function getDashConfig(): DashConfig
    {
        return $this->get('dashConfig');
    }

    public function getDashSync(): DashSync
    {
        return $this->get('dashSync');
    }

    public function getDashTransforms(): DashTransforms
    {
        return $this->get('dashTransforms');
    }
}
