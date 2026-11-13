<?php

namespace heano\basin\services;

use Craft;
use craft\base\Component;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use heano\basin\api\exceptions\ApiException;
use heano\basin\Plugin;

/**
 * Per-form settings kept by the plugin (spec §6.6): a Form Studio form's
 * hosted ID and the form's own reCAPTCHA version.
 *
 * Stored in the database, not project config, so site owners can change them
 * in production. Each environment has its own.
 *
 * Garbage collection removes a row once its form has been missing from
 * Basin's list for {@see EXPIRY_DAYS} days. Forms can drop out of the list
 * for a while (moved between projects, a different project linked), so rows
 * aren't deleted as soon as their form is missing.
 */
class FormSettings extends Component
{
    public const TABLE = '{{%basin_form_settings}}';

    public const EXPIRY_DAYS = 30;

    /** @var array<string, array{hostedFormId: ?string, recaptchaVersion: ?string}>|null */
    private ?array $rows = null;

    public static function createTable(Migration $migration): void
    {
        $migration->createTable(self::TABLE, [
            'id' => $migration->primaryKey(),
            'uuid' => $migration->string(40)->notNull(),
            'hostedFormId' => $migration->string(40),
            'recaptchaVersion' => $migration->string(2),
            'lastSeen' => $migration->dateTime()->notNull(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);
        $migration->createIndex(null, self::TABLE, ['uuid'], true);
    }

    /**
     * @return array{hostedFormId: ?string, recaptchaVersion: ?string}
     */
    public function get(string $uuid): array
    {
        return $this->all()[$uuid] ?? ['hostedFormId' => null, 'recaptchaVersion' => null];
    }

    public function getHostedFormId(string $uuid): ?string
    {
        return $this->get($uuid)['hostedFormId'];
    }

    public function getRecaptchaVersion(string $uuid): ?string
    {
        $version = $this->get($uuid)['recaptchaVersion'];
        return in_array($version, ['v2', 'v3'], true) ? $version : null;
    }

    /**
     * Sets some of a form's settings. A row left with no settings is removed.
     *
     * @param array{hostedFormId?: ?string, recaptchaVersion?: ?string} $values
     */
    public function save(string $uuid, array $values): void
    {
        $row = array_merge($this->get($uuid), array_intersect_key($values, ['hostedFormId' => 1, 'recaptchaVersion' => 1]));

        if ($row['hostedFormId'] === null && $row['recaptchaVersion'] === null) {
            Db::delete(self::TABLE, ['uuid' => $uuid]);
        } else {
            Db::upsert(self::TABLE, ['uuid' => $uuid] + $row + [
                'lastSeen' => Db::prepareDateForDb(new DateTime()),
            ]);
        }
        $this->rows = null;
    }

    /**
     * Removes settings for forms that haven't been in Basin for a while.
     *
     * Checks Basin's current list first, so a form that still exists is never
     * removed, however rarely the site uses it. Does nothing if Basin can't
     * be reached or no project is linked.
     */
    public function gc(): void
    {
        if (!$this->all()) {
            return;
        }
        $forms = Plugin::getInstance()->getForms();
        if (!$forms->isConfigured()) {
            return;
        }
        try {
            $current = array_keys($forms->getForms(true));
        } catch (ApiException) {
            return;
        }
        if ($forms->isStale) {
            return;
        }

        if ($current) {
            Db::update(self::TABLE, ['lastSeen' => Db::prepareDateForDb(new DateTime())], ['uuid' => $current], updateTimestamp: false);
        }
        Db::delete(self::TABLE, ['<', 'lastSeen', Db::prepareDateForDb(new DateTime('-' . self::EXPIRY_DAYS . ' days'))]);
        $this->rows = null;
    }

    /**
     * @return array<string, array{hostedFormId: ?string, recaptchaVersion: ?string}>
     */
    private function all(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }
        $this->rows = [];
        try {
            $rows = (new Query())->select(['uuid', 'hostedFormId', 'recaptchaVersion'])->from(self::TABLE)->all();
        } catch (\Throwable $e) {
            // E.g. before the migration has run. Forms still work without these.
            Craft::warning('Couldn’t read Basin form settings: ' . $e->getMessage(), 'basin');
            return $this->rows;
        }
        foreach ($rows as $row) {
            $this->rows[$row['uuid']] = [
                'hostedFormId' => $row['hostedFormId'] ?: null,
                'recaptchaVersion' => $row['recaptchaVersion'] ?: null,
            ];
        }
        return $this->rows;
    }

    /**
     * Whether the current user can change form settings.
     */
    public static function canManage(): bool
    {
        return Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_FORMS);
    }
}
