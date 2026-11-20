<?php

namespace heano\basin\models;

use Craft;
use craft\base\Model;
use craft\helpers\App;
use heano\basin\helpers\FormEditor;

/**
 * Basin plugin settings (stored in project config; spec §5).
 *
 * `apiKey` must be an environment variable reference (e.g. `$BASIN_API_KEY`)
 * so the key itself never lands in project config YAML.
 */
class Settings extends Model
{
    /** Env var reference to a Basin Account API key, e.g. `$BASIN_API_KEY`. */
    public string $apiKey = '';

    /** The Basin project linked to this site. Numeric ID or an env var reference. */
    public string $projectId = '';

    /** Seconds to cache forms and form details. */
    public int $cacheDuration = 300;

    /** Whether submissions can be permanently deleted from the CP. */
    public bool $allowDeletion = false;

    /** Whether to record references to forms that aren't in the linked project. */
    public bool $trackMissingForms = true;

    /**
     * Whether to add the CAPTCHA and honeypot each form requires to any Basin
     * form on a front-end page (spec §10.4).
     */
    public bool $autoProtect = true;

    /**
     * Which reCAPTCHA to render for forms that require it. Basin's setting
     * doesn't say, and accepts either with its own keys.
     *
     * @var 'v3'|'v2'
     */
    public string $recaptchaVersion = 'v3';

    /** Basin's own CAPTCHA site keys. Only change these if Basin rotates them. */
    public string $recaptchaV3SiteKey = '6Les66kUAAAAANyLrgkl7iuN4JUpNlB5upaMovI4';
    public string $recaptchaV2SiteKey = '6Lew3SMUAAAAAJ82QoS7gqOTkRI_dhYrFy1f7Sqy';
    public string $hcaptchaSiteKey = '7fe715a1-151f-4c63-b497-bd971974df05';

    /** Whether to show the unread count on the CP nav item (spec §15). */
    public bool $showUnreadBadge = true;

    /**
     * Notification recipients filled in for forms created from the CP
     * (spec §6.7). Comma-separated addresses, or an env var reference.
     * Blank = Basin's default, the Basin account owner.
     */
    public string $defaultNotificationEmail = '';

    /** Custom label for the main CP nav item. Blank = "Basin". */
    public string $navLabel = '';


    protected function defineRules(): array
    {
        return [
            [['apiKey', 'projectId', 'navLabel', 'defaultNotificationEmail'], 'trim'],
            [['apiKey', 'projectId', 'navLabel', 'defaultNotificationEmail'], 'string'],
            ['defaultNotificationEmail', 'validateEmails'],
            ['apiKey', 'validateEnvReference'],
            ['projectId', 'validateProjectId'],
            ['navLabel', 'string', 'max' => 60],
            ['cacheDuration', 'integer', 'min' => 0],
            [['allowDeletion', 'trackMissingForms', 'autoProtect', 'showUnreadBadge'], 'boolean'],
            ['recaptchaVersion', 'in', 'range' => ['v3', 'v2']],
            [['recaptchaV3SiteKey', 'recaptchaV2SiteKey', 'hcaptchaSiteKey'], 'match', 'pattern' => '/^[A-Za-z0-9_\-]{10,100}$/'],
        ];
    }

    /**
     * The API key must be stored as an env var reference, never a literal.
     */
    public function validateEnvReference(string $attribute): void
    {
        $value = $this->$attribute;
        if ($value === '') {
            return;
        }
        if (!preg_match('/^\$[A-Z_][A-Z0-9_]*$/i', $value)) {
            $this->addError($attribute, Craft::t('basin', 'Enter an environment variable (e.g. {example}), not the key itself, so it isn’t saved in project config.', [
                'example' => '$BASIN_API_KEY',
            ]));
            return;
        }
        if (App::env(substr($value, 1)) === null) {
            $this->addError($attribute, Craft::t('basin', 'The environment variable {name} isn’t set.', ['name' => $value]));
        }
    }

    public function validateProjectId(string $attribute): void
    {
        $value = $this->$attribute;
        if ($value === '' || str_starts_with($value, '$')) {
            return;
        }
        if (!ctype_digit($value)) {
            $this->addError($attribute, Craft::t('basin', 'Choose a project, or enter an environment variable.'));
        }
    }

    /**
     * Each address must be valid, including when it comes from an env var.
     */
    public function validateEmails(string $attribute): void
    {
        $value = $this->$attribute;
        if ($value === '') {
            return;
        }
        if (str_starts_with($value, '$') && App::env(substr($value, 1)) === null) {
            $this->addError($attribute, Craft::t('basin', 'The environment variable {name} isn’t set.', ['name' => $value]));
            return;
        }
        $invalid = FormEditor::firstInvalidEmail(FormEditor::splitEmails((string)App::parseEnv($value)));
        if ($invalid !== null) {
            $this->addError($attribute, Craft::t('basin', '“{email}” isn’t a valid email address.', ['email' => $invalid]));
        }
    }

    /**
     * The default notification recipients, comma-separated, or ''.
     */
    public function getDefaultNotificationEmail(): string
    {
        return implode(', ', FormEditor::splitEmails((string)App::parseEnv($this->defaultNotificationEmail)));
    }

    public function getApiKey(): string
    {
        return (string)App::parseEnv($this->apiKey);
    }

    public function getProjectId(): ?int
    {
        $value = (string)App::parseEnv($this->projectId);
        return ctype_digit($value) ? (int)$value : null;
    }

    public function getNavLabel(): string
    {
        return $this->navLabel !== ''
            ? Craft::t('site', $this->navLabel)
            : Craft::t('basin', 'Basin');
    }
}
