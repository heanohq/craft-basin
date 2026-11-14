<?php

namespace heano\basin\services;

use Craft;
use craft\base\Component;
use Heano\Basin\Client as ApiClient;
use Heano\Basin\Exception\ApiException;
use Heano\Basin\Exception\NotFoundException;
use heano\basin\helpers\StudioEmbed;
use heano\basin\models\BasinForm;
use heano\basin\models\Submission;
use heano\basin\Plugin;
use yii\caching\TagDependency;

/**
 * Reading and triaging submissions (spec §7).
 *
 * Submission data is only ever held in Craft's cache, for at most 60 seconds
 * (spec §16). Every method that takes a submission ID checks that it belongs
 * to a form in the linked project, because the Account API key can reach
 * every project in the Basin account.
 */
class Submissions extends Component
{
    public const LIST_TTL = 60;
    public const DETAIL_TTL = 60;

    /** Widget and nav badge data (spec §14, §15). */
    public const RECENT_TTL = 120;
    public const RECENT_TAG = 'basin:recent';

    /** Bulk actions with more than this many submissions run as a queue job (spec §7.3). */
    public const SYNC_LIMIT = 50;

    /** Triage actions and the flags they set. */
    public const ACTIONS = [
        'markRead' => ['read' => true],
        'markUnread' => ['read' => false],
        'markSpam' => ['spam' => true],
        'markNotSpam' => ['spam' => false],
        'trash' => ['trash' => true],
        'restore' => ['trash' => false],
    ];

    /** Tabs and the `filter_by` value each one sends (null = inbox). */
    public const TABS = ['inbox' => null, 'spam' => 'spam', 'trash' => 'trash'];

    /**
     * One page of a form's submissions.
     *
     * @param array{tab?: string, query?: string|null, order?: string|null, dateFrom?: string|null, dateTo?: string|null} $criteria
     * @return array{submissions: Submission[], total: ?int, page: int, perPage: int, counts: array{inbox: ?int, spam: ?int, trash: ?int}}
     * @throws ApiException
     */
    public function getPage(BasinForm $form, array $criteria, int $page = 1): array
    {
        $apiCriteria = self::apiCriteria($form, $criteria);

        $key = 'basin:submissions:' . md5(json_encode([$apiCriteria, $page, $this->keyHash()]));
        $cache = Craft::$app->getCache();
        $data = $cache->get($key);
        if ($data === false) {
            $result = $this->client()->listSubmissions($apiCriteria, $page);
            // Defence in depth: only keep rows for this form.
            $rows = array_values(array_filter($result->items, fn($row) => (int)($row['form_id'] ?? 0) === $form->id));
            $data = [
                'rows' => $rows,
                'total' => $result->total,
                'page' => $result->page,
                'perPage' => $result->perPage ?? 100,
                'counts' => [
                    'inbox' => isset($result->meta['inbox_count']) ? (int)$result->meta['inbox_count'] : null,
                    'spam' => isset($result->meta['spam_count']) ? (int)$result->meta['spam_count'] : null,
                    'trash' => isset($result->meta['trash_count']) ? (int)$result->meta['trash_count'] : null,
                ],
            ];
            $cache->set($key, $data, self::LIST_TTL, $this->dependency($form->id));
            // Prime the detail cache, so opening a row or checking IDs before
            // a bulk action doesn't need another request per submission.
            foreach ($rows as $row) {
                $cache->set('basin:submission:' . $row['id'] . ':' . $this->keyHash(), $row, self::DETAIL_TTL, $this->dependency($form->id, (int)$row['id']));
            }
            $this->detectStudio($form, $rows);
        }

        return [
            'submissions' => array_map(fn(array $row) => Submission::fromApi($row), $data['rows']),
        ] + $data;
    }

