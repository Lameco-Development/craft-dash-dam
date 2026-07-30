<?php

namespace lameco\dash\migrations;

use craft\db\Migration;

/**
 * When an asset stops coming back from Dash, record when — so the control panel can report
 * it without asking Dash on every request.
 *
 * The column lives on the mapping row rather than in a table of its own because an orphan
 * *is* a mapped asset whose Dash counterpart vanished; there is nothing to record about an
 * asset that was never mapped.
 */
class m260730_102958_add_missing_since extends Migration
{
    private const MAP_TABLE = '{{%dash_asset_map}}';

    public function safeUp(): bool
    {
        if ($this->db->getTableSchema(self::MAP_TABLE)->getColumn('missingSince') === null) {
            $this->addColumn(self::MAP_TABLE, 'missingSince', $this->dateTime()->null());
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn(self::MAP_TABLE, 'missingSince');

        return true;
    }
}
