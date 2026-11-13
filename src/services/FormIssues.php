<?php

namespace heano\basin\services;

use Craft;
use craft\base\Component;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\Db;
use heano\basin\models\BasinForm;
use heano\basin\Plugin;
use Throwable;
use yii\db\Expression;

/**
 * Records references to forms the site can't use (spec §13): forms that
 * aren't in the linked project (`missing`), and Form Studio forms rendered
 * without their embed code (`noEmbed`). Holds no personal data.
 */
class FormIssues extends Component
{
    public const TABLE = '{{%basin_form_issues}}';

    public const REASON_MISSING = 'missing';
    public const REASON_NO_EMBED = 'noEmbed';

    public const SOURCE_TWIG = 'twig';
    public const SOURCE_FIELD = 'field';
    public const SOURCE_GRAPHQL = 'graphql';
    public const SOURCE_HTML = 'html';

    /** At most one write per form and reason in this many seconds. */
    public const THROTTLE = 600;

    public static function createTable(Migration $migration): void
    {
        $migration->createTable(self::TABLE, [
            'id' => $migration->primaryKey(),
            'uuid' => $migration->string(40)->notNull(),
            'reason' => $migration->string(20)->notNull(),
            'source' => $migration->string(20)->notNull(),
            'lastUrl' => $migration->string(500),
            'hitCount' => $migration->integer()->unsigned()->notNull()->defaultValue(1),
            'firstSeen' => $migration->dateTime()->notNull(),
            'lastSeen' => $migration->dateTime()->notNull(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);
        $migration->createIndex(null, self::TABLE, ['uuid', 'reason'], true);
    }

    /**
     * Records a reference. Never throws: tracking must not break a page.
     */
    public function record(string $uuid, string $reason, string $source, ?string $url = null): void
    {
        if (!Plugin::getInstance()->getSettings()->trackMissingForms) {
            return;
        }
        if (!preg_match('/^[A-Za-z0-9]{1,40}$/', $uuid)) {
            return;
        }
        // `add` only succeeds when the key isn't set, so this is the throttle.
        if (!Craft::$app->getCache()->add("basin:issue:$reason:$uuid", 1, self::THROTTLE)) {
            return;
        }

        $url ??= self::currentPath();
        $now = Db::prepareDateForDb(new \DateTime());

        try {
            Db::upsert(self::TABLE, [
                'uuid' => $uuid,
                'reason' => $reason,
                'source' => $source,
                'lastUrl' => $url !== null ? mb_substr($url, 0, 500) : null,
                'hitCount' => 1,
                'firstSeen' => $now,
                'lastSeen' => $now,
            ], [
                'source' => $source,
                'lastUrl' => $url !== null ? mb_substr($url, 0, 500) : null,
                'hitCount' => new Expression('[[hitCount]] + 1'),
                'lastSeen' => $now,
            ]);
        } catch (Throwable $e) {
            Craft::warning("Couldn’t record a Basin form issue for $uuid: " . $e->getMessage(), 'basin');
        }

        if ($reason === self::REASON_MISSING) {
            Craft::warning("A Basin form that isn’t in the linked project was requested: $uuid ($source, " . ($url ?? 'unknown URL') . ')', 'basin');
        }
    }

    /**
     * Current issues, with ones that no longer apply cleared: forms that are
     * back in the project, and Studio forms whose embed code is now saved.
     * Forms with a `noEmbed` row are flagged as needing their embed code.
     *
     * @param array<string, BasinForm> $forms A fresh forms list.
     * @return array<int, array{id: int, uuid: string, reason: string, source: string, lastUrl: ?string, hitCount: int, lastSeen: string, form: ?BasinForm}>
     */
    public function getActive(array $forms, bool $fresh = true): array
    {
        try {
            $rows = (new Query())->from(self::TABLE)->orderBy(['lastSeen' => SORT_DESC])->all();
        } catch (Throwable) {
            return [];
        }

        $active = [];
        $resolved = [];
        foreach ($rows as $row) {
            $form = $forms[$row['uuid']] ?? null;
            $stillApplies = match ($row['reason']) {
                // A stale list can't prove a form is back, so keep the row.
                self::REASON_MISSING => $form === null || !$fresh,
                self::REASON_NO_EMBED => $form !== null && !$form->getIsStudioForm(),
                default => false,
            };
            if (!$stillApplies) {
                $resolved[] = (int)$row['id'];
                continue;
            }
            if ($form && $row['reason'] === self::REASON_NO_EMBED) {
                $form->noEmbedReported = true;
            }
            $row['id'] = (int)$row['id'];
            $row['hitCount'] = (int)$row['hitCount'];
            $row['form'] = $form;
            $active[] = $row;
        }

        if ($resolved) {
            $this->deleteIds($resolved);
        }
        return $active;
    }

    public function dismiss(int $id): void
    {
        $this->deleteIds([$id]);
    }

    /** Clears the `noEmbed` row for a form, e.g. once its embed code is saved. */
    public function clear(string $uuid, string $reason): void
    {
        try {
            Db::delete(self::TABLE, ['uuid' => $uuid, 'reason' => $reason]);
            Craft::$app->getCache()->delete("basin:issue:$reason:$uuid");
        } catch (Throwable) {
        }
    }

    /**
     * The request's path, without the query string, which may hold personal data.
     */
    public static function currentPath(): ?string
    {
        $request = Craft::$app->getRequest();
        if ($request->getIsConsoleRequest()) {
            return null;
        }
        return '/' . ltrim($request->getPathInfo(), '/');
    }

    private function deleteIds(array $ids): void
    {
        try {
            Db::delete(self::TABLE, ['id' => $ids]);
        } catch (Throwable) {
        }
    }
}