    /**
     * The newest inbox submissions of one form, or of every form in the
     * project, with counts. One API call (100 rows), cached for 2 minutes.
     *
     * Basin has no unread filter or count, so `unread` counts the unread rows
     * among the newest 100, and `unreadCapped` says whether there may be more.
     *
     * @return array{submissions: Submission[], inbox: ?int, unread: int, unreadCapped: bool}
     * @throws ApiException
     */
    public function getRecent(?BasinForm $form = null, ?float $timeout = null): array
    {
        $plugin = Plugin::getInstance();
        $forms = $plugin->getForms()->getForms();
        $projectId = $plugin->getSettings()->getProjectId();

        $key = 'basin:recent:' . ($form?->id ?? "p$projectId") . ':' . $this->keyHash();
        $cache = Craft::$app->getCache();
        $data = $cache->get($key);
        if ($data === false) {
            $criteria = $form ? ['formId' => $form->id] : ['projectId' => $projectId];
            $result = $plugin->getApi()->getClient(null, $timeout)->listSubmissions($criteria);
            // Only forms in the linked project.
            $formIds = array_map(fn(BasinForm $f) => $f->id, $forms);
            $rows = array_values(array_filter($result->items, fn($row) => in_array((int)($row['form_id'] ?? 0), $form ? [$form->id] : $formIds, true)));
            $data = [
                'rows' => $rows,
                'inbox' => $result->total,
                'unread' => count(array_filter($rows, fn($row) => empty($row['read']))),
                'unreadCapped' => $result->hasMore() && count($rows) > 0 && !array_filter($rows, fn($row) => !empty($row['read'])),
            ];
            $tags = [Api::CACHE_TAG, self::RECENT_TAG];
            $cache->set($key, $data, self::RECENT_TTL, new TagDependency(['tags' => $tags]));
        }

        // Fill in each submission's form, for links.
        $byId = [];
        foreach ($forms as $f) {
            $byId[$f->id] = $f;
        }
        $submissions = [];
        foreach ($data['rows'] as $row) {
            $submission = Submission::fromApi($row);
            $f = $byId[$submission->formId] ?? null;
            $submission->formUuid = $f?->uuid;
            $submission->formName = $f?->name;
            $submissions[] = $submission;
        }

        return ['submissions' => $submissions] + $data;
    }

    /**
     * Unread submissions among the project's newest 100 in the inbox, for the
     * nav badge (spec §15). Shown on every CP page, so it never waits more
     * than 3 seconds, and after a failure it doesn't retry for a minute.
     * Returns null when it can't be worked out.
     */
    public function getUnreadCount(): ?int
    {
        $plugin = Plugin::getInstance();
        $projectId = $plugin->getSettings()->getProjectId();
        if ($projectId === null || $plugin->getSettings()->getApiKey() === '') {
            return null;
        }

        $cache = Craft::$app->getCache();
        $key = "basin:unread:$projectId:" . $this->keyHash();
        $count = $cache->get($key);
        if ($count !== false) {
            return $count;
        }
        if ($cache->get("$key:down") !== false) {
            return null;
        }

        try {
            // project_id is an exact filter, so no forms list is needed here.
            $result = $plugin->getApi()->getClient(null, 3.0)->listSubmissions(['projectId' => $projectId]);
        } catch (ApiException $e) {
            Craft::warning('Couldn’t get the Basin unread count: ' . $e->getMessage(), 'basin');
            $cache->set("$key:down", true, 60);
            return null;
        }
        $count = count(array_filter($result->items, fn($row) => empty($row['read'])));
        $cache->set($key, $count, self::RECENT_TTL, new TagDependency(['tags' => [Api::CACHE_TAG, self::RECENT_TAG]]));
        return $count;
    }

    /**
     * Turns CP list criteria into {@see ApiClient::listSubmissions()} criteria.
     *
     * @param array{tab?: string, query?: string|null, order?: string|null, dateFrom?: string|null, dateTo?: string|null} $criteria
     */
    public static function apiCriteria(BasinForm $form, array $criteria): array
    {
        return [
            'formId' => $form->id,
            'filter' => self::TABS[$criteria['tab'] ?? 'inbox'] ?? null,
            'query' => ($criteria['query'] ?? '') !== '' ? $criteria['query'] : null,
            'order' => in_array($criteria['order'] ?? null, ApiClient::SUBMISSION_ORDERS, true) ? $criteria['order'] : null,
            'dateFrom' => $criteria['dateFrom'] ?? null,
            'dateTo' => $criteria['dateTo'] ?? null,
        ];
    }

    /**
     * A submission, if it exists and belongs to the given form.
     *
     * @throws ApiException
     */
    public function getSubmission(BasinForm $form, int $id): ?Submission
    {
        $key = 'basin:submission:' . $id . ':' . $this->keyHash();
        $cache = Craft::$app->getCache();
        $row = $cache->get($key);
        if ($row === false) {
            try {
                $row = $this->client()->getSubmission($id);
            } catch (NotFoundException) {
                return null;
            }
            $cache->set($key, $row, self::DETAIL_TTL, $this->dependency((int)($row['form_id'] ?? 0), $id));
        }
        if ((int)($row['form_id'] ?? 0) !== $form->id) {
            return null;
        }
        return Submission::fromApi($row);
    }

