<?php

namespace heano\basin\tests\craft;

use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Stands in for Basin in integration tests: its Account API, Form Studio
 * scripts and attachment downloads. config/guzzle.php makes every Guzzle
 * client Craft creates send its requests to the current FakeBasin
 * ({@see handler()}), so nothing in a test reaches the network.
 *
 * It starts with the data in tests/fixtures (project 1002 "Alpha", its two
 * forms, and four submissions to the first), applies changes the plugin
 * sends, and records every request. A request it doesn't recognise gets a 501
 * and is listed in {@see $unhandled}, which tests check is empty.
 *
 * Tests add forms in whatever configuration they need with {@see addForm()},
 * combining the presets below, e.g.
 * `$basin->addForm('Contact', FakeBasin::turnstile() + FakeBasin::honeypot())`.
 */
class FakeBasin implements ClientInterface
{
    public const KEY = 'fake-basin-key';
    public const PER_PAGE = 100;

    /** The instance requests go to. Set before each test. */
    public static ?self $current = null;

    /** @var array<string, true> Published Form Studio scripts, keyed by "uuid-hostedId" */
    public array $studioScripts = [];
    /** @var array<int, string> Attachment contents, by attachment ID */
    public array $attachmentContents = [];

    /** @var array<int, array<string, mixed>> */
    public array $projects = [];
    /** @var array<int, array<string, mixed>> */
    public array $forms = [];
    /** @var array<int, array<string, mixed>> */
    public array $submissions = [];

    /** @var list<array{method: string, path: string, query: array<string, string>, body: mixed}> */
    public array $requests = [];
    /** @var list<string> */
    public array $unhandled = [];

    /** @var array<string, array{0: int, 1: mixed}> Canned responses, keyed by "METHOD path" */
    private array $overrides = [];

    private int $nextId = 5000;

    public function __construct()
    {
        $dir = dirname(__DIR__, 2) . '/tests/fixtures';
        foreach (json_decode(file_get_contents("$dir/projects.json"), true)['projects'] as $p) {
            $this->projects[$p['id']] = $p;
        }
        foreach (json_decode(file_get_contents("$dir/forms.json"), true)['forms'] as $f) {
            $this->forms[$f['id']] = $f;
        }
        foreach (json_decode(file_get_contents("$dir/submissions.json"), true)['submissions'] as $s) {
            $this->submissions[$s['id']] = $s;
        }
    }

    /**
     * Adds a form to Basin, starting from Basin's defaults (no CAPTCHA, no
     * honeypot, no redirect, no webhooks) in the linked project 1002.
     * `$fields` uses Basin's field names, and can come from the presets.
     *
     * @return array<string, mixed> The form as Basin stores it
     */
    public function addForm(string $name, array $fields = []): array
    {
        $id = $this->nextId++;
        $defaults = [
            'project_id' => 1002,
            'project_name' => $this->projects[1002]['name'] ?? null,
            'force_recaptcha' => false,
            'force_hcaptcha' => false,
            'force_turnstile' => false,
            'turnstile_site_key' => '',
            'turnstile_secret' => '',
            'honeypot_field' => '',
            'use_ajax' => false,
            'redirect_url' => null,
            'form_webhooks' => [],
            'notification_emails' => 'person1@example.com',
            'notification_emails_pending' => '',
        ];
        $form = array_merge(
            reset($this->forms) ?: [],
            $defaults,
            ['name' => $name],
            $fields,
            ['id' => $id, 'uuid' => sprintf('f%011d', $id)],
        );
        return $this->forms[$id] = $form;
    }

