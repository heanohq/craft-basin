<?php

namespace heano\basin\services;

use Craft;
use craft\base\Component;
use heano\basin\api\exceptions\ApiException;
use heano\basin\api\exceptions\ApiUnavailableException;
use heano\basin\helpers\StudioEmbed;
use heano\basin\models\BasinForm;
use heano\basin\Plugin;
use Throwable;
use yii\caching\TagDependency;

/**
 * Forms in the linked project (spec §6), cached per §4.3.
 *
 * The raw API rows are cached rather than models, and Studio embed IDs are
 * applied when models are built, so saving an embed code needs no cache clear.
 */
class Forms extends Component
{
    public const CACHE_TAG = 'basin:forms';
    private const STUDIO_CHECK_TTL = 86400;

    /** Front-end requests never wait longer than this for Basin (spec §12.4). */
    public float $siteTimeout = 3.0;

    /** After a failed fetch, use the last-known-good copy for this long before retrying. */
    public int $outageBackoff = 60;

    /** Whether the last {@see getForms()} result came from the last-known-good copy. */
    public bool $isStale = false;

    /** Why the forms list is stale, if it is. */
    public ?string $staleReason = null;

    /** @var array<string, BasinForm>|null */
    private ?array $forms = null;

    /**
     * All forms in the linked project, sorted by name and keyed by UUID.
     * Empty when no project is linked.
     *
     * Falls back to the last-known-good copy when Basin can't be reached, and
     * throws only when there's no copy at all.
     *
     * @return array<string, BasinForm>
     * @throws ApiException
     */
    public function getForms(bool $refresh = false): array
    {
        if ($this->forms !== null && !$refresh) {
            return $this->forms;
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $projectId = $settings->getProjectId();
        if ($projectId === null || $settings->getApiKey() === '') {
            return $this->forms = [];
        }

        $cache = Craft::$app->getCache();
        $key = $this->cacheKey($projectId);
        $this->isStale = false;
        $this->staleReason = null;

        $rows = $refresh ? false : $cache->get($key);
        if ($rows === false) {
            try {
                // While Basin is down, don't make every page wait for a timeout.
                $down = $refresh ? false : $cache->get($this->downKey($projectId));
                if ($down !== false) {
                    throw new ApiUnavailableException($down);
                }
                $rows = $this->fetchRows($projectId);
            } catch (ApiException $e) {
                if ($down === false) {
                    // Tagged, so Clear Caches or saving settings retries straight away.
                    $cache->set($this->downKey($projectId), $e->getMessage(), $this->outageBackoff, new TagDependency(['tags' => [Api::CACHE_TAG]]));
                }
                $copy = $cache->get($this->copyKey($projectId));
                if ($copy === false) {
                    throw $e;
                }
                Craft::warning('Using the last known copy of the Basin forms list: ' . $e->getMessage(), 'basin');
                $this->isStale = true;
                $this->staleReason = $e->getMessage();
                $rows = $copy['rows'];
            }
            if (!$this->isStale) {
                // GraphQL results include form data and Craft caches them, so
                // clear them when the forms changed in Basin.
                $previous = $cache->get($this->copyKey($projectId));
                if (($previous['rows'] ?? null) !== $rows) {
                    Craft::$app->getGql()->invalidateCaches();
                }
                $cache->set($key, $rows, $settings->cacheDuration, new TagDependency(['tags' => [Api::CACHE_TAG, self::CACHE_TAG]]));
                // Kept with no expiry and no tag, so the front end keeps working
                // through a Basin outage (spec §12.4). Holds no submission data.
                $cache->set($this->copyKey($projectId), ['rows' => $rows, 'time' => time()]);
            }
        }

        $formSettings = $plugin->getFormSettings();
        $forms = [];
        foreach ($rows as $row) {
            if (!isset($row['id'], $row['uuid'])) {
                continue;
            }
            $form = BasinForm::fromApi($row, $formSettings->getHostedFormId((string)$row['uuid']));
            $form->recaptchaVersion = $formSettings->getRecaptchaVersion($form->uuid);
            $form->detectedHostedId = $this->getDetectedHostedId($form->uuid);
            $forms[$form->uuid] = $form;
        }
        uasort($forms, fn(BasinForm $a, BasinForm $b) => strnatcasecmp($a->name, $b->name));

        return $this->forms = $forms;
    }

    /**
     * Whether an API key and project are set, so there's a forms list to check.
     */
    public function isConfigured(): bool
    {
        $settings = Plugin::getInstance()->getSettings();
        return $settings->getApiKey() !== '' && $settings->getProjectId() !== null;
    }

    /**
     * Looks up a form for the front end (Twig, fields, GraphQL). Never throws:
     * if Basin can't be reached and there's no saved copy, it returns null.
     *
     * A form that isn't in a fresh list is recorded as missing (spec §13).
     * Nothing is recorded when the list is a stale copy or unavailable,
     * because then the form isn't known to be missing.
     */
    public function lookup(?string $uuid, string $source, ?string $url = null): ?BasinForm
    {
        $uuid = trim((string)$uuid);
        if ($uuid === '' || !$this->isConfigured()) {
            return null;
        }
        try {
            $form = $this->getFormByUuid($uuid);
        } catch (ApiException $e) {
            Craft::warning("Couldn’t look up Basin form $uuid: " . $e->getMessage(), 'basin');
            return null;
        }
        if ($form === null && !$this->isStale) {
            Plugin::getInstance()->getFormIssues()->record($uuid, FormIssues::REASON_MISSING, $source, $url);
        }
        return $form;
    }

    /**
     * Every form, for the front end (Twig, GraphQL). Never throws.
     *
     * @return BasinForm[]
     */
    public function listForFrontEnd(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }
        try {
            return array_values($this->getForms());
        } catch (ApiException $e) {
            Craft::warning('Couldn’t list Basin forms: ' . $e->getMessage(), 'basin');
            return [];
        }
    }

