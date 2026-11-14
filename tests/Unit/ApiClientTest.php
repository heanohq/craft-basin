<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use heano\basin\api\ApiClient;
use heano\basin\api\ListPage;
use heano\basin\api\exceptions\ApiException;
use heano\basin\api\exceptions\ApiUnavailableException;
use heano\basin\api\exceptions\AuthException;
use heano\basin\api\exceptions\NotFoundException;
use heano\basin\api\exceptions\RateLimitException;
use heano\basin\api\exceptions\ValidationException;

/**
 * Builds an ApiClient backed by queued mock responses, recording requests.
 *
 * @param array<int, Response|Throwable> $responses
 * @param array<int, array{request: Request}> $history
 */
function basinClient(array $responses, array &$history = [], string $key = 'secret-key'): ApiClient
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    return new ApiClient(new Client(['handler' => $stack]), $key, 5.0);
}

function json(int $status, mixed $body, array $headers = []): Response
{
    return new Response($status, ['Content-Type' => 'application/json'] + $headers, json_encode($body));
}

it('sends the key in the Authorization header, not the query string', function() {
    $history = [];
    basinClient([json(200, [])], $history)->listProjects();

    $request = $history[0]['request'];
    expect($request->getHeaderLine('Authorization'))->toBe('Token secret-key')
        ->and($request->getHeaderLine('Accept'))->toBe('application/json')
        ->and($request->getUri()->getQuery())->not->toContain('api_token')
        ->and((string)$request->getUri())->toBe('https://usebasin.com/api/v1/projects?page=1');
});

function fixture(string $name): array
{
    return json_decode(file_get_contents(__DIR__ . "/../fixtures/$name.json"), true, flags: JSON_THROW_ON_ERROR);
}

it('parses Basin’s real project list format', function() {
    $result = basinClient([json(200, fixture('projects'))])->listProjects();
    expect($result)->toBeInstanceOf(ListPage::class)
        ->and(array_column($result->items, 'name'))->toBe(['My Starter Project', 'Alpha', 'Beta'])
        ->and($result->page)->toBe(1)
        ->and($result->perPage)->toBe(100)
        ->and($result->total)->toBe(3)
        ->and($result->hasMore())->toBeFalse();
});

it('accepts list responses as a bare array', function() {
    $result = basinClient([json(200, [['id' => 1, 'name' => 'Acme']])])->listProjects();
    expect($result->items)->toBe([['id' => 1, 'name' => 'Acme']])
        ->and($result->total)->toBeNull()
        ->and($result->hasMore())->toBeTrue();
});

it('accepts list responses wrapped in a key', function(string $key) {
    $result = basinClient([json(200, [$key => [['id' => 2, 'name' => 'Beta']], 'meta' => ['page' => 1]])])->listProjects();
    expect($result->items)->toBe([['id' => 2, 'name' => 'Beta']]);
})->with(['data', 'projects', 'items']);

it('reads a string page number from meta', function() {
    $result = basinClient([json(200, ['projects' => [['id' => 1]], 'meta' => ['count' => 150, 'page' => '2', 'per_page' => 100]])])->listProjects(2);
    expect($result->page)->toBe(2)->and($result->hasMore())->toBeFalse();
});

it('knows when more pages remain', function() {
    $result = basinClient([json(200, ['projects' => [['id' => 1]], 'meta' => ['count' => 150, 'page' => 1, 'per_page' => 100]])])->listProjects();
    expect($result->hasMore())->toBeTrue();
});

it('treats an empty page as the last page', function() {
    expect(basinClient([json(200, ['projects' => [], 'meta' => ['count' => 3, 'page' => 2, 'per_page' => 100]])])->listProjects(2)->hasMore())->toBeFalse();
});

it('parses Basin’s 404 error body', function() {
    basinClient([json(404, ['error' => 'Not Found'])])->getProject(1);
})->throws(NotFoundException::class, 'Not Found');

it('rejects unrecognised list formats', function() {
    basinClient([json(200, ['unexpected' => true])])->listProjects();
})->throws(ApiException::class, 'unexpected list format');

it('gets a single project', function() {
    $history = [];
    $project = basinClient([json(200, ['id' => 7, 'name' => 'Acme'])], $history)->getProject(7);
    expect($project['name'])->toBe('Acme')
        ->and($history[0]['request']->getUri()->getPath())->toBe('/api/v1/projects/7');
});

