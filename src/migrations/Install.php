<?php

namespace heano\basin\migrations;

use craft\db\Migration;
use heano\basin\services\FormIssues;

/**
 * Creates the missing-form tracking table (spec §13).
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        FormIssues::createTable($this);
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(FormIssues::TABLE);
        return true;
    }
}
