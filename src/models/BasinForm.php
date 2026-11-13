<?php

namespace heano\basin\models;

use craft\base\Model;
use craft\helpers\DateTimeHelper;
use craft\helpers\UrlHelper;
use DateTime;
use heano\basin\helpers\StudioEmbed;

/**
 * A Basin form in the linked project (spec §6.3).
 *
 * Built from the API's form data. Secrets and notification settings are
 * deliberately not copied in.
 */
class BasinForm extends Model
{
    public const BASIN_FORM_URL = 'https://usebasin.com/app/forms/%d';
    public const ENDPOINT_URL = 'https://usebasin.com/f/%s';

    public int $id;
    public string $uuid;
    public string $name = '';
    public ?int $projectId = null;
    public ?string $projectName = null;
    public string $timezone = 'UTC';
    public ?string $redirectUrl = null;
    public bool $useAjax = false;
    public ?string $honeypotField = null;
    public bool $recaptcha = false;
    public bool $hcaptcha = false;
    public bool $turnstile = false;
    public ?string $turnstileSiteKey = null;
    public ?int $retentionDays = null;
    public int $inboxCount = 0;
    public int $spamCount = 0;
    public int $trashCount = 0;
    public ?DateTime $dateUpdated = null;

    /** Form Studio hosted ID saved in plugin settings (spec §10.3). */
    public ?string $hostedFormId = null;

    /**
     * @param array<string, mixed> $data A form from Basin's API.
     */
    public static function fromApi(array $data, ?string $hostedFormId = null): self
    {
        $str = fn(string $key) => isset($data[$key]) && is_scalar($data[$key]) && (string)$data[$key] !== '' ? (string)$data[$key] : null;

        return new self([
            'id' => (int)$data['id'],
            'uuid' => (string)$data['uuid'],
            'name' => $str('name') ?? (string)$data['uuid'],
            'projectId' => isset($data['project_id']) ? (int)$data['project_id'] : null,
            'projectName' => $str('project_name'),
            'timezone' => $str('timezone') ?? 'UTC',
            'redirectUrl' => $str('redirect_url'),
            'useAjax' => (bool)($data['use_ajax'] ?? false),
            'honeypotField' => $str('honeypot_field'),
            'recaptcha' => (bool)($data['force_recaptcha'] ?? false),
            'hcaptcha' => (bool)($data['force_hcaptcha'] ?? false),
            'turnstile' => (bool)($data['force_turnstile'] ?? false),
            'turnstileSiteKey' => $str('turnstile_site_key'),
            'retentionDays' => isset($data['retention_days']) ? (int)$data['retention_days'] : null,
            'inboxCount' => (int)($data['inbox_count'] ?? 0),
            'spamCount' => (int)($data['spam_count'] ?? 0),
            'trashCount' => (int)($data['trash_count'] ?? 0),
            'dateUpdated' => DateTimeHelper::toDateTime($data['updated_at'] ?? null) ?: null,
            'hostedFormId' => $hostedFormId,
        ]);
    }

    /** The URL forms post to. */
    public function getAction(): string
    {
        return sprintf(self::ENDPOINT_URL, rawurlencode($this->uuid));
    }

    /**
     * The CAPTCHA Basin requires for this form, if any.
     *
     * @return 'recaptcha'|'hcaptcha'|'turnstile'|null
     */
    public function getCaptcha(): ?string
    {
        return match (true) {
            $this->recaptcha => 'recaptcha',
            $this->hcaptcha => 'hcaptcha',
            $this->turnstile => 'turnstile',
            default => null,
        };
    }

    /**
     * A Turnstile requirement without a site key means every submission fails.
     */
    public function getHasTurnstileProblem(): bool
    {
        return $this->turnstile && $this->turnstileSiteKey === null;
    }

    /** Whether `render()` can output this form (its Studio embed code is saved). */
    public function getIsStudioForm(): bool
    {
        return $this->hostedFormId !== null;
    }

    public function getStudioScriptUrl(): ?string
    {
        return $this->hostedFormId !== null ? StudioEmbed::scriptUrl($this->uuid, $this->hostedFormId) : null;
    }

    public function getBasinUrl(): string
    {
        return sprintf(self::BASIN_FORM_URL, $this->id);
    }

    public function getCpUrl(): string
    {
        return UrlHelper::cpUrl('basin/forms/' . $this->uuid);
    }
}
