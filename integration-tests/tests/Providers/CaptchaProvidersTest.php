<?php

use heano\basin\tests\craft\Docs;
use heano\basin\tests\craft\FakeBasin;

/*
 * The real CAPTCHA providers, on a page whose Content Security Policy is
 * exactly what the docs say to add (forms/spam-protection.md). Fails if a
 * provider moves its script, needs a domain the docs don't list, or stops
 * rendering, and if the plugin adds anything a strict policy blocks.
 *
 * Needs the network. Uses the providers' public test keys, which always pass,
 * except reCAPTCHA v3, which has none, so it uses Basin's key.
 */

const CSP_NONCE = 'docs-test-nonce';

/**
 * What the docs' CSP table says to add for a provider.
 *
 * @return array<string, list<string>> Sources, by directive
 */
function docsCspFor(string $provider): array
{
    $rows = Docs::table(Docs::section(Docs::page('forms/spam-protection.md'), 'Content Security Policy'));
    $sources = [];
    foreach ($rows as $row) {
        if (Docs::plain($row['Provider']) === $provider) {
            $directive = Docs::plain($row['Directive']);
            $sources[$directive] = [...$sources[$directive] ?? [], ...Docs::codeSpans($row['Sources'])];
        }
    }
    expect($sources)->not->toBeEmpty("The docs' CSP table has no rows for $provider");
    return $sources;
}

/**
 * A strict policy plus the docs' sources: nothing inline but scripts with the
 * nonce (or, without one, inline scripts, as the docs say).
 */
function strictPolicy(array $docsSources, bool $withNonce): string
{
    $policy = [
        'default-src' => ["'self'"],
        'script-src' => ["'self'", $withNonce ? "'nonce-" . CSP_NONCE . "'" : "'unsafe-inline'"],
        'style-src' => ["'self'"],
    ];
    foreach ($docsSources as $directive => $sources) {
        $policy[$directive] = [...$policy[$directive] ?? ["'self'"], ...$sources];
    }
    return implode('; ', array_map(fn($d, $s) => "$d " . implode(' ', $s), array_keys($policy), $policy));
}

/** Waits up to 20 seconds for a JavaScript condition on the page. */
function waitFor($page, string $condition): bool
{
    return $page->script(<<<JS
        async () => {
            for (let i = 0; i < 40; i++) {
                if ($condition) return true;
                await new Promise((resolve) => setTimeout(resolve, 500));
            }
            return false;
        }
        JS);
}

it('renders the CAPTCHA under the policy the docs give', function(string $provider, array $fields, array $settings, string $rendered, bool $withNonce) {
    settings($settings);
    $form = $this->basin->addForm('CAPTCHA form', $fields);
    $policy = strictPolicy(docsCspFor($provider), $withNonce);

    $page = visit("/basin-test/csp?uuid={$form['uuid']}&csp=" . rawurlencode($policy));

    expect(waitFor($page, $rendered))->toBeTrue("$provider didn't render. Policy: $policy");
    $page->wait(2);
    expect($page->script('() => window.violations'))->toBe([], "Blocked by the policy the docs give: $policy");
})->with([
    'hCaptcha' => ['hCaptcha', FakeBasin::hcaptcha(), ['hcaptchaSiteKey' => '10000000-ffff-ffff-ffff-000000000001'],
        '!!document.querySelector(\'#contact iframe[src*="hcaptcha.com"]\')'],
    'Turnstile' => ['Turnstile', FakeBasin::turnstile(), [],
        '!!document.querySelector(\'#contact [name="cf-turnstile-response"]\')?.value'],
    'reCAPTCHA v2' => ['reCAPTCHA', FakeBasin::recaptcha(), ['recaptchaVersion' => 'v2', 'recaptchaV2SiteKey' => '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI'],
        '!!document.querySelector(\'#contact iframe[src*="recaptcha/api2/anchor"]\')'],
    'reCAPTCHA v3' => ['reCAPTCHA', FakeBasin::recaptcha(), ['recaptchaVersion' => 'v3'],
        '!!document.querySelector(\'iframe[src*="recaptcha/api2/anchor"]\') && typeof grecaptcha.execute === "function"'],
])->with([
    'nonce' => true,
    'no nonce' => false,
]);

it('moves the honeypot off screen under a strict policy, without JavaScript', function(string $styleSrc) {
    // A honeypot-only form, so the page gets no script: just the stylesheet.
    $form = $this->basin->addForm('Honeypot form', FakeBasin::honeypot());
    $policy = str_replace("style-src 'self'", "style-src $styleSrc", strictPolicy([], true));

    $page = visit("/basin-test/csp?uuid={$form['uuid']}&csp=" . rawurlencode($policy));

    expect($page->script('() => document.querySelectorAll("script[data-basin-protect-script]").length'))->toBe(0)
        ->and($page->script('() => document.querySelector(\'#contact [data-basin-extra]\').getBoundingClientRect().right'))->toBeLessThanOrEqual(0)
        ->and($page->script('() => window.violations'))->toBe([]);
})->with([
    'styles from the site' => "'self'",
    'styles with a nonce only' => "'nonce-" . CSP_NONCE . "'",
]);