    /**
     * @throws ApiException
     */
    public function getFormByUuid(string $uuid): ?BasinForm
    {
        return $this->getForms()[$uuid] ?? null;
    }

    /**
     * When the last-known-good copy was saved, if it's being used.
     */
    public function getStaleSince(): ?int
    {
        $projectId = Plugin::getInstance()->getSettings()->getProjectId();
        if (!$this->isStale || $projectId === null) {
            return null;
        }
        $copy = Craft::$app->getCache()->get($this->copyKey($projectId));
        return $copy['time'] ?? null;
    }

    /**
     * Saves (or with null, removes) a form's Studio hosted ID.
     */
    public function saveHostedFormId(string $uuid, ?string $hostedId): void
    {
        $this->saveFormSetting($uuid, ['hostedFormId' => $hostedId]);
        if ($hostedId !== null) {
            Plugin::getInstance()->getFormIssues()->clear($uuid, FormIssues::REASON_NO_EMBED);
        }
    }

    /**
     * Saves (or with null, removes) a form's own reCAPTCHA version.
     */
    public function saveRecaptchaVersion(string $uuid, ?string $version): void
    {
        $this->saveFormSetting($uuid, ['recaptchaVersion' => $version]);
    }

    private function saveFormSetting(string $uuid, array $values): void
    {
        Plugin::getInstance()->getFormSettings()->save($uuid, $values);
        $this->forms = null;
        Craft::$app->getGql()->invalidateCaches();
    }

    /**
     * Whether a Studio embed script still loads. Cached for a day.
     *
     * @return bool|null null if it couldn't be checked (e.g. network error)
     */
    public function checkStudioScript(string $uuid, string $hostedId, bool $refresh = false): ?bool
    {
        $url = StudioEmbed::scriptUrl($uuid, $hostedId);
        $key = 'basin:studio-script:' . md5($url);
        $cache = Craft::$app->getCache();

        if (!$refresh && ($cached = $cache->get($key)) !== false) {
            return $cached['ok'];
        }

        try {
            $response = Craft::createGuzzleClient()->request('GET', $url, [
                'timeout' => 5,
                'connect_timeout' => 3,
                'http_errors' => false,
            ]);
            $ok = $response->getStatusCode() === 200;
        } catch (Throwable $e) {
            Craft::warning("Couldn’t check the Basin Form Studio script for $uuid: " . $e->getMessage(), 'basin');
            return null;
        }

        $cache->set($key, ['ok' => $ok], self::STUDIO_CHECK_TTL, new TagDependency(['tags' => [Api::CACHE_TAG]]));
        return $ok;
    }

    /**
     * The cached result of {@see checkStudioScript()}, without making a request.
     */
    public function peekStudioScript(string $uuid, string $hostedId): ?bool
    {
        $cached = Craft::$app->getCache()->get('basin:studio-script:' . md5(StudioEmbed::scriptUrl($uuid, $hostedId)));
        return $cached === false ? null : $cached['ok'];
    }

    /**
     * Records a Studio hosted ID spotted in a submission's referrer, so the CP
     * can offer it (spec §10.3). Kept until the embed code is saved.
     */
    public function rememberDetectedHostedId(string $uuid, string $hostedId): void
    {
        if (Plugin::getInstance()->getFormSettings()->getHostedFormId($uuid) === $hostedId) {
            return;
        }
        Craft::$app->getCache()->set('basin:studio-detected:' . $uuid, $hostedId);
    }

    /**
     * A detected hosted ID that differs from the saved one, if any.
     */
    public function getDetectedHostedId(string $uuid): ?string
    {
        $detected = Craft::$app->getCache()->get('basin:studio-detected:' . $uuid);
        if (!is_string($detected) || !StudioEmbed::isValidHostedId($detected)) {
            return null;
        }
        return Plugin::getInstance()->getFormSettings()->getHostedFormId($uuid) === $detected ? null : $detected;
    }

    public function invalidateCaches(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
        $this->forms = null;
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws ApiException
     */
    private function fetchRows(int $projectId): array
    {
        $request = Craft::$app->getRequest();
        $timeout = !$request->getIsConsoleRequest() && $request->getIsSiteRequest() ? $this->siteTimeout : null;
        $client = Plugin::getInstance()->getApi()->getClient(null, $timeout);
        $rows = [];
        $previousIds = null;
        for ($page = 1; $page <= 50; $page++) {
            $result = $client->listForms($projectId, $page);
            $ids = array_column($result->items, 'id');
            if ($ids === $previousIds) {
                break;
            }
            foreach ($result->items as $row) {
                $rows[$row['id']] = $row;
            }
            if (!$result->hasMore()) {
                break;
            }
            $previousIds = $ids;
        }
        return array_values($rows);
    }

    private function cacheKey(int $projectId): string
    {
        return 'basin:forms:' . $projectId . ':' . $this->keyHash();
    }

    private function downKey(int $projectId): string
    {
        return 'basin:forms-down:' . $projectId . ':' . $this->keyHash();
    }

    private function copyKey(int $projectId): string
    {
        return 'basin:forms-copy:' . $projectId . ':' . $this->keyHash();
    }

    private function keyHash(): string
    {
        return substr(hash('sha256', Plugin::getInstance()->getSettings()->getApiKey()), 0, 16);
    }
}
