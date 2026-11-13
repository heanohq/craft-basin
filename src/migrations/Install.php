<?php

namespace heano\basin\migrations;

use craft\db\Migration;
use heano\basin\services\FormIssues;
use heano\basin\services\FormSettings;

/**
 * Creates the missing-form tracking (spec §13) and form settings tables.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        FormIssues::createTable($this);
        FormSettings::createTable($this);
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(FormSettings::TABLE);
        $this->dropTableIfExists(FormIssues::TABLE);
        return true;
    }
}
