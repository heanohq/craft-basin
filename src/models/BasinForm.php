<?php

namespace heano\basin\models;

use Craft;
use craft\base\Model;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use DateTime;
use heano\basin\helpers\StudioEmbed;
use heano\basin\Plugin;
use heano\basin\services\FormIssues;
use Twig\Markup;

/**
 * A Basin form in the linked project (spec §6.3).
 *
 * Built from the API's form data. Secrets and notification settings are
 * deliberately not copied in.
 */
class BasinForm extends Model
{
    public const BASIN_FORM_URL = 'https://usebasin.com/app/forms/%d';
    public const BASIN_SPAM_URL = 'https://usebasin.com/app/forms/%d/spam_settings/edit';
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

    /** A hosted ID seen in this form's submissions that hasn't been saved. */
    public ?string $detectedHostedId = null;

    /** This form's own reCAPTCHA version, or null for the site default. */
    public ?string $recaptchaVersion = null;

    /** A template called `render()` for this form without saved embed code. */
    public bool $noEmbedReported = false;

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

    /**
     * The form's timezone as a PHP identifier, for showing submission dates.
     * Basin's value may not be one PHP knows; those fall back to UTC.
     */
    public function getPhpTimezone(): string
    {
        return in_array($this->timezone, \DateTimeZone::listIdentifiers(), true) || $this->timezone === 'UTC'
            ? $this->timezone
            : 'UTC';
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

    /** A Studio form whose embed code hasn't been saved yet. */
    public function getNeedsEmbedCode(): bool
    {
        return $this->hostedFormId === null && ($this->detectedHostedId !== null || $this->noEmbedReported);
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

    /**
     * The form's spam settings in Basin.
     */
    public function getBasinSpamUrl(): string
    {
        return sprintf(self::BASIN_SPAM_URL, $this->id);
    }

    /**
     * The form's submissions in the Control Panel.
     */
    public function getSubmissionsCpUrl(?string $tab = null): string
    {
        return UrlHelper::cpUrl('basin/forms/' . $this->uuid . '/submissions', $tab && $tab !== 'inbox' ? ['tab' => $tab] : null);
    }

    /**
     * The form's Form Studio page in Basin's dashboard. Studio's own numeric
     * IDs aren't in the API, so this links to the form's list of Studio forms.
     */
    public function getStudioDashboardUrl(): string
    {
        return sprintf(self::BASIN_FORM_URL, $this->id) . '/hosted_forms';
    }

    public function getCpUrl(): string
    {
        return UrlHelper::cpUrl('basin/forms/' . $this->uuid);
    }

    /**
     * The form's public spam-protection settings (spec §10.4).
     *
     * @return array{captcha: ?string, siteKey: ?string, scriptUrl: ?string, recaptchaVersion: ?string, honeypotField: string}
     */
    public function getSpamProtection(): array
    {
        return Plugin::getInstance()->getSpamProtection()->config($this);
    }

    /** A hidden honeypot input using the form's honeypot field name. */
    public function honeypot(array $attributes = []): Markup
    {
        return Template::raw(Plugin::getInstance()->getSpamProtection()->honeypotHtml($this, $attributes));
    }

    /** Where the form's CAPTCHA goes, if Basin requires one. */
    public function captcha(array $attributes = []): Markup
    {
        return Template::raw(Plugin::getInstance()->getSpamProtection()->captchaHtml($this, $attributes));
    }

    /** The honeypot and CAPTCHA together. */
    public function spamProtection(): Markup
    {
        $service = Plugin::getInstance()->getSpamProtection();
        return Template::raw($service->honeypotHtml($this) . $service->captchaHtml($this));
    }

    /**
     * Outputs the form's Form Studio embed (spec §10.3). Always safe to
     * output: without saved embed code, visitors get only an HTML comment,
     * while devMode and previews show a notice to whoever can fix it.
     *
     * @param array{hiddenFields?: array<string, scalar|null>} $options
     *   `hiddenFields`: values added to each submission from this page, sent
     *   to the embedded form with Basin's `addHiddenFields` message.
     */
    public function render(array $options = []): Markup
    {
        $url = $this->getStudioScriptUrl();
        if ($url !== null) {
            $html = Html::tag('script', '', ['src' => $url, 'async' => true]);
            $fields = StudioEmbed::hiddenFields($options['hiddenFields'] ?? []);
            if ($fields) {
                $html .= Html::script(StudioEmbed::hiddenFieldsJs($this->uuid, (string)$this->hostedFormId, $fields));
            }
            return Template::raw($html);
        }

        Plugin::getInstance()->getFormIssues()->record($this->uuid, FormIssues::REASON_NO_EMBED, FormIssues::SOURCE_TWIG);
        Craft::warning("form.render() was called for Basin form $this->uuid, which has no Form Studio embed code saved.", 'basin');

        $message = Craft::t('basin', '“{name}” can’t be shown because its Form Studio embed code hasn’t been added in the Control Panel.', ['name' => $this->name]);
        return self::notice($message, Craft::t('basin', 'Add it in the Control Panel'), $this->getCpUrl());
    }

    /**
     * Why a form can't be shown: an HTML comment for visitors, plus a visible
     * notice in devMode and previews, linking to the CP for people who can
     * fix it.
     */
    public static function notice(string $message, string $linkText, string $url): Markup
    {
        $comment = '<!-- Basin: ' . str_replace(['--', '>'], ['–', '›'], $message) . ' -->';

        $request = Craft::$app->getRequest();
        $showNotice = Craft::$app->getConfig()->getGeneral()->devMode
            || (!$request->getIsConsoleRequest() && $request->getIsPreview());
        if (!$showNotice) {
            return Template::raw($comment);
        }

        $link = '';
        if (Craft::$app->getUser()->checkPermission('accessPlugin-basin')) {
            $link = ' ' . Html::a($linkText, $url, ['target' => '_blank', 'rel' => 'noopener']);
        }
        $notice = Html::tag('div', Html::encode($message) . $link, [
            'role' => 'note',
            'data-basin-notice' => true,
            'style' => 'padding:1em;border:2px dashed #d97706;background:#fffbeb;color:#78350f;font:14px/1.5 system-ui,sans-serif;',
        ]);
        return Template::raw($comment . $notice);
    }
}