it('maps HTTP errors to typed exceptions', function(int $status, string $class) {
    expect(fn() => basinClient([json($status, [])])->listProjects())->toThrow($class);
})->with([
    [401, AuthException::class],
    [403, AuthException::class],
    [404, NotFoundException::class],
    [422, ValidationException::class],
    [429, RateLimitException::class],
    [500, ApiUnavailableException::class],
    [503, ApiUnavailableException::class],
    [418, ApiException::class],
]);

it('uses Basin’s error message when there is one', function() {
    expect(fn() => basinClient([json(422, ['errors' => ['name' => ['is too long']]])])->listProjects())
        ->toThrow(ValidationException::class, 'is too long');
});

it('reads Retry-After on 429', function() {
    try {
        basinClient([json(429, [], ['Retry-After' => '30'])])->listProjects();
    } catch (RateLimitException $e) {
        expect($e->retryAfter)->toBe(30);
        return;
    }
    $this->fail('Expected RateLimitException');
});

it('wraps connection failures', function() {
    $error = new ConnectException('cURL error 6', new Request('GET', 'https://usebasin.com/api/v1/projects'));
    basinClient([$error])->listProjects();
})->throws(ApiUnavailableException::class, 'Could not connect to Basin.');

it('rejects invalid JSON on success', function() {
    basinClient([new Response(200, [], '<html>')])->listProjects();
})->throws(ApiException::class, 'valid JSON');

it('refuses to call the API without a key', function() {
    $history = [];
    expect(fn() => basinClient([json(200, [])], $history, '')->listProjects())->toThrow(AuthException::class);
    expect($history)->toBeEmpty();
});

it('never includes the API key in exception messages', function(int $status) {
    try {
        basinClient([json($status, ['error' => 'bad'])], key: 'TOPSECRET123')->listProjects();
    } catch (ApiException $e) {
        expect($e->getMessage())->not->toContain('TOPSECRET123');
        return;
    }
    $this->fail('Expected an ApiException');
})->with([401, 404, 422, 500]);

it('lists a project’s forms using project_id', function() {
    $history = [];
    $fixture = fixture('forms');
    $projectId = $fixture['forms'][0]['project_id'];
    $result = basinClient([json(200, $fixture)], $history)->listForms($projectId);

    parse_str($history[0]['request']->getUri()->getQuery(), $query);
    expect($history[0]['request']->getUri()->getPath())->toBe('/api/v1/forms')
        ->and($query)->toBe(['project_id' => (string)$projectId, 'page' => '1'])
        ->and(array_column($result->items, 'name'))->toBe(['Alpha form 1', 'Alpha form 2']);
});

it('drops forms from other projects', function() {
    $rows = [['id' => 1, 'project_id' => 5], ['id' => 2, 'project_id' => 6]];
    $result = basinClient([json(200, ['forms' => $rows, 'meta' => ['count' => 2, 'page' => 1, 'per_page' => 100]])])->listForms(5);
    expect(array_column($result->items, 'id'))->toBe([1]);
});

it('strips turnstile_secret from forms', function() {
    $form = ['id' => 1, 'project_id' => 5, 'turnstile_site_key' => 'site', 'turnstile_secret' => 'TOPSECRET'];
    $client = basinClient([
        json(200, ['forms' => [$form], 'meta' => []]),
        json(200, $form),
    ]);
    expect($client->listForms(5)->items[0])->not->toHaveKey('turnstile_secret')
        ->toHaveKey('turnstile_site_key')
        ->and($client->getForm(1))->not->toHaveKey('turnstile_secret');
});

it('strips email settings from forms', function() {
    $form = ['id' => 1, 'project_id' => 5, 'name' => 'Contact', 'use_ajax' => false, 'notification_emails' => 'a@example.com', 'notification_cc_emails' => 'b@example.com', 'autoreply_email' => 'c@example.com', 'domain_email' => null];
    $client = basinClient([json(200, ['forms' => [$form], 'meta' => []])]);
    expect(array_keys($client->listForms(5)->items[0]))->toBe(['id', 'project_id', 'name', 'use_ajax']);
});

it('replaces form webhooks with a count', function() {
    $form = ['id' => 1, 'project_id' => 5, 'form_webhooks' => [['url' => 'https://hooks.example.com/SECRET'], ['url' => 'https://x.example.com']]];
    $item = basinClient([json(200, ['forms' => [$form], 'meta' => []])])->listForms(5)->items[0];
    expect($item)->not->toHaveKey('form_webhooks')->and($item['webhook_count'])->toBe(2);
});

it('asks Basin to resend a submission to its webhooks', function() {
    $history = [];
    basinClient([json(200, ['success' => true, 'message' => 'Webhooks queued'])], $history)->refireWebhooks(42);
    expect($history[0]['request']->getMethod())->toBe('POST')
        ->and($history[0]['request']->getUri()->getPath())->toEndWith('/submissions/42/refire_webhooks');
});

