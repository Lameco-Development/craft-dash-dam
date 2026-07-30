<?php

/**
 * General config for the integration-test app. Admin changes stay allowed so the harness
 * can seed the filesystem, volume and relation field. Project config stays in the test
 * database alone — the harness boots with CRAFT_EPHEMERAL=1, which turns YAML writing off.
 */
return [
    'allowAdminChanges' => true,
    'devMode' => true,
];
