<?php

namespace lameco\dash\tests\integration;

use Craft;
use craft\fields\Assets as AssetsField;
use craft\helpers\Db;
use craft\helpers\Session;
use craft\migrations\Install as CraftInstall;
use craft\models\Site;
use craft\models\Volume;
use lameco\dash\fs\DashFs;
use lameco\dash\Plugin;
use lameco\dash\services\DashApi;
use RuntimeException;
use Throwable;
use yii\base\Event;
use yii\caching\ArrayCache;

/**
 * Boots a real Craft console app against a disposable test database, the way Craft's own
 * `craft\test\TestSetup` does it, minus the Codeception layer (see ADR 0001).
 *
 * Once per process: bootstrap Craft, drop every table, run Craft's install migration,
 * install this plugin, and seed one Dash filesystem + volume plus an Assets field for
 * relation tests. Per test: rebuild the app from the captured config — cheap, and it
 * resets every memoised service — while the test wraps itself in a rolled-back DB
 * transaction, which is the same cleanup strategy Codeception's Yii2 module uses.
 */
final class CraftHarness
{
    public const VOLUME_HANDLE = 'dashDam';
    public const FS_HANDLE = 'dashFs';
    public const RELATION_FIELD_HANDLE = 'relatedImage';

    /** @var array<string, mixed>|null */
    private static ?array $appConfig = null;
    private static bool $installed = false;

    /**
     * Called from `craft_modify_app_config()` while Craft's own bootstrap runs.
     *
     * @param array<string, mixed> $config
     */
    public static function captureAppConfig(array &$config): void
    {
        // The one app id craft\mutex\Mutex special-cases to a NullMutex — the same id
        // Craft's own TestSetup runs under. Any other id gets the DB mutex, whose own
        // extra connection outlives every torn-down app, still holding whatever project
        // config locked, until the suite deadlocks itself.
        $config['id'] = 'craft-test';

        // A per-app in-memory cache instead of Craft's default: every rebuilt app starts
        // with nothing cached, so no listing or folder cache survives into the next test.
        $config['components']['cache'] = ['class' => ArrayCache::class];

        self::$appConfig = $config;
    }

    /**
     * Boot Craft and (re)install the test schema. Idempotent; the schema is rebuilt from
     * scratch once per PHPUnit process so every run starts from a known state.
     */
    public static function ensureInstalled(): void
    {
        if (self::$installed) {
            return;
        }

        $testsDir = dirname(__DIR__);
        self::loadEnvFile($testsDir . '/.env');
        self::applyEnvDefaults($testsDir);
        self::guardDatabaseName();

        foreach (['/_craft/storage', '/_craft/templates'] as $dir) {
            if (!is_dir($testsDir . $dir)) {
                mkdir($testsDir . $dir, 0775, true);
            }
        }

        // No Yii error handler: PHPUnit must keep owning the process's error handling, or
        // every test that boots Craft is flagged risky for swapping the global handlers.
        defined('YII_ENABLE_ERROR_HANDLER') || define('YII_ENABLE_ERROR_HANDLER', false);

        require dirname(__DIR__, 2) . '/vendor/craftcms/cms/bootstrap/console.php';

        if (self::$appConfig === null) {
            throw new RuntimeException('Craft booted without calling craft_modify_app_config() — is tests/bootstrap.php the PHPUnit bootstrap?');
        }

        self::installSchema();
        self::$installed = true;
    }

    /**
     * Tear down the current app and build a fresh one from the captured config. Every
     * memoised service state goes with the old instance.
     */
    public static function freshApp(): void
    {
        self::teardownApp();
        Craft::createObject(self::$appConfig);
    }

    public static function teardownApp(): void
    {
        if (Craft::$app === null) {
            return;
        }

        try {
            Craft::$app->getDb()->close();
        } catch (Throwable) {
            // A test that broke the connection should not also break the teardown.
        }

        Event::offAll();
        Craft::setLogger(null);
        // The statics Craft's own CraftConnector resets between tests. Db above all: the
        // Db helper memoises a Connection, and letting it outlive the app splits writes
        // over two connections whose transactions then block each other.
        Db::reset();
        Session::reset();
        /** @phpstan-ignore assign.propertyType (between apps there is genuinely no app; Craft's own TestSetup does the same) */
        Craft::$app = null;

        // The app graph is cyclic, so anything it still holds open — connections, file
        // handles — only goes away once the cycle collector runs. Waiting for an arbitrary
        // later GC would leak resources into the next test's app.
        gc_collect_cycles();
    }

