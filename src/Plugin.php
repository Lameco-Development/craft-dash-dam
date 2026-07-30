<?php

namespace lameco\dash;

use Craft;
use craft\base\Event;
use craft\base\Plugin as BasePlugin;
use craft\events\RegisterComponentTypesEvent;
use craft\services\Fs;
use craft\services\Utilities;
use lameco\dash\console\controllers\DashController;
use lameco\dash\fs\DashFs;
use lameco\dash\services\DashApi;
use lameco\dash\services\DashConfig;
use lameco\dash\services\DashSync;
use lameco\dash\utilities\DashUtility;

/**
 * Mounts the Dash (dash.app) DAM as a read-only Craft volume.
 *
 * @method static Plugin getInstance()
 * @property-read DashApi $dashApi
 * @property-read DashConfig $dashConfig
 * @property-read DashSync $dashSync
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.2.0';

    public static function config(): array
    {
        return [
            'components' => [
                'dashApi' => DashApi::class,
                'dashConfig' => DashConfig::class,
                'dashSync' => DashSync::class,
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

        // The handle is `_craft-dash`, so the route Craft resolves on its own would be
        // `_craft-dash/dash/sync`. Cron runs this every few minutes, so it gets a name
        // worth typing.
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            Craft::$app->controllerMap['dash'] = DashController::class;
        }
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
}
