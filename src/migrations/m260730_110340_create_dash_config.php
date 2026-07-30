<?php

namespace lameco\dash\migrations;

use craft\db\Migration;

/**
 * Configuration the client owns, kept out of project config on purpose.
 *
 * Plugin settings are the wrong home for it: Craft writes those to project config, and every
 * deploy runs `project-config/apply`, so anything an editor changed in the control panel
 * would be reverted from the committed YAML on the next release. Retour and SEOmatic make
 * the same split — developer knobs in project config, editor-owned data in their own tables.
 *
 * Deliberately not the `dash_sync_state` table, which the sync writes: clearing sync state
 * to force a full re-reconcile must not also wipe the folder selection.
 */
class m260730_110340_create_dash_config extends Migration
{
    private const CONFIG_TABLE = '{{%dash_config}}';

    public function safeUp(): bool
    {
        if (!$this->db->tableExists(self::CONFIG_TABLE)) {
            $this->createTable(self::CONFIG_TABLE, [
                'k' => $this->string(64)->notNull(),
                'v' => $this->text()->null(),
                'PRIMARY KEY([[k]])',
            ]);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(self::CONFIG_TABLE);

        return true;
    }
}
