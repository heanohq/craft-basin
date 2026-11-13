<?php

namespace heano\basin\models;

use craft\base\Model;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeZone;

/**
 * A Basin submission (spec §7). Built from API data; never stored in Craft.
 */
class Submission extends Model
{
    public const BASIN_URL = 'https://usebasin.com/app/submissions/%d?form_id=%d';

    public int $id;
    public int $formId;
    public ?string $formUuid = null;
    public ?string $formName = null;
    public ?string $email = null;

    /** @var array<string, mixed> Field name => value, as submitted. */
    public array $payload = [];

    public bool $read = false;
    public bool $spam = false;
    public bool $trash = false;
    public ?string $spamReason = null;
    public ?DateTime $dateCreated = null;
    public ?DateTime $webhookSentAt = null;
    public ?string $ip = null;
    public ?string $userAgent = null;
    public ?string $referrer = null;
    public ?string $sourceUrl = null;
    public ?string $country = null;
    public ?string $region = null;
    public ?string $city = null;

    /** @var array<int, array{id: int, name: string, size: ?int, contentType: ?string}> */
    public array $attachments = [];

    /**
     * @param array<string, mixed> $data A submission from Basin's API.
     */
    public static function fromApi(array $data): self
    {
        $str = fn(mixed $v) => is_scalar($v) && (string)$v !== '' ? (string)$v : null;
        $date = function(mixed $v): ?DateTime {
            if (!is_string($v) || $v === '') {
                return null;
            }
            try {
                return (new DateTime($v))->setTimezone(new DateTimeZone('UTC'));
            } catch (\Exception) {
                return null;
            }
        };
        $geo = fn(mixed $v) => ($s = $str($v)) !== null && $s !== 'Unknown' ? $s : null;

        $attachments = [];
        foreach ((array)($data['attachments'] ?? []) as $a) {
            if (is_array($a) && isset($a['id'])) {
                $attachments[] = [
                    'id' => (int)$a['id'],
                    'name' => $str($a['name'] ?? null) ?? 'attachment-' . $a['id'],
                    'size' => isset($a['file_size']) ? (int)$a['file_size'] : null,
                    'contentType' => $str($a['content_type'] ?? null),
                ];
            }
        }

        return new self([
            'id' => (int)$data['id'],
            'formId' => (int)($data['form_id'] ?? 0),
            'formUuid' => $str($data['form']['uuid'] ?? null),
            'formName' => $str($data['form']['name'] ?? null),
            'email' => $str($data['email'] ?? null),
            'payload' => is_array($data['payload_params'] ?? null) ? $data['payload_params'] : [],
            'read' => (bool)($data['read'] ?? false),
            'spam' => (bool)($data['spam'] ?? false),
            'trash' => (bool)($data['trash'] ?? false),
            'spamReason' => $str($data['spam_reason'] ?? null),
            'dateCreated' => $date($data['created_at'] ?? null),
            'webhookSentAt' => $date($data['webhook_sent_at'] ?? null),
            'ip' => $str($data['ip'] ?? null),
            'userAgent' => $str($data['user_agent'] ?? null),
            'referrer' => $str($data['referrer'] ?? null),
            'sourceUrl' => $str($data['source_url'] ?? null),
            'country' => $geo($data['geocoded_country'] ?? null),
            'region' => $geo($data['geocoded_region'] ?? null),
            'city' => $geo($data['geocoded_city'] ?? null),
            'attachments' => $attachments,
        ]);
    }

    /**
     * The first few payload fields, for list previews. Skips the field that
     * holds the email address, since that has its own column.
     *
     * @return array<string, string>
     */
    public function getPreview(int $limit = 3): array
    {
        $preview = [];
        foreach ($this->payload as $name => $value) {
            if (count($preview) >= $limit) {
                break;
            }
            $text = self::stringify($value);
            if ($text === '' || ($this->email !== null && strcasecmp($text, $this->email) === 0)) {
                continue;
            }
            $preview[(string)$name] = mb_strimwidth(preg_replace('/\s+/', ' ', $text), 0, 80, '…');
        }
        return $preview;
    }

    /** Human-readable text for a payload value (nested values become JSON). */
    public static function stringify(mixed $value): string
    {
        if (is_array($value)) {
            return array_is_list($value) && !array_filter($value, 'is_array')
                ? implode(', ', array_map('strval', $value))
                : (string)json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return $value === null ? '' : (string)$value;
    }

    public function getLocation(): ?string
    {
        $parts = array_filter([$this->city, $this->region, $this->country]);
        return $parts ? implode(', ', array_unique($parts)) : null;
    }

    public function getBasinUrl(): string
    {
        return sprintf(self::BASIN_URL, $this->id, $this->formId);
    }

    public function getCpUrl(array $params = []): string
    {
        return UrlHelper::cpUrl("basin/forms/$this->formUuid/submissions/$this->id", $params);
    }

    public function getStatus(): string
    {
        return $this->trash ? 'trash' : ($this->spam ? 'spam' : 'inbox');
    }
}
