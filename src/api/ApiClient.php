<?php

namespace heano\basin\api;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TransferException;
use heano\basin\api\exceptions\ApiException;
use heano\basin\api\exceptions\ApiUnavailableException;
use heano\basin\api\exceptions\AuthException;
use heano\basin\api\exceptions\NotFoundException;
use heano\basin\api\exceptions\RateLimitException;
use heano\basin\api\exceptions\ValidationException;
use Psr\Http\Message\ResponseInterface;

/**
 * Thin, framework-free client for the Basin REST API (v1).
 *
 * Only the endpoints the plugin needs are exposed (see spec §4.2). There is
 * deliberately no generic "request anything" method, so an Account API key
 * can't be used for writes the plugin doesn't intend to make.
 *
 * The API key is sent as `Authorization: Token {key}` and is never included in
 * exception messages.
 */
final class ApiClient
{
    public const BASE_URI = 'https://usebasin.com/api/v1/';

    /**
     * Keys Basin wraps list responses in (`{"projects": [...], "meta": {...}}`).
     * A few generic keys and bare arrays are also accepted, in case the format
     * changes; see spec §18.
     */
    private const LIST_WRAPPER_KEYS = ['projects', 'forms', 'form_views', 'submissions', 'data', 'items', 'results'];

    /** `filter_by` values Basin understands. Null (omitted) is the inbox. */
    public const SUBMISSION_FILTERS = ['spam', 'trash'];