    /**
     * Adds a submission to a form, e.g. `['spam' => true]` or
     * `['payload_params' => ['Message' => '...']]`.
     *
     * @return array<string, mixed>
     */
    public function addSubmission(int $formId, array $fields = []): array
    {
        $id = $this->nextId++;
        $form = $this->forms[$formId];
        $submission = array_merge(reset($this->submissions) ?: [], [
            'email' => 'person1@example.com',
            'payload_params' => ['Email' => 'person1@example.com', 'Message' => 'Automated plugin test'],
            'read' => false,
            'spam' => false,
            'trash' => false,
            'attachments' => [],
            'created_at' => gmdate('Y-m-d\\TH:i:s.000\\Z'),
        ], $fields, [
            'id' => $id,
            'form_id' => $formId,
            'form' => ['name' => $form['name'], 'uuid' => $form['uuid']],
        ]);
        return $this->submissions[$id] = $submission;
    }

    /**
     * Adds `$count` inbox submissions to a form, e.g. to test paging and
     * queued jobs.
     *
     * @return list<int> Their IDs
     */
    public function addSubmissions(int $formId, int $count, array $fields = []): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->addSubmission($formId, $fields)['id'];
        }
        return $ids;
    }

    /**
     * Gives a submission an attachment.
     *
     * @return int The attachment's ID
     */
    public function addAttachment(int $submissionId, string $name, string $contents, string $contentType = 'application/pdf'): int
    {
        $id = $this->nextId++;
        $this->submissions[$submissionId]['attachments'][] = [
            'id' => $id,
            'name' => $name,
            'file_size' => strlen($contents),
            'content_type' => $contentType,
        ];
        $this->attachmentContents[$id] = $contents;
        return $id;
    }

    /** Publishes a Form Studio version of a form, so its embed script loads. */
    public function publishStudioForm(string $uuid, string $hostedId): static
    {
        $this->studioScripts["$uuid-$hostedId"] = true;
        return $this;
    }

    /**
     * A Guzzle handler that sends each request to {@see $current}. Requests
     * with no current FakeBasin get a 599, so they can never reach the network.
     */
    public static function handler(): callable
    {
        return static function(RequestInterface $request, array $options): PromiseInterface {
            $response = self::$current?->sendRequest($request)
                ?? new Response(599, [], 'No FakeBasin is set up for this request.');
            return Create::promiseFor($response);
        };
    }

    // Form presets, using Basin's field names. Combine them with `+`.

    public static function recaptcha(): array
    {
        return ['force_recaptcha' => true];
    }

    public static function hcaptcha(): array
    {
        return ['force_hcaptcha' => true];
    }

    /** Cloudflare's public test site key, which always passes. */
    public static function turnstile(string $siteKey = '1x00000000000000000000AA'): array
    {
        return ['force_turnstile' => true, 'turnstile_site_key' => $siteKey, 'turnstile_secret' => 'fake-secret'];
    }

    /** Turnstile required but no site key saved in Basin: every submission fails. */
    public static function turnstileWithoutSiteKey(): array
    {
        return ['force_turnstile' => true, 'turnstile_site_key' => '', 'turnstile_secret' => ''];
    }

    public static function honeypot(string $field = '_gotcha'): array
    {
        return ['honeypot_field' => $field];
    }

    public static function ajax(): array
    {
        return ['use_ajax' => true];
    }

    public static function redirect(string $url = 'https://example.com/thanks'): array
    {
        return ['redirect_url' => $url];
    }

    public static function webhooks(int $count = 1): array
    {
        $hooks = [];
        for ($i = 1; $i <= $count; $i++) {
            $hooks[] = ['id' => $i, 'url' => "https://example.com/hook/$i", 'format' => 'json'];
        }
        return ['form_webhooks' => $hooks];
    }

    /** Another project's form, which the plugin must ignore. */
    public static function inProject(int $projectId): array
    {
        return ['project_id' => $projectId, 'project_name' => null];
    }

    /**
     * Makes `METHOD path` (e.g. `GET forms`, `GET forms/1001`) return this
     * status and body from now on, e.g. to simulate Basin failing.
     */
    public function respond(string $method, string $path, int $status, mixed $body = null): static
    {
        $this->overrides[strtoupper($method) . ' ' . $path] = [$status, $body];
        return $this;
    }

    /**
     * Requests matching a method and a path pattern (regex without delimiters).
     */
    public function requestsTo(string $method, string $pathPattern): array
    {
        return array_values(array_filter(
            $this->requests,
            fn($r) => $r['method'] === strtoupper($method) && preg_match("#^$pathPattern$#", $r['path']),
        ));
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $uri = $request->getUri();
        $host = $uri->getHost();
        $method = $request->getMethod();

        if ($host === 'hf.usebasin.com') {
            $this->requests[] = ['method' => $method, 'path' => $uri->getPath(), 'query' => [], 'body' => null, 'host' => $host];
            return preg_match('#^/hf/([a-z0-9]+-[a-z0-9]+)\.js$#i', $uri->getPath(), $m) && isset($this->studioScripts[strtolower($m[1])])
                ? new Response(200, ['Content-Type' => 'application/javascript'], '/* Form Studio */')
                : new Response(404);
        }
        if ($host !== 'usebasin.com' || !str_starts_with($uri->getPath(), '/api/v1/')) {
            $this->unhandled[] = "$method $uri";
            return $this->json(501, ['error' => "FakeBasin doesn't handle $method $uri"]);
        }

        $path = preg_replace('#^/api/v1/#', '', $uri->getPath());
        parse_str($uri->getQuery(), $query);
        $raw = (string)$request->getBody();
        $body = $raw === '' ? null : json_decode($raw, true);

        $this->requests[] = compact('method', 'path', 'query', 'body', 'host');

        if ($request->getHeaderLine('Authorization') !== 'Token ' . self::KEY) {
            return $this->json(401, ['error' => 'Invalid API key']);
        }

        if (isset($this->overrides["$method $path"])) {
            [$status, $data] = $this->overrides["$method $path"];
            return $this->json($status, $data);
        }

        $response = $this->route($method, $path, $query, $body);
        if ($response === null) {
            $this->unhandled[] = "$method $path";
            return $this->json(501, ['error' => "FakeBasin doesn't handle $method $path"]);
        }
        return $response;
    }

    private function route(string $method, string $path, array $query, mixed $body): ?ResponseInterface
    {
        $id = preg_match('#/(\d+)(?:/|$)#', $path, $m) ? (int)$m[1] : null;

        return match (true) {
            $method === 'GET' && $path === 'projects' => $this->list('projects', array_values($this->projects), $query),
            $method === 'GET' && preg_match('#^projects/\d+$#', $path) => $this->one($this->projects[$id] ?? null),

            $method === 'GET' && $path === 'forms' => $this->list('forms', array_values(array_filter(
                $this->forms,
                fn($f) => !isset($query['project_id']) || $f['project_id'] === (int)$query['project_id'],
            )), $query),
            $method === 'GET' && preg_match('#^forms/\d+$#', $path) => $this->one(
                isset($this->forms[$id]) ? $this->withCounts($this->forms[$id]) : null,
            ),
            $method === 'POST' && $path === 'forms' => $this->createForm($body['form'] ?? []),
            $method === 'PUT' && preg_match('#^forms/\d+$#', $path) => $this->updateForm($id, $body['form'] ?? []),
            $method === 'DELETE' && preg_match('#^forms/\d+$#', $path) => $this->delete($this->forms, $id),

            $method === 'GET' && $path === 'submissions' => $this->listSubmissions($query),
            $method === 'GET' && preg_match('#^submissions/\d+$#', $path) => $this->one($this->submissions[$id] ?? null),
            $method === 'PATCH' && preg_match('#^submissions/\d+$#', $path) => $this->updateSubmission($id, $body['submission'] ?? []),
            $method === 'DELETE' && preg_match('#^submissions/\d+$#', $path) => $this->delete($this->submissions, $id),
            $method === 'POST' && preg_match('#^submissions/\d+/refire_webhooks$#', $path) => isset($this->submissions[$id])
                ? $this->json(200, ['success' => true])
                : $this->notFound(),

            $method === 'GET' && preg_match('#^attachments/\d+$#', $path) => isset($this->attachmentContents[$id])
                ? new Response(200, ['Content-Type' => 'application/octet-stream'], $this->attachmentContents[$id])
                : $this->notFound(),

            default => null,
        };
    }

    /** One page of a list, as Basin sends it: 100 a page, `page` echoed as a string. */
    private function list(string $key, array $items, array $query, array $meta = []): ResponseInterface
    {
        $page = max(1, (int)($query['page'] ?? 1));
        return $this->json(200, [
            $key => array_slice(array_values($items), ($page - 1) * self::PER_PAGE, self::PER_PAGE),
            'meta' => $meta + ['count' => count($items), 'page' => (string)$page, 'per_page' => self::PER_PAGE],
        ]);
    }

    private function one(?array $item): ResponseInterface
    {
        return $item === null ? $this->notFound() : $this->json(200, $item);
    }

    private function withCounts(array $form): array
    {
        $own = array_filter($this->submissions, fn($s) => $s['form_id'] === $form['id']);
        $form['inbox_count'] = count(array_filter($own, fn($s) => !$s['spam'] && !$s['trash']));
        $form['spam_count'] = count(array_filter($own, fn($s) => $s['spam'] && !$s['trash']));
        $form['trash_count'] = count(array_filter($own, fn($s) => $s['trash']));
        return $form;
    }

    private function listSubmissions(array $query): ResponseInterface
    {
        $all = array_filter($this->submissions, function($s) use ($query) {
            if (isset($query['form_id']) && $s['form_id'] !== (int)$query['form_id']) {
                return false;
            }
            if (isset($query['project_id']) && ($this->forms[$s['form_id']]['project_id'] ?? null) !== (int)$query['project_id']) {
                return false;
            }
            return true;
        });

        $filter = $query['filter_by'] ?? null;
        $items = array_filter($all, fn($s) => match ($filter) {
            'spam' => $s['spam'] && !$s['trash'],
            'trash' => $s['trash'],
            default => !$s['spam'] && !$s['trash'],
        });
        if (isset($query['query'])) {
            $items = array_filter($items, fn($s) => stripos(json_encode($s), $query['query']) !== false);
        }
        usort($items, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $this->list('submissions', $items, $query, [
            'inbox_count' => count(array_filter($all, fn($s) => !$s['spam'] && !$s['trash'])),
            'spam_count' => count(array_filter($all, fn($s) => $s['spam'] && !$s['trash'])),
            'trash_count' => count(array_filter($all, fn($s) => $s['trash'])),
        ]);
    }

    private function createForm(array $fields): ResponseInterface
    {
        if (trim($fields['name'] ?? '') === '') {
            return $this->json(422, ['errors' => ['name' => ["can't be blank"]]]);
        }
        return $this->json(201, $this->addForm($fields['name'], $fields));
    }

    private function updateForm(?int $id, array $fields): ResponseInterface
    {
        if (!isset($this->forms[$id])) {
            return $this->notFound();
        }
        $this->forms[$id] = array_merge($this->forms[$id], $fields);
        return $this->json(200, $this->forms[$id]);
    }

    private function updateSubmission(?int $id, array $flags): ResponseInterface
    {
        if (!isset($this->submissions[$id])) {
            return $this->notFound();
        }
        $this->submissions[$id] = array_merge($this->submissions[$id], $flags);
        return $this->json(200, $this->submissions[$id]);
    }

    private function delete(array &$items, ?int $id): ResponseInterface
    {
        if (!isset($items[$id])) {
            return $this->notFound();
        }
        unset($items[$id]);
        return new Response(204);
    }

    private function notFound(): ResponseInterface
    {
        return $this->json(404, ['error' => 'Not found']);
    }

    private function json(int $status, mixed $data): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], $data === null ? '' : json_encode($data));
    }
}
