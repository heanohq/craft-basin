<?php

namespace heano\basin\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\Db;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use DateTime;
use heano\basin\helpers\StudioEmbed;
use heano\basin\services\FormSettings;

/**
 * Moves per-form settings (Form Studio embed IDs and reCAPTCHA versions) out
 * of project config and into their own table, so they can be changed in
 * production.
 */
class m261114_000000_form_settings extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->tableExists(FormSettings::TABLE)) {
            FormSettings::createTable($this);
        }

        $projectConfig = Craft::$app->getProjectConfig();
        $path = 'plugins.basin.settings';
        $stored = ProjectConfigHelper::unpackAssociativeArrays((array)($projectConfig->get($path) ?? []));

        // Copy into this environment's database.
        $rows = [];
        foreach ((array)($stored['studioForms'] ?? []) as $uuid => $hostedId) {
            if (StudioEmbed::isValidHostedId((string)$uuid) && is_string($hostedId) && StudioEmbed::isValidHostedId($hostedId)) {
                $rows[(string)$uuid]['hostedFormId'] = $hostedId;
            }
        }
        foreach ((array)($stored['recaptchaVersions'] ?? []) as $uuid => $version) {
            if (StudioEmbed::isValidHostedId((string)$uuid) && in_array($version, ['v2', 'v3'], true)) {
                $rows[(string)$uuid]['recaptchaVersion'] = $version;
            }
        }
        $now = Db::prepareDateForDb(new DateTime());
        foreach ($rows as $uuid => $row) {
            Db::upsert(FormSettings::TABLE, [
                'uuid' => $uuid,
                'hostedFormId' => $row['hostedFormId'] ?? null,
                'recaptchaVersion' => $row['recaptchaVersion'] ?? null,
                'lastSeen' => $now,
            ]);
        }

        // Remove them from project config once, where the change is made.
        $schemaVersion = $projectConfig->get('plugins.basin.schemaVersion', true);
        if (version_compare((string)$schemaVersion, '1.0.2', '<')) {
            $projectConfig->remove("$path.studioForms");
            $projectConfig->remove("$path.recaptchaVersions");
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(FormSettings::TABLE);
        return true;
    }
}