    /** `order_by` values Basin understands. Null (omitted) is newest first. */
    public const SUBMISSION_ORDERS = ['date_desc', 'date_asc', 'email_asc', 'email_desc'];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey,
        private readonly float $timeout = 10.0,
    ) {
    }

    /**
     * @throws ApiException
     */
    public function listProjects(int $page = 1): ListPage
    {
        return $this->normaliseList($this->get('projects', ['page' => $page]), $page);
    }

    /**
     * @return array<string, mixed>
     * @throws ApiException
     */
    public function getProject(int|string $id): array
    {
        return $this->normaliseObject($this->get('projects/' . rawurlencode((string)$id)));
    }

    /**
     * Forms in one project. Filtered with `project_id`, which is exact (`query`
     * is a loose text search). Rows from other projects are dropped as well,
     * in case the filter is ever ignored.
     *
     * @throws ApiException
     */
    public function listForms(int $projectId, int $page = 1): ListPage
    {
        $result = $this->normaliseList($this->get('forms', ['project_id' => $projectId, 'page' => $page]), $page);
        $items = [];
        foreach ($result->items as $form) {
            if ((int)($form['project_id'] ?? 0) === $projectId) {
                $items[] = $this->sanitiseForm($form);
            }
        }
        return new ListPage($items, $result->page, $result->perPage, $result->total, $result->meta);
    }

    /**
     * @return array<string, mixed>
     * @throws ApiException
     */
    public function getForm(int $id): array
    {
        return $this->sanitiseForm($this->normaliseObject($this->get('forms/' . $id)));
    }

    /**
     * Submissions, newest first unless `order_by` says otherwise.
     *
     * Only known `filter_by` and `order_by` values are sent: Basin ignores an
     * unknown `filter_by` and returns everything, spam included (spec §7.1).
     *
     * @param array{formId?: int|null, projectId?: int|null, filter?: string|null, query?: string|null, order?: string|null, dateFrom?: string|null, dateTo?: string|null} $criteria
     * @throws ApiException
     */
    public function listSubmissions(array $criteria, int $page = 1): ListPage
    {
        $filter = $criteria['filter'] ?? null;
        if ($filter !== null && !in_array($filter, self::SUBMISSION_FILTERS, true)) {
            throw new \InvalidArgumentException("Unsupported submission filter: $filter");
        }
        $order = $criteria['order'] ?? null;
        if ($order !== null && !in_array($order, self::SUBMISSION_ORDERS, true)) {
            throw new \InvalidArgumentException("Unsupported submission order: $order");
        }

        $dateRange = null;
        $from = $criteria['dateFrom'] ?? null;
        $to = $criteria['dateTo'] ?? null;
        if ($from !== null || $to !== null) {
            foreach ([$from, $to] as $date) {
                if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                    throw new \InvalidArgumentException('Dates must be YYYY-MM-DD.');
                }
            }
            $dateRange = ($from ?? '2000-01-01') . ' to ' . ($to ?? '2999-12-31');
        }

        $result = $this->normaliseList($this->get('submissions', [
            'form_id' => $criteria['formId'] ?? null,
            'project_id' => $criteria['projectId'] ?? null,
            'filter_by' => $filter,
            'query' => $criteria['query'] ?? null,
            'order_by' => $order,
            'date_range' => $dateRange,
            'page' => $page,
        ]), $page);

        return new ListPage(
            array_map(fn(array $row) => $this->sanitiseSubmission($row), $result->items),
            $result->page, $result->perPage, $result->total, $result->meta,
        );
    }

    /**
     * @return array<string, mixed>
     * @throws ApiException
     */
    public function getSubmission(int $id): array
    {
        return $this->sanitiseSubmission($this->normaliseObject($this->get('submissions/' . $id)));
    }

    /**
     * Sets the read, spam and/or trash flags. Other keys are rejected, because
     * Basin silently ignores them.
     *
     * @param array{read?: bool, spam?: bool, trash?: bool} $flags
     * @return array<string, mixed> The updated submission
     * @throws ApiException
     */
    public function updateSubmission(int $id, array $flags): array
    {
        $unknown = array_diff(array_keys($flags), ['read', 'spam', 'trash']);
        if ($unknown || $flags === []) {
            throw new \InvalidArgumentException('Only read, spam and trash can be updated.');
        }
        $flags = array_map(fn($v) => (bool)$v, $flags);

        return $this->sanitiseSubmission($this->normaliseObject($this->send('PATCH', 'submissions/' . $id, [
            'json' => ['submission' => $flags],
        ])));
    }

    /**
     * Permanently deletes a submission. Callers must check the plugin's
     * `allowDeletion` setting and the user's permission first.
     *
     * @throws ApiException
     */
    public function deleteSubmission(int $id): void
    {
        $this->send('DELETE', 'submissions/' . $id);
    }

    /**
     * Streams an attachment through the authenticated proxy URL.
     *
     * @throws ApiException
     */
    public function downloadAttachment(int $id): ResponseInterface
    {
        $response = $this->request('GET', 'attachments/' . $id, [
            'stream' => true,
            'headers' => ['Accept' => '*/*'],
        ]);
        if ($response->getStatusCode() >= 300) {
            // Throws the matching typed exception.
            $this->handleResponse($response);
        }
        return $response;
    }

    /**
     * Attachment `public_url`s work for anyone who has them, with no auth, so
     * they're dropped here and downloads go through {@see downloadAttachment()}.
     *
     * @param array<string, mixed> $submission
     * @return array<string, mixed>
     */
    private function sanitiseSubmission(array $submission): array
    {
        if (isset($submission['attachments']) && is_array($submission['attachments'])) {
            foreach ($submission['attachments'] as &$attachment) {
                if (is_array($attachment)) {
                    unset($attachment['public_url']);
                }
            }
            unset($attachment);
        }
        return $submission;
    }

    /**
     * Basin returns form secrets in plain text. Remove them before anything
     * else sees the data, so they can't be cached, logged or rendered.
     *
     * @param array<string, mixed> $form
     * @return array<string, mixed>
     */
    private function sanitiseForm(array $form): array
    {
        unset($form['turnstile_secret']);
        return $form;
    }

    /**
     * @param array<string, scalar> $query
     * @throws ApiException
     */
    private function get(string $path, array $query = []): mixed
    {
        return $this->send('GET', $path, ['query' => array_filter($query, fn($v) => $v !== null && $v !== '')]);
    }

    /**
     * @param array<string, mixed> $options
     * @throws ApiException
     */
    private function send(string $method, string $path, array $options = []): mixed
    {
        return $this->handleResponse($this->request($method, $path, $options));
    }

    /**
     * Makes a request and returns the raw response, whatever its status.
     *
     * @param array<string, mixed> $options
     * @throws ApiException
     */
    private function request(string $method, string $path, array $options = []): ResponseInterface
    {
        if ($this->apiKey === '') {
            throw new AuthException('No Basin API key is configured.');
        }

        $options += [
            'timeout' => $this->timeout,
            'connect_timeout' => min($this->timeout, 5.0),
            'http_errors' => false,
        ];
        $options['headers'] = ($options['headers'] ?? []) + [
            'Authorization' => 'Token ' . $this->apiKey,
            'Accept' => 'application/json',
        ];

        try {
            return $this->http->request($method, self::BASE_URI . ltrim($path, '/'), $options);
        } catch (ConnectException $e) {
            throw new ApiUnavailableException('Could not connect to Basin.', 0, $e);
        } catch (TransferException|GuzzleException $e) {
            throw new ApiUnavailableException('The request to Basin failed.', 0, $e);
        }
    }

    /**
     * @throws ApiException
     */
    private function handleResponse(ResponseInterface $response): mixed
    {
        $status = $response->getStatusCode();
        $body = (string)$response->getBody();
        $data = $body === '' ? null : json_decode($body, true);

        if ($status >= 200 && $status < 300) {
            if ($body !== '' && json_last_error() !== JSON_ERROR_NONE) {
                throw new ApiException('Basin returned a response that wasn’t valid JSON.', $status);
            }
            return $data;
        }

        $message = $this->extractErrorMessage($data);

        throw match (true) {
            $status === 401, $status === 403 => new AuthException($message ?? 'Basin rejected the API key.', $status),
            $status === 404 => new NotFoundException($message ?? 'Not found in Basin.', $status),
            $status === 422 => new ValidationException($message ?? 'Basin rejected the request.', $status),
            $status === 429 => new RateLimitException(
                $message ?? 'Too many requests to Basin.',
                $status,
                retryAfter: $this->parseRetryAfter($response->getHeaderLine('Retry-After')),
            ),
            $status >= 500 => new ApiUnavailableException($message ?? 'Basin is unavailable.', $status),
            default => new ApiException($message ?? "Unexpected response from Basin (HTTP $status).", $status),
        };
    }

    private function extractErrorMessage(mixed $data): ?string
    {
        if (!is_array($data)) {
            return null;
        }
        foreach (['error', 'message', 'errors'] as $key) {
            if (!isset($data[$key])) {
                continue;
            }
            $value = $data[$key];
            if (is_string($value) && $value !== '') {
                return $value;
            }
            if (is_array($value)) {
                $flat = array_filter(array_map(
                    fn($v) => is_array($v) ? implode(', ', array_filter($v, 'is_string')) : (is_string($v) ? $v : null),
                    $value,
                ));
                if ($flat) {
                    return implode('; ', $flat);
                }
            }
        }
        return null;
    }

    private function parseRetryAfter(string $header): ?int
    {
        if ($header === '') {
            return null;
        }
        if (ctype_digit($header)) {
            return (int)$header;
        }
        $time = strtotime($header);
        return $time === false ? null : max(0, $time - time());
    }

    /**
     * @throws ApiException
     */
    private function normaliseList(mixed $data, int $page): ListPage
    {
        if (is_array($data) && array_is_list($data)) {
            return new ListPage(array_values(array_filter($data, 'is_array')), $page);
        }
        if (is_array($data)) {
            foreach (self::LIST_WRAPPER_KEYS as $key) {
                if (isset($data[$key]) && is_array($data[$key]) && array_is_list($data[$key])) {
                    return ListPage::fromMeta(array_values(array_filter($data[$key], 'is_array')), $data['meta'] ?? null, $page);
                }
            }
        }
        throw new ApiException('Basin returned an unexpected list format.');
    }

    /**
     * @return array<string, mixed>
     * @throws ApiException
     */
    private function normaliseObject(mixed $data): array
    {
        if (is_array($data) && !array_is_list($data)) {
            return $data;
        }
        throw new ApiException('Basin returned an unexpected response format.');
    }
}