    /**
     * Replace the plugin's Dash API component, which is the single seam between the sync
     * and the outside world.
     */
    public static function injectDashApi(DashApi $api): Plugin
    {
        $plugin = Craft::$app->getPlugins()->getPlugin('dash-dam');

        if (!$plugin instanceof Plugin) {
            throw new RuntimeException('The dash-dam plugin is not installed in the test app.');
        }

        $plugin->set('dashApi', $api);

        return $plugin;
    }

    private static function installSchema(): void
    {
        $db = Craft::$app->getDb();

        // MySQL-only on purpose: the suite targets the MySQL service container CI runs.
        $db->createCommand('SET FOREIGN_KEY_CHECKS = 0')->execute();

        foreach ($db->getSchema()->getTableNames() as $table) {
            $db->createCommand()->dropTable($table)->execute();
        }

        $db->createCommand('SET FOREIGN_KEY_CHECKS = 1')->execute();

        $migration = new CraftInstall([
            'db' => $db,
            'username' => 'tester',
            'password' => 'dash-dam-tests-2026!!',
            'email' => 'dev@lameco.nl',
            'site' => new Site([
                'name' => 'Dash DAM test site',
                'handle' => 'default',
                'hasUrls' => true,
                'baseUrl' => 'https://dash-dam.test/',
                'language' => 'en-US',
                'primary' => true,
            ]),
        ]);
        $migration->up(true);

        // The app cached "not installed" before the migration ran.
        self::freshApp();

        if (!Craft::$app->getPlugins()->installPlugin('dash-dam')) {
            throw new RuntimeException('Could not install the dash-dam plugin into the test schema.');
        }

        self::seed();

        // Craft only persists project config changes when a request ends, and nothing here
        // ever ends a request — without this flush the plugin, filesystem, volume and
        // field would evaporate with the app instance that created them.
        Craft::$app->getProjectConfig()->saveModifiedConfigData();

        self::freshApp();
    }

    /**
     * One Dash filesystem + volume — the exact single-tenant shape DashVolumes::single()
     * demands — and one Assets field so tests can relate elements to assets.
     */
    private static function seed(): void
    {
        $fsService = Craft::$app->getFs();
        $fs = $fsService->createFilesystem([
            'type' => DashFs::class,
            'handle' => self::FS_HANDLE,
            'name' => 'Dash',
        ]);

        if (!$fsService->saveFilesystem($fs)) {
            throw new RuntimeException('Could not save the Dash filesystem: ' . print_r($fs->getErrors(), true));
        }

        $volume = new Volume([
            'name' => 'Dash DAM',
            'handle' => self::VOLUME_HANDLE,
            'fsHandle' => self::FS_HANDLE,
        ]);

        if (!Craft::$app->getVolumes()->saveVolume($volume)) {
            throw new RuntimeException('Could not save the Dash volume: ' . print_r($volume->getErrors(), true));
        }

        $field = new AssetsField([
            'name' => 'Related image',
            'handle' => self::RELATION_FIELD_HANDLE,
        ]);

        if (!Craft::$app->getFields()->saveField($field)) {
            throw new RuntimeException('Could not save the relation field: ' . print_r($field->getErrors(), true));
        }
    }

    /**
     * Minimal KEY=VALUE loader so the harness needs no dotenv dependency. Existing
     * environment variables win, which is how CI overrides the local file.
     */
    private static function loadEnvFile(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with(ltrim($line), '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);

            if ($name === '' || getenv($name) !== false) {
                continue;
            }

            $value = trim(trim($value), '"\'');
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    private static function applyEnvDefaults(string $testsDir): void
    {
        $defaults = [
            'CRAFT_BASE_PATH' => $testsDir . '/_craft',
            'CRAFT_DOTENV_PATH' => $testsDir . '/.env',
            'CRAFT_SECURITY_KEY' => 'dash-dam-tests-not-a-secret',
            // Ephemeral keeps Craft from writing project config YAML and a license key
            // file — the test database alone holds the seeded schema.
            'CRAFT_EPHEMERAL' => '1',
        ];

        foreach ($defaults as $name => $value) {
            if (getenv($name) === false) {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }

    /**
     * The harness drops every table in the configured database, so refuse anything that
     * does not announce itself as a test database. This is what stands between a typo in
     * tests/.env and an emptied development database.
     */
    private static function guardDatabaseName(): void
    {
        $database = getenv('CRAFT_DB_DATABASE');

        if ($database === false || $database === '') {
            throw new RuntimeException(
                'Integration tests need a database: copy tests/.env.example to tests/.env, '
                . 'create the database it names, or export the CRAFT_DB_* variables.',
            );
        }

        if (!str_contains($database, 'test')) {
            throw new RuntimeException(
                "Refusing to run against '{$database}': the harness drops every table in the "
                . "database, so its name must contain 'test'.",
            );
        }
    }
}
