<?php

namespace heano\basin\migrations;

use craft\db\Migration;
use heano\basin\services\FormIssues;

/**
 * Adds the missing-form tracking table to installs made before it existed.
 */
class m261113_000000_form_issues extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(FormIssues::TABLE)) {
            FormIssues::createTable($this);
        }
        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(FormIssues::TABLE);
        return true;
    }
}
