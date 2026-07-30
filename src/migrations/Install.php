<?php

namespace lameco\dash\migrations;

use craft\db\Migration;

/**
 * Tables backing the Dash DAM sync.
 *
 * `dash_asset_map` is the identity Craft's path-based asset model cannot provide.
 * Reconciling on path instead would read a folder move in Dash as one file missing plus
 * one file new, silently orphaning every relation pointing at the asset.
 *
 * Guarded rather than unconditional: these tables predate the plugin. They were created
 * at runtime by the spike, then by a content migration while the integration lived in the
 * `lameco` module, so an install can land on a database that already holds them.
 */
class Install extends Migration
{
    private const MAP_TABLE = '{{%dash_asset_map}}';
    private const STATE_TABLE = '{{%dash_sync_state}}';
    private const CONFIG_TABLE = '{{%dash_config}}';

    public function safeUp(): bool
    {
        if ($this->db->tableExists(self::MAP_TABLE)) {
            $columns = $this->db->getTableSchema(self::MAP_TABLE);

            if ($columns->getColumn('checksum') === null) {
                $this->addColumn(self::MAP_TABLE, 'checksum', $this->string(64)->null());
            }

            if ($columns->getColumn('missingSince') === null) {
                $this->addColumn(self::MAP_TABLE, 'missingSince', $this->dateTime()->null());
            }
        } else {
            $this->createTable(self::MAP_TABLE, [
                'assetId' => $this->integer()->notNull(),
                'dashId' => $this->string(64)->notNull(),
                'checksum' => $this->string(64)->null(),
                'missingSince' => $this->dateTime()->null(),
                'PRIMARY KEY([[assetId]])',
            ]);
            $this->createIndex(null, self::MAP_TABLE, ['dashId'], true);
        }

        if (!$this->db->tableExists(self::STATE_TABLE)) {
            $this->createTable(self::STATE_TABLE, [
                'k' => $this->string(64)->notNull(),
                'v' => $this->text()->null(),
                'PRIMARY KEY([[k]])',
            ]);
        }

        // Separate from STATE_TABLE: this one a human writes, that one the sync writes.
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
        $this->dropTableIfExists(self::MAP_TABLE);
        $this->dropTableIfExists(self::STATE_TABLE);
        $this->dropTableIfExists(self::CONFIG_TABLE);

        return true;
    }
}
