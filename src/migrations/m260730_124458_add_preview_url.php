<?php

namespace lameco\dash\migrations;

use craft\db\Migration;

/**
 * Somewhere to keep each asset's Dash preview URL, so the control panel can show a thumbnail
 * without downloading the original.
 *
 * Craft builds asset thumbnails by generating a 200×200 crop, which needs the source file:
 * measured at 9.5 MB pulled from Dash for one thumbnail. Opening a folder of 225 assets would
 * transfer over 2 GB and spend four months of a 500-download allowance on a single page view.
 *
 * Stored rather than read live so a control panel page costs no Dash calls at all, and still
 * renders when Dash is unreachable. Refreshed on every reconcile — the signature on these URLs
 * lasts about 30 days, far longer than the interval between reconciles.
 */
class m260730_124458_add_preview_url extends Migration
{
    private const MAP_TABLE = '{{%dash_asset_map}}';

    public function safeUp(): bool
    {
        if ($this->db->getTableSchema(self::MAP_TABLE)->getColumn('previewUrl') === null) {
            $this->addColumn(self::MAP_TABLE, 'previewUrl', $this->text()->null());
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn(self::MAP_TABLE, 'previewUrl');

        return true;
    }
}
