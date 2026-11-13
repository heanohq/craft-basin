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
     * Keys Basin may wrap list responses in. The response shape isn't
     * documented (spec §18 #1), so lists are normalised defensively.
     */
    private const LIST_WRAPPER_KEYS = ['data', 'items', 'results', 'projects', 'forms', 'form_views', 'submissions'];

    public function __construct(
        private readonly ClientInterface $http,
        private readonly string $apiKey,
        private readonly float $timeout = 10.0,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws ApiException
     */
    public function listProjects(int $page = 1): array
    {
        return $this->normaliseList($this->get('projects', ['page' => $page]));
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
            $response = $this->http->request($method, self::BASE_URI . ltrim($path, '/'), $options);
        } catch (ConnectException $e) {
            throw new ApiUnavailableException('Could not connect to Basin.', 0, $e);
        } catch (TransferException|GuzzleException $e) {
            throw new ApiUnavailableException('The request to Basin failed.', 0, $e);
        }

        return $this->handleResponse($response);
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
     * @return array<int, array<string, mixed>>
     * @throws ApiException
     */
    private function normaliseList(mixed $data): array
    {
        if (is_array($data) && array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        if (is_array($data)) {
            foreach (self::LIST_WRAPPER_KEYS as $key) {
                if (isset($data[$key]) && is_array($data[$key]) && array_is_list($data[$key])) {
                    return array_values(array_filter($data[$key], 'is_array'));
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