function query(array $history, int $i = 0): array
{
    parse_str($history[$i]['request']->getUri()->getQuery(), $q);
    return $q;
}

it('lists submissions with Basin’s real format and counts', function() {
    $history = [];
    $result = basinClient([json(200, fixture('submissions'))], $history)->listSubmissions(['formId' => 1004]);
    expect($result->total)->toBe(4)
        ->and($result->meta['inbox_count'])->toBe(3)
        ->and($result->meta['spam_count'])->toBe(1)
        ->and(query($history))->toBe(['form_id' => '1004', 'page' => '1']);
});

it('sends search, filter, order and date range', function() {
    $history = [];
    basinClient([json(200, ['submissions' => [], 'meta' => []])], $history)->listSubmissions([
        'formId' => 5, 'filter' => 'spam', 'query' => 'bob', 'order' => 'email_asc',
        'dateFrom' => '2026-11-01', 'dateTo' => '2026-11-13',
    ], 2);
    expect(query($history))->toBe([
        'form_id' => '5', 'filter_by' => 'spam', 'query' => 'bob', 'order_by' => 'email_asc',
        'date_range' => '2026-11-01 to 2026-11-13', 'page' => '2',
    ]);
});

it('fills in an open-ended date range', function() {
    $history = [];
    basinClient([json(200, ['submissions' => []])], $history)->listSubmissions(['dateFrom' => '2026-11-01']);
    expect(query($history)['date_range'])->toBe('2026-11-01 to 2999-12-31');
});

it('refuses filters Basin would silently ignore', function(array $criteria) {
    $history = [];
    expect(fn() => basinClient([json(200, [])], $history)->listSubmissions($criteria))->toThrow(InvalidArgumentException::class);
    expect($history)->toBeEmpty();
})->with([
    'unread filter' => [['filter' => 'unread']],
    'new filter' => [['filter' => 'new']],
    'unknown order' => [['order' => 'name']],
    'bad date' => [['dateFrom' => '13/11/2026']],
]);

it('updates flags with the submission wrapper', function() {
    $history = [];
    $updated = basinClient([json(200, ['id' => 9, 'read' => true, 'trash' => true])], $history)
        ->updateSubmission(9, ['read' => 1, 'trash' => true]);
    $request = $history[0]['request'];
    expect($request->getMethod())->toBe('PATCH')
        ->and($request->getUri()->getPath())->toBe('/api/v1/submissions/9')
        ->and(json_decode((string)$request->getBody(), true))->toBe(['submission' => ['read' => true, 'trash' => true]])
        ->and($updated['trash'])->toBeTrue();
});

it('only updates read, spam and trash', function(array $flags) {
    $history = [];
    expect(fn() => basinClient([json(200, [])], $history)->updateSubmission(9, $flags))->toThrow(InvalidArgumentException::class);
    expect($history)->toBeEmpty();
})->with([[[]], [['email' => 'x@example.com']], [['read' => true, 'payload_params' => []]]]);

it('deletes a submission', function() {
    $history = [];
    basinClient([new Response(200, [], '')], $history)->deleteSubmission(9);
    expect($history[0]['request']->getMethod())->toBe('DELETE')
        ->and($history[0]['request']->getUri()->getPath())->toBe('/api/v1/submissions/9');
});

it('streams attachments through the authenticated proxy', function() {
    $history = [];
    $response = basinClient([new Response(200, ['Content-Type' => 'image/png'], 'PNGDATA')], $history)->downloadAttachment(42);
    expect((string)$response->getBody())->toBe('PNGDATA')
        ->and($history[0]['request']->getUri()->getPath())->toBe('/api/v1/attachments/42')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toBe('Token secret-key');
});

it('maps attachment errors to exceptions', function() {
    basinClient([json(404, ['error' => 'Not Found'])])->downloadAttachment(42);
})->throws(NotFoundException::class);

it('never returns attachment public URLs', function() {
    $fixture = fixture('submissions');
    $client = basinClient([json(200, $fixture), json(200, $fixture['submissions'][3])]);
    $listed = array_merge(...array_column($client->listSubmissions([])->items, 'attachments'));
    $single = $client->getSubmission(1)['attachments'];
    expect($listed)->not->toBeEmpty()
        ->and(array_filter($listed, fn($a) => isset($a['public_url'])))->toBeEmpty()
        ->and($single[0])->not->toHaveKey('public_url')->toHaveKey('attachment_proxy_url');
});