    /**
     * Applies a triage action to submissions of one form.
     *
     * @param int[] $ids
     * @return array{done: int, failed: int, errors: string[]}
     */
    public function applyAction(BasinForm $form, array $ids, string $action): array
    {
        $flags = self::ACTIONS[$action] ?? throw new \InvalidArgumentException("Unknown action: $action");
        return $this->each($form, $ids, fn(int $id) => $this->client()->updateSubmission($id, $flags));
    }

    /**
     * Permanently deletes submissions of one form. Callers check the setting
     * and permission; this checks the setting again.
     *
     * @param int[] $ids
     * @return array{done: int, failed: int, errors: string[]}
     */
    public function delete(BasinForm $form, array $ids): array
    {
        if (!Plugin::getInstance()->getSettings()->allowDeletion) {
            throw new \RuntimeException('Deleting submissions is turned off in the Basin plugin settings.');
        }
        return $this->each($form, $ids, fn(int $id) => $this->client()->deleteSubmission($id));
    }

    /**
     * Asks Basin to send a submission to its form's webhooks again.
     *
     * @throws ApiException
     */
    public function resendWebhooks(BasinForm $form, int $id): void
    {
        if ($this->getSubmission($form, $id) === null) {
            throw new \InvalidArgumentException("Submission $id isn’t in form $form->uuid.");
        }
        $this->client()->refireWebhooks($id);
        // Basin updates webhook_sent_at once they've gone out.
        TagDependency::invalidate(Craft::$app->getCache(), ["basin:submission:$id"]);
    }

    /**
     * Whether each ID belongs to the form. Checked against Basin before any
     * write, since IDs come from the browser.
     *
     * @param int[] $ids
     * @return int[] IDs that don't belong to the form
     * @throws ApiException
     */
    public function foreignIds(BasinForm $form, array $ids): array
    {
        $foreign = [];
        foreach ($ids as $id) {
            if ($this->getSubmission($form, $id) === null) {
                $foreign[] = $id;
            }
        }
        return $foreign;
    }

    public function invalidateForm(int $formId): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), ["basin:submissions:$formId", Forms::CACHE_TAG, self::RECENT_TAG]);
    }

    /**
     * @param int[] $ids
     * @return array{done: int, failed: int, errors: string[]}
     */
    private function each(BasinForm $form, array $ids, callable $fn): array
    {
        $done = 0;
        $errors = [];
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            try {
                if ($this->getSubmission($form, $id) === null) {
                    $errors[] = "Submission $id isn’t in this form.";
                    continue;
                }
                $fn($id);
                $done++;
            } catch (ApiException $e) {
                Craft::warning("Basin action failed for submission $id: " . $e->getMessage(), 'basin');
                $errors[] = $e->getMessage();
            }
        }
        $this->invalidateForm($form->id);
        return ['done' => $done, 'failed' => count($errors), 'errors' => array_values(array_unique($errors))];
    }

    /**
     * Studio submissions have the hosted form URL as their referrer, which
     * reveals the hosted ID the API doesn't otherwise expose (spec §10.3).
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function detectStudio(BasinForm $form, array $rows): void
    {
        foreach ($rows as $row) {
            $hostedId = StudioEmbed::hostedIdFromReferrer($row['referrer'] ?? null, $form->uuid);
            if ($hostedId !== null) {
                Plugin::getInstance()->getForms()->rememberDetectedHostedId($form->uuid, $hostedId);
                return;
            }
        }
    }

    private function dependency(int $formId, ?int $submissionId = null): TagDependency
    {
        $tags = [Api::CACHE_TAG, "basin:submissions:$formId"];
        if ($submissionId !== null) {
            $tags[] = "basin:submission:$submissionId";
        }
        return new TagDependency(['tags' => $tags]);
    }

    private function client(): ApiClient
    {
        return Plugin::getInstance()->getApi()->getClient();
    }

    private function keyHash(): string
    {
        return substr(hash('sha256', Plugin::getInstance()->getSettings()->getApiKey()), 0, 16);
    }
}
