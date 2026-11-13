<?php

namespace heano\basin\services;

use Craft;
use craft\base\Component;
use craft\helpers\Html;
use heano\basin\api\exceptions\ApiException;
use heano\basin\helpers\PageScanner;
use heano\basin\models\BasinForm;
use heano\basin\Plugin;

/**
 * Adds the spam protection each Basin form requires (spec §10.4).
 *
 * Honeypots are added to the HTML on the server. CAPTCHAs need JavaScript,
 * so the page gets a small script plus each form's public settings, and the
 * script renders the widgets in the browser.
 */
class SpamProtection extends Component
{
    /** Basin's default honeypot field name, used when a form doesn't set one. */
    public const DEFAULT_HONEYPOT = '_gotcha';

    public const RECAPTCHA_SCRIPT = 'https://www.google.com/recaptcha/api.js';
    public const HCAPTCHA_SCRIPT = 'https://js.hcaptcha.com/1/api.js';
    public const TURNSTILE_SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js';


    /**
     * A form's public spam-protection settings, as used by the script, the
     * Twig helpers and GraphQL.
     *
     * @return array{captcha: ?string, siteKey: ?string, scriptUrl: ?string, recaptchaVersion: ?string, honeypotField: string}
     */
    public function config(BasinForm $form): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $captcha = $form->getCaptcha();
        $version = $captcha === 'recaptcha' ? ($form->recaptchaVersion ?? $settings->recaptchaVersion) : null;

        $siteKey = match ($captcha) {
            'recaptcha' => $version === 'v2' ? $settings->recaptchaV2SiteKey : $settings->recaptchaV3SiteKey,
            'hcaptcha' => $settings->hcaptchaSiteKey,
            'turnstile' => $form->turnstileSiteKey,
            default => null,
        };

        $scriptUrl = match ($captcha) {
            'recaptcha' => $version === 'v3' ? self::RECAPTCHA_SCRIPT . '?render=' . rawurlencode((string)$siteKey) : self::RECAPTCHA_SCRIPT,
            'hcaptcha' => self::HCAPTCHA_SCRIPT,
            'turnstile' => self::TURNSTILE_SCRIPT,
            default => null,
        };

        return [
            'captcha' => $captcha,
            'siteKey' => $siteKey,
            'scriptUrl' => $scriptUrl,
            'recaptchaVersion' => $version,
            'honeypotField' => $form->honeypotField ?? self::DEFAULT_HONEYPOT,
        ];
    }

    /**
     * A hidden honeypot input with the form's field name.
     */
    public function honeypotHtml(BasinForm $form, array $attributes = []): string
    {
        return Html::tag('input', '', array_merge([
            'type' => 'text',
            'name' => $this->config($form)['honeypotField'],
            'value' => '',
            'tabindex' => '-1',
            'autocomplete' => 'off',
            'aria-hidden' => 'true',
            'style' => 'display:none !important',
            'data-basin-honeypot' => true,
        ], $attributes));
    }

    /**
     * A placeholder the script renders the form's CAPTCHA into. Empty if the
     * form doesn't need one.
     */
    public function captchaHtml(BasinForm $form, array $attributes = []): string
    {
        if ($form->getCaptcha() === null) {
            return '';
        }
        // `version` picks reCAPTCHA v2 or v3 for this placement.
        $version = $attributes['version'] ?? null;
        unset($attributes['version']);
        if ($form->getCaptcha() === 'recaptcha' && in_array($version, ['v2', 'v3'], true)) {
            $attributes['data-basin-recaptcha'] = $version;
        }
        return Html::tag('div', '', array_merge($attributes, [
            'data-basin-captcha' => $form->uuid,
        ]));
    }

    /**
     * Adds honeypots and the CAPTCHA script to a rendered HTML page.
     *
     * With `autoProtect` off, only placeholders from the Twig helpers are
     * handled. Forms not in the linked project are recorded as missing.
     */
    public function processHtml(string $html): string
    {
        if (!PageScanner::mightHaveForms($html)) {
            return $html;
        }

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $formsService = $plugin->getForms();
        if (!$formsService->isConfigured()) {
            return $html;
        }

        $tags = PageScanner::forms($html);
        $placeholders = PageScanner::placeholders($html);
        $uuids = array_unique(array_merge(array_column($tags, 'uuid'), $placeholders));
        if (!$uuids) {
            return $html;
        }
        // Forms the automatic layer handles: not opted out with data-basin-protect="false".
        $protected = array_column(array_filter($tags, fn($t) => !$t['optedOut']), 'uuid');

        try {
            $forms = $formsService->getForms();
        } catch (ApiException $e) {
            Craft::warning('Couldn’t add Basin spam protection to this page: ' . $e->getMessage(), 'basin');
            return $html;
        }

        $configs = [];
        foreach ($uuids as $uuid) {
            $form = $forms[$uuid] ?? null;
            if ($form === null) {
                if (!$formsService->isStale) {
                    $plugin->getFormIssues()->record($uuid, FormIssues::REASON_MISSING, FormIssues::SOURCE_HTML);
                }
                continue;
            }
            $config = $this->config($form);
            $wanted = in_array($uuid, $placeholders, true) || ($settings->autoProtect && in_array($uuid, $protected, true));
            if ($config['captcha'] !== null && $wanted) {
                $configs[$uuid] = $config;
            }
        }

        if ($settings->autoProtect) {
            $html = PageScanner::insertHoneypots($html, $tags, function(string $uuid) use ($forms) {
                $form = $forms[$uuid] ?? null;
                return $form ? [$this->config($form)['honeypotField'], $this->honeypotHtml($form)] : null;
            });
        }

        if (!$configs || str_contains($html, 'data-basin-protect-script')) {
            return $html;
        }

        $nonce = PageScanner::nonce($html);
        $json = json_encode([
            'auto' => $settings->autoProtect,
            'forms' => $configs,
            // Both reCAPTCHA keys, so templates can pick a version per form tag.
            'recaptchaKeys' => ['v2' => $settings->recaptchaV2SiteKey, 'v3' => $settings->recaptchaV3SiteKey],
            'nonce' => $nonce,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES);
        $script = Html::tag('script', "window.BasinProtect=$json;\n" . self::script(), array_filter([
            'nonce' => $nonce,
            'data-basin-protect-script' => true,
        ]));

        return PageScanner::insertBeforeBodyEnd($html, $script);
    }

    private static function script(): string
    {
        static $js = null;
        return $js ??= (string)file_get_contents(dirname(__DIR__) . '/web/protect.js');
    }
}
