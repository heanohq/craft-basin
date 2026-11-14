<?php

namespace heano\basin\services;

use Craft;
use craft\base\Component;
use GuzzleHttp\Psr7\HttpFactory;
use Psr\Http\Client\ClientInterface;
use Heano\Basin\Client as ApiClient;
use Heano\Basin\Exception\ApiException;
use Heano\Basin\Exception\NotFoundException;
use heano\basin\models\Project;
use heano\basin\Plugin;
use yii\caching\TagDependency;

/**
 * Craft-facing wrapper around the basin-php client ({@see ApiClient}): builds
 * the client from plugin settings and caches reads (spec §4.3).
 */
class Api extends Component
{
    public const CACHE_TAG = 'basin';
    private const PROJECTS_TTL = 3600;

    /** Timeout for CP requests. Front-end lookups use a shorter one (spec §12.4). */
    public float $cpTimeout = 10.0;

    /**
     * Optional PSR-18 HTTP client override, mainly for tests. Timeouts are
     * then up to that client.
     */
    public ?ClientInterface $httpClient = null;

    private array $clients = [];

    /**
     * Returns an API client for the saved key, or for the given key (e.g. when
     * testing a key before it's saved).
     */
    public function getClient(?string $apiKey = null, ?float $timeout = null): ApiClient
    {
        $apiKey ??= Plugin::getInstance()->getSettings()->getApiKey();
        $timeout ??= $this->cpTimeout;
        $id = hash('sha256', $apiKey) . ':' . $timeout;

        if (!isset($this->clients[$id])) {
            // basin-php leaves timeouts to the HTTP client.
            $http = $this->httpClient ?? Craft::createGuzzleClient([
                'timeout' => $timeout,
                'connect_timeout' => min($timeout, 5.0),
            ]);
            $factory = new HttpFactory();
            $this->clients[$id] = new ApiClient($apiKey, $http, $factory, $factory);
        }
        return $this->clients[$id];
    }

    /**
     * @return Project[]
     * @throws ApiException
     */
    public function getProjects(bool $refresh = false): array
    {
        $key = $this->cacheKey('projects');
        $cache = Craft::$app->getCache();

        if (!$refresh && ($cached = $cache->get($key)) !== false) {
            return $cached;
        }

        $projects = $this->fetchAllProjects($this->getClient());
        $cache->set($key, $projects, self::PROJECTS_TTL, new TagDependency(['tags' => [self::CACHE_TAG]]));
        return $projects;
    }

    /**
     * Returns the linked project, or null if none is set or it no longer exists.
     *
     * @throws ApiException
     */
    public function getLinkedProject(): ?Project
    {
        $id = Plugin::getInstance()->getSettings()->getProjectId();
        if ($id === null) {
            return null;
        }
        foreach ($this->getProjects() as $project) {
            if ($project->id === $id) {
                return $project;
            }
        }
        try {
            return Project::fromApi($this->getClient()->getProject($id));
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * Checks a key by listing projects. Never throws.
     *
     * @return array{success: bool, message: string, projects: Project[]}
     */
    public function testConnection(?string $apiKey = null): array
    {
        try {
            $projects = $this->fetchAllProjects($this->getClient($apiKey));
        } catch (ApiException $e) {
            Craft::warning('Basin connection test failed: ' . $e->getMessage(), 'basin');
            return ['success' => false, 'message' => $e->getMessage(), 'projects' => []];
        }

        return [
            'success' => true,
            'message' => Craft::t('basin', 'Connected to Basin. {count, plural, =0{No projects found} =1{1 project found} other{# projects found}}.', [
                'count' => count($projects),
            ]),
            'projects' => $projects,
        ];
    }

    public function invalidateCaches(): void
    {
        TagDependency::invalidate(Craft::$app->getCache(), self::CACHE_TAG);
        $this->clients = [];
    }

    /**
     * @return Project[]
     * @throws ApiException
     */
    private function fetchAllProjects(ApiClient $client): array
    {
        // Basin returns 100 per page with a total in `meta.count`. The page cap
        // and repeat check guard against a change in that behaviour.
        $projects = [];
        $previousIds = null;
        for ($page = 1; $page <= 50; $page++) {
            $result = $client->listProjects($page);
            $ids = array_column($result->items, 'id');
            if ($ids === $previousIds) {
                break;
            }
            foreach ($result->items as $row) {
                $project = Project::fromApi($row);
                $projects[$project->id] = $project;
            }
            if (!$result->hasMore()) {
                break;
            }
            $previousIds = $ids;
        }

        uasort($projects, fn(Project $a, Project $b) => strcasecmp($a->name, $b->name));
        return array_values($projects);
    }

    private function cacheKey(string $name): string
    {
        $apiKey = Plugin::getInstance()->getSettings()->getApiKey();
        return 'basin:' . $name . ':' . substr(hash('sha256', $apiKey), 0, 16);
    }
}
