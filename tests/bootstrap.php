<?php

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Craft's bootstrap calls this hook right before it creates the application, which makes
 * it the one seam where the final app config can be both adjusted and captured — the
 * integration harness replays it to rebuild a fresh app per test. Only the integration
 * suite ever boots Craft; for unit tests this function is defined and never called.
 */
function craft_modify_app_config(array &$config, string $appType): void
{
    \lameco\dash\tests\integration\CraftHarness::captureAppConfig($config);
}
